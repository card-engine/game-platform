<?php

namespace app\logic\game;

use app\model\DailyStat;
use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantBill;
use app\model\MerchantCredit;
use app\model\HourlyStat;
use app\model\MonthlyStat;
use app\model\User;
use app\service\game\EnterpriseScope;
use app\service\game\ConfigService;
use app\service\game\report\PlatformStatsRebuildService;
use app\service\game\trade\MonthlyTableService;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use support\Db;

class OperationsLogic extends BaseLogic
{
    public function overview(int $adminId, array $selectedMerchantIds = []): array
    {
        $merchantIds = $this->merchantIds($adminId, $selectedMerchantIds);
        $dateMerchants = [];
        $isSuperAdmin = EnterpriseScope::isGameSuperAdmin($adminId);
        $dateColumn = $isSuperAdmin ? 'platform_date' : 'business_date';
        if ($isSuperAdmin) {
            $date = (new \DateTimeImmutable('now', new \DateTimeZone((new ConfigService())->get('platform_timezone', 'UTC'))))->format('Y-m-d');
            if ($merchantIds) $dateMerchants[$date] = $merchantIds;
        } else {
            foreach (Merchant::whereIn('id', $merchantIds)->get(['id', 'timezone']) as $merchant) {
                $date = (new \DateTimeImmutable('now', new \DateTimeZone($merchant->timezone)))->format('Y-m-d');
                $dateMerchants[$date][] = (int) $merchant->id;
            }
        }
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        $tables = [];
        foreach ([-1, 0, 1] as $offset) $tables[] = (new MonthlyTableService())->table('bets', $month->modify("{$offset} month")->format('ym'));
        $queries = [];
        $bindings = [];
        foreach ($dateMerchants as $date => $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($tables as $table) {
                $queries[] = "SELECT currency_code, user_id, debit_count, credit_count, rollback_count, bet_amount, win_amount, bet_rollback_amount, win_rollback_amount, ggr_amount, billable_ggr_amount, merchant_fee, upstream_fee, status FROM `{$table}` WHERE delete_time IS NULL AND {$dateColumn} = ? AND merchant_id IN ({$placeholders})";
                array_push($bindings, $date, ...$ids);
            }
        }
        $today = collect();
        $todayUserCount = 0;
        if ($queries) {
            $union = implode(' UNION ALL ', $queries);
            $today = collect(Db::select("SELECT currency_code, COUNT(DISTINCT user_id) user_count, COUNT(*) bet_count, SUM(debit_count + credit_count + rollback_count) bill_count, COALESCE(SUM(bet_amount), 0) bet_amount, COALESCE(SUM(win_amount), 0) win_amount, COALESCE(SUM(bet_rollback_amount + win_rollback_amount), 0) rollback_amount, COALESCE(SUM(ggr_amount), 0) ggr_amount, COALESCE(SUM(billable_ggr_amount), 0) billable_ggr_amount, COALESCE(SUM(merchant_fee), 0) merchant_fee, COALESCE(SUM(upstream_fee), 0) upstream_fee, COALESCE(SUM(merchant_fee - upstream_fee), 0) platform_profit, SUM(status = 4) exception_count FROM ({$union}) bets GROUP BY currency_code ORDER BY currency_code", $bindings));
            $todayUserCount = (int) (array_values((array) Db::selectOne("SELECT COUNT(DISTINCT user_id) user_count FROM ({$union}) bets", $bindings))[0] ?? 0);
        }
        $unknown = 0;
        foreach ([gmdate('ym'), gmdate('ym', strtotime('-1 month'))] as $month) {
            $table = (new MonthlyTableService())->table('bills', $month);
            $unknown += Db::table($table)->whereNull('delete_time')->whereIn('merchant_id', $merchantIds)->whereIn('status', [1, 4])->count();
        }
        $businessDates = array_keys($dateMerchants);
        $trendMerchantId = $isSuperAdmin
            ? (count($selectedMerchantIds) === 1 && count($merchantIds) === 1 ? $merchantIds[0] : 0)
            : (count($merchantIds) === 1 ? $merchantIds[0] : -1);
        $games = Game::query();
        if (!$isSuperAdmin) {
            $games->whereIn('id', Db::table('mg_merchant_games')->whereIn('merchant_id', $merchantIds)
                ->where(['status' => 1, 'merchant_status' => 1])->whereNull('delete_time')->select('game_id'));
        }
        return [
            'business_date' => count($businessDates) === 1 ? $businessDates[0] : null,
            'business_date_label' => count($businessDates) === 1 ? $businessDates[0] : '各商户当地今日',
            'business_dates' => $businessDates,
            'enterprise_count' => Merchant::whereIn('id', $merchantIds)->distinct()->count('enterprise_id'),
            'active_enterprise_count' => Enterprise::whereIn('id', Merchant::whereIn('id', $merchantIds)->pluck('enterprise_id'))->where('status', 1)->count(),
            'merchant_count' => count($merchantIds),
            'active_merchant_count' => Merchant::whereIn('id', $merchantIds)->where('status', 1)->count(),
            'user_count' => User::whereIn('merchant_id', $merchantIds)->count(),
            'today_user_count' => $todayUserCount,
            'platform_count' => (clone $games)->distinct()->count('platform_code'),
            'game_count' => (clone $games)->where('status', 1)->count(),
            'total_game_count' => (clone $games)->count(),
            'unknown_bill_count' => $unknown,
            'today_exception_count' => $today->sum('exception_count'),
            'today' => $today,
            'credits' => MerchantCredit::with('merchant:id,mch_id,name')->whereIn('merchant_id', $merchantIds)->where('status', 1)->orderBy('available_amount')->limit(12)->get(),
            'platforms' => (clone $games)->selectRaw('platform_code, COUNT(*) game_count, SUM(status = 1) enabled_count, MAX(last_sync_time) last_sync_time')->groupBy('platform_code')->get(),
            'hourly' => HourlyStat::where('merchant_id', $trendMerchantId)->latest('stat_date')->orderBy('stat_hour')->get(),
            'monthly' => MonthlyStat::where('merchant_id', $trendMerchantId)->orderByDesc('stat_month')->limit(12)->get()->reverse()->values(),
            'is_super_admin' => $isSuperAdmin,
            'platform_stats_rebuild' => $isSuperAdmin ? (new PlatformStatsRebuildService())->status() : ['status' => 'idle'],
        ];
    }

    public function users(int $adminId, array $where, array $selectedMerchantIds = []): array
    {
        $merchantIds = $this->merchantIds($adminId, $selectedMerchantIds);
        $query = User::with('merchant:id,mch_id,name')
            ->whereIn('merchant_id', $merchantIds)
            ->when(($where['status'] ?? '') !== '', fn ($q) => $q->where('status', $where['status']))
            ->when($where['keyword'] ?? null, fn ($q, $value) => $q->whereAny(['merchant_user_id', 'nickname'], 'like', "%{$value}%"));
        return $this->page($query);
    }

    public function monthly(int $adminId, string $type, array $where, array $selectedMerchantIds = []): array
    {
        $merchantIds = $this->merchantIds($adminId, $selectedMerchantIds);
        $dateColumn = EnterpriseScope::current($adminId) ? 'business_date' : 'platform_date';
        $start = new \DateTimeImmutable($where['date_start'] ?: gmdate('Y-m-01'), new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($where['date_end'] ?: gmdate('Y-m-d'), new \DateTimeZone('UTC'));
        $month = $start->modify('first day of this month -1 month');
        $lastMonth = $end->modify('first day of next month');
        $union = null;
        while ($month <= $lastMonth) {
            $table = (new MonthlyTableService())->table($type, $month->format('ym'));
            $part = Db::table($table)->whereNull('delete_time')->whereBetween($dateColumn, [$start->format('Y-m-d'), $end->format('Y-m-d')]);
            $union = $union ? $union->unionAll($part) : $part;
            $month = $month->modify('+1 month');
        }
        $query = Db::query()->fromSub($union, 't')
            ->join('mg_merchants as m', 'm.id', '=', 't.merchant_id')
            ->join('mg_users as u', 'u.id', '=', 't.user_id')
            ->join('mg_games as g', 'g.id', '=', 't.game_id')
            ->select('t.*', 'm.mch_id', 'm.name as merchant_name', 'u.merchant_user_id', 'u.nickname', 'g.game_code', 'g.name as game_name')
            ->whereIn('t.merchant_id', $merchantIds)
            ->when(($where['status'] ?? '') !== '', fn ($q) => $q->where('t.status', $where['status']))
            ->when($where['keyword'] ?? null, function ($q, $value) use ($type) {
                $number = $type === 'bets' ? 't.bet_no' : 't.bill_no';
                $q->where(fn ($item) => $item->where($number, 'like', "%{$value}%")->orWhere('u.merchant_user_id', 'like', "%{$value}%")->orWhere('g.name', 'like', "%{$value}%"));
            });
        $result = $this->page($query);
        $jsonField = $type === 'bets' ? 'actions' : 'data';
        foreach ($result['data'] as $row) $row->$jsonField = json_decode($row->$jsonField ?: ($type === 'bets' ? '[]' : '{}'), true);
        return $result;
    }

    public function merchantBills(int $adminId, array $where, array $selectedMerchantIds = []): array
    {
        $merchantIds = $this->merchantIds($adminId, $selectedMerchantIds);
        $query = MerchantBill::query()->join('mg_merchant_credits as c', 'c.id', '=', 'mg_merchant_bills.credit_id')
            ->join('mg_merchants as m', 'm.id', '=', 'c.merchant_id')
            ->select('mg_merchant_bills.*', 'c.currency_code', 'm.mch_id', 'm.name as merchant_name')
            ->whereIn('m.id', $merchantIds)
            ->when($where['date_start'] ?? null, fn ($q, $value) => $q->where('mg_merchant_bills.create_time', '>=', $value . ' 00:00:00'))
            ->when($where['date_end'] ?? null, fn ($q, $value) => $q->where('mg_merchant_bills.create_time', '<=', $value . ' 23:59:59'))
            ->when($where['keyword'] ?? null, fn ($q, $value) => $q->where(fn ($item) => $item->where('mg_merchant_bills.bill_no', 'like', "%{$value}%")->orWhere('mg_merchant_bills.source_no', 'like', "%{$value}%")));
        return $this->page($query, 'mg_merchant_bills.id');
    }

    public function reports(int $adminId, array $where, array $selectedMerchantIds = []): array
    {
        $merchantIds = $this->merchantIds($adminId, $selectedMerchantIds);
        $dateColumn = EnterpriseScope::current($adminId) ? 'business_date' : 'platform_date';
        $query = DailyStat::query()->join('mg_merchants as m', 'm.id', '=', 'mg_daily_stats.merchant_id')
            ->join('mg_games as g', 'g.id', '=', 'mg_daily_stats.game_id')
            ->join('mg_game_brands as b', 'b.id', '=', 'mg_daily_stats.brand_id')
            ->select('mg_daily_stats.*', 'm.mch_id', 'm.name as merchant_name', 'g.game_code', 'g.name as game_name', 'b.name as brand_name')
            ->selectRaw('COALESCE(ROUND(mg_daily_stats.win_amount / NULLIF(mg_daily_stats.bet_amount, 0) * 100, 2), 0) AS rtp')
            ->whereIn('mg_daily_stats.merchant_id', $merchantIds)
            ->when($where['date_start'] ?? null, fn ($q, $value) => $q->where($dateColumn, '>=', $value))
            ->when($where['date_end'] ?? null, fn ($q, $value) => $q->where($dateColumn, '<=', $value));
        return $this->page($query, 'mg_daily_stats.id');
    }

    private function merchantIds(int $adminId, array $selectedMerchantIds = []): array
    {
        $ids = EnterpriseScope::merchantIds($adminId);
        $query = Merchant::when($ids !== null, fn ($item) => $item->whereIn('id', $ids));
        if ($selectedMerchantIds) $query->whereIn('id', array_values(array_unique(array_map('intval', $selectedMerchantIds))));
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function page($query, string $order = 'id'): array
    {
        $page = max(1, (int) request()->input('page', 1));
        $limit = min(100, max(1, (int) request()->input('limit', 20)));
        $list = $query->orderByDesc($order)->paginate($limit, ['*'], 'page', $page);
        return [
            'current_page' => $list->currentPage(), 'per_page' => $list->perPage(), 'last_page' => $list->lastPage(),
            'has_more' => $list->hasMorePages(), 'total' => $list->total(), 'data' => $list->items(),
        ];
    }
}
