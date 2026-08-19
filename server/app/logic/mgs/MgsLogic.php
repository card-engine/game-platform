<?php

namespace app\logic\mgs;

use app\model\mgs\Game;
use app\model\mgs\User;
use app\service\mgs\MgsTableService;
use app\service\mgs\MgsConfigService;
use DateTimeImmutable;
use DateTimeZone;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;
use support\Db;

class MgsLogic extends BaseLogic
{
    public function overview(): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')))))->format('Y-m-d');
        return [
            'user_count' => User::count(),
            'game_count' => Game::count(),
            'active_game_count' => Game::where('status', 1)->count(),
            'wallets' => Db::table('mgs_wallets')->selectRaw('currency_code, COUNT(*) wallet_count, COALESCE(SUM(balance), 0) balance')->groupBy('currency_code')->orderBy('currency_code')->get(),
            'daily' => Db::table('mgs_daily_stats')->where('stat_date', $today)->selectRaw('currency_code, SUM(active_user_count) active_user_count, SUM(bet_count) bet_count, SUM(bet_amount) bet_amount, SUM(win_amount) win_amount, SUM(rollback_amount) rollback_amount, SUM(ggr_amount) ggr_amount, SUM(platform_fee) platform_fee')->groupBy('currency_code')->orderBy('currency_code')->get(),
            'hourly' => Db::table('mgs_hourly_stats')->where('stat_date', $today)->orderBy('stat_hour')->orderBy('currency_code')->get(),
            'monthly' => Db::table('mgs_monthly_stats')->orderByDesc('stat_month')->orderBy('currency_code')->limit(36)->get()->reverse()->values(),
        ];
    }

    public function games(array $where): array
    {
        $query = Game::with('brand:id,name')->when($where['status'] !== '', fn ($q) => $q->where('status', (int) $where['status']))
            ->when($where['keyword'], fn ($q, $value) => $q->where(fn ($item) => $item->where('name', 'like', "%{$value}%")->orWhere('platform_game_code', 'like', "%{$value}%")))
            ->orderBy('sort')->orderBy('id');
        return $this->page($query);
    }

    public function gameStatus(int $id, int $status): void
    {
        Game::whereKey($id)->update(['status' => $status ? 1 : 0, 'update_time' => $this->now()]);
    }

    public function gameConfig(int $id, array $data): void
    {
        $game = Game::findOrFail($id);
        if (isset($data['rate_value']) && (preg_match('/^\d+(?:\.\d{1,10})?$/', (string) $data['rate_value']) !== 1 || bccomp((string) $data['rate_value'], '1', 10) > 0)) throw new ApiException('费率格式无效');
        if (isset($data['default_rtp']) && $data['default_rtp'] !== '' && $game->rtp_options && !in_array((string) $data['default_rtp'], $game->rtp_options, true)) throw new ApiException('RTP 档位无效');
        Game::whereKey($id)->update(array_filter([
            'sort' => isset($data['sort']) ? (int) $data['sort'] : null,
            'is_hot' => isset($data['is_hot']) ? (int) $data['is_hot'] : null,
            'is_new' => isset($data['is_new']) ? (int) $data['is_new'] : null,
            'default_rtp' => isset($data['default_rtp']) ? (string) $data['default_rtp'] : null,
            'rate_value' => isset($data['rate_value']) ? bcadd((string) $data['rate_value'], '0', 10) : null,
            'update_time' => $this->now(),
        ], fn ($value) => $value !== null));
    }

    public function users(array $where): array
    {
        $query = User::with('wallets:id,user_id,currency_code,balance')->when($where['status'] !== '', fn ($q) => $q->where('status', (int) $where['status']))
            ->when($where['keyword'], fn ($q, $value) => $q->where(fn ($item) => $item->where('user_no', 'like', "%{$value}%")->orWhere('nickname', 'like', "%{$value}%")));
        return $this->page($query);
    }

    public function monthly(string $type, array $where): array
    {
        $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
        $today = new DateTimeImmutable('today', $zone);
        $start = new DateTimeImmutable(($where['date_start'] ?: $today->format('Y-m-01')) . ' 00:00:00', $zone)->setTimezone(new DateTimeZone('UTC'));
        $end = new DateTimeImmutable(($where['date_end'] ?: $today->format('Y-m-d')) . ' 23:59:59.999', $zone)->setTimezone(new DateTimeZone('UTC'));
        $union = null;
        foreach ($this->months($start, $end, $type) as $table) {
            $part = Db::table($table)->whereNull('delete_time')->whereBetween('create_time', [$start->format('Y-m-d H:i:s.v'), $end->format('Y-m-d H:i:s.v')]);
            $union = $union ? $union->unionAll($part) : $part;
        }
        $number = $type === 'bets' ? 'bet_no' : 'bill_no';
        $query = Db::query()->fromSub($union, 't')->join('mgs_users as u', 'u.id', '=', 't.user_id')->leftJoin('mgs_games as g', 'g.id', '=', 't.game_id')
            ->select('t.*', 'u.user_no', 'u.nickname', 'g.name as game_name')
            ->when($where['status'] !== '', fn ($q) => $q->where('t.status', (int) $where['status']))
            ->when($type === 'bills' && $where['type'] !== '', fn ($q) => $q->where('t.type', $where['type']))
            ->when($where['keyword'], fn ($q, $value) => $q->where(fn ($item) => $item->where("t.{$number}", 'like', "%{$value}%")->orWhere('u.user_no', 'like', "%{$value}%")->orWhere('g.name', 'like', "%{$value}%")->orWhere('t.' . ($type === 'bets' ? 'platform_round_id' : 'transaction_id'), 'like', "%{$value}%")));
        $result = $this->page($query, "t.{$number}");
        $field = $type === 'bets' ? 'actions' : 'data';
        foreach ($result['data'] as $row) {
            if (is_string($row->{$field} ?? null)) $row->{$field} = json_decode($row->{$field}, true) ?: [];
            if ($type === 'bets') $row->rollback_amount = bcadd((string) $row->bet_rollback_amount, (string) $row->win_rollback_amount, 8);
        }
        return $result;
    }

    public function reports(array $where): array
    {
        $query = Db::table('mgs_daily_stats as s')->leftJoin('mgs_games as g', 'g.id', '=', 's.game_id')->select('s.*', 'g.name as game_name')
            ->when($where['date_start'], fn ($q, $value) => $q->where('s.stat_date', '>=', $value))
            ->when($where['date_end'], fn ($q, $value) => $q->where('s.stat_date', '<=', $value))
            ->when($where['currency_code'], fn ($q, $value) => $q->where('s.currency_code', $value))
            ->when($where['keyword'], fn ($q, $value) => $q->where('g.name', 'like', "%{$value}%"));
        return $this->page($query, 's.stat_date');
    }

    public function settlements(array $where): array
    {
        $query = Db::table('mgs_settlements')
            ->when($where['settlement_month'], fn ($q, $value) => $q->where('settlement_month', $value))
            ->when($where['currency_code'], fn ($q, $value) => $q->where('currency_code', $value))
            ->when($where['status'] !== '', fn ($q) => $q->where('status', (int) $where['status']));
        return $this->page($query);
    }

    private function months(DateTimeImmutable $start, DateTimeImmutable $end, string $type): array
    {
        $tables = [];
        $month = new DateTimeImmutable($start->format('Y-m-01'), new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($end->format('Y-m-01'), new DateTimeZone('UTC'));
        while ($month <= $last) {
            $tables[] = (new MgsTableService())->table($type, $month->format('ym'));
            $month = $month->modify('+1 month');
        }
        return $tables;
    }

    private function page($query, string $order = 'id'): array
    {
        $page = max(1, (int) request()->input('page', 1));
        $limit = min(100, max(1, (int) request()->input('limit', 20)));
        $result = $query->orderByDesc($order)->paginate($limit, ['*'], 'page', $page);
        return ['current_page' => $result->currentPage(), 'per_page' => $result->perPage(), 'last_page' => $result->lastPage(), 'has_more' => $result->hasMorePages(), 'total' => $result->total(), 'data' => $result->items()];
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
