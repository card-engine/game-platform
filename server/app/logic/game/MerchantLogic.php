<?php

namespace app\logic\game;

use app\model\Enterprise;
use app\model\Merchant;
use app\model\MerchantBrand;
use app\model\MerchantCredit;
use app\model\MerchantGame;
use app\model\MerchantBill;
use app\model\MerchantMonthlyBill;
use app\model\UniqueBrand;
use app\service\game\EnterpriseScope;
use app\service\game\OpenApiService;
use app\service\game\SecretService;
use app\service\game\report\MonthlyBillingService;
use DateTimeZone;
use Illuminate\Support\Arr;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class MerchantLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new Merchant();
    }

    public function indexList(array $where): array
    {
        $query = $this->search($where)->with(['enterprise:id,name', 'credits']);
        if (($ids = EnterpriseScope::merchantIds((int) $this->adminInfo['id'])) !== null) $query->whereIn('id', $ids);
        return $this->getList($query);
    }

    public function add(array $data): mixed
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能创建商户参数');
        if ((int) $data['wallet_mode'] !== 1) throw new ApiException('转账钱包尚未启用');
        return $this->transaction(function () use ($data) {
            $enterprise = Enterprise::lockForUpdate()->findOrFail($data['enterprise_id']);
            if (Merchant::where('enterprise_id', $enterprise->id)->count() >= $enterprise->merchant_limit) throw new ApiException('已达到该企业商户参数上限');
            $fields = Arr::only($data, ['enterprise_id', 'name', 'wallet_mode', 'callback_url', 'ip_whitelist', 'language_codes', 'default_language', 'gc_exchange_rate', 'timezone', 'timeout_ms', 'status', 'remark']);
            $fields['billing_mode'] = 1;
            $fields['secret'] = SecretService::encrypt(bin2hex(random_bytes(16)));
            $merchant = Merchant::create($fields);
            $merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
            if (isset($data['billing_mode'])) {
                $this->billing((int) $merchant->id, Arr::except($data, ['credits']));
                $merchant->refresh();
            }
            foreach ($this->normalizeCredits($data['credits'] ?? [], (int) $merchant->billing_mode === 1) as $credit) {
                MerchantCredit::create(Arr::only($credit, ['currency_code', 'rate_value', 'settlement_enabled', 'available_amount', 'status']) + ['merchant_id' => $merchant->id]);
            }
            if ($data['copy_from_merchant_id'] ?? null) {
                $source = Merchant::where(['id' => $data['copy_from_merchant_id'], 'enterprise_id' => $merchant->enterprise_id])->firstOrFail();
                foreach (MerchantBrand::where('merchant_id', $source->id)->get() as $brand) MerchantBrand::create(['merchant_id' => $merchant->id, 'unique_brand_id' => $brand->unique_brand_id, 'status' => $brand->status, 'merchant_status' => $brand->merchant_status]);
                foreach (MerchantGame::where('merchant_id', $source->id)->get() as $game) MerchantGame::create(['merchant_id' => $merchant->id, 'game_id' => $game->game_id, 'status' => $game->status, 'merchant_status' => $game->merchant_status, 'rate_value' => $game->rate_value]);
            } else {
                foreach ($data['brand_ids'] ?? [] as $brandId) MerchantBrand::create(['merchant_id' => $merchant->id, 'unique_brand_id' => $brandId, 'status' => 1]);
            }
            return (int) $merchant->id;
        });
    }

    public function edit($id, array $data): mixed
    {
        $merchant = $this->findScoped((int) $id);
        if (isset($data['wallet_mode']) && (int) $data['wallet_mode'] !== 1) throw new ApiException('转账钱包尚未启用');
        $fields = Arr::only($data, ['name', 'wallet_mode', 'callback_url', 'ip_whitelist', 'language_codes', 'default_language', 'timezone', 'timeout_ms', 'status', 'remark']);
        $updated = $merchant->update($fields);
        if (isset($data['credits']) && !EnterpriseScope::current((int) $this->adminInfo['id'])) $this->credits($id, (array) $data['credits']);
        return $updated;
    }

    public function reveal(int $id): array
    {
        $merchant = $this->findScoped($id);
        return ['mch_id' => $merchant->mch_id, 'secret' => SecretService::decrypt($merchant->getRawOriginal('secret'))];
    }

    public function resetSecret(int $id): array
    {
        $merchant = $this->findScoped($id);
        $secret = bin2hex(random_bytes(16));
        $merchant->update(['secret' => SecretService::encrypt($secret)]);
        return ['mch_id' => $merchant->mch_id, 'secret' => $secret];
    }

    public function options(): array
    {
        $scope = EnterpriseScope::current((int) $this->adminInfo['id']);
        $merchantIds = EnterpriseScope::merchantIds((int) $this->adminInfo['id']);
        $enterprises = Enterprise::when($scope, fn ($query) => $query->whereKey($scope->enterprise_id))->where('status', 1)->get(['id', 'name']);
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
        $timezones = array_map(fn (string $id) => [
            'value' => $id,
            'label' => 'UTC' . (new \DateTimeImmutable('now', new DateTimeZone($id)))->format('P') . ' · ' . $id,
        ], $timezones);
        usort($timezones, fn (array $a, array $b) => strcmp($a['label'], $b['label']));
        return [
            'enterprises' => $enterprises,
            'merchants' => Merchant::when($merchantIds !== null, fn ($query) => $query->whereIn('id', $merchantIds))->get(['id', 'enterprise_id', 'mch_id', 'name', 'timezone', 'billing_mode']),
            'brands' => UniqueBrand::where('status', 1)->orderBy('sort')->orderBy('name')->get(['id', 'code', 'name']),
            'timezones' => $timezones,
            'role' => !$scope ? 'super_admin' : ($scope->is_owner ? 'enterprise_owner' : 'enterprise_staff'),
        ];
    }

    public function grantState(int $id): array
    {
        $merchant = $this->findScoped($id);
        return [
            'brands' => MerchantBrand::where('merchant_id', $merchant->id)->get(['unique_brand_id', 'status', 'merchant_status']),
            'games' => MerchantGame::with('game:id,game_code,name,brand_id,platform_code')->where('merchant_id', $merchant->id)->get(['game_id', 'status', 'merchant_status', 'rate_value']),
        ];
    }

    public function credits(int $id, array $items): bool
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能修改计费和额度');
        $merchant = $this->findScoped($id);
        return $this->transaction(fn () => $this->saveCredits($merchant, $items));
    }

    public function grants(int $id, array $brandIds, array $games): bool
    {
        $merchant = $this->findScoped($id);
        $isAdmin = !EnterpriseScope::current((int) $this->adminInfo['id']);
        foreach ($games as $game) {
            if (isset($game['rate_value']) && $game['rate_value'] !== null && $game['rate_value'] !== '') {
                $rate = (string) $game['rate_value'];
                if (preg_match('/^\d+(?:\.\d{1,10})?$/', $rate) !== 1 || bccomp($rate, '1', 10) > 0) throw new ApiException('游戏费率无效');
            }
        }
        return $this->transaction(function () use ($merchant, $brandIds, $games, $isAdmin) {
            MerchantBrand::where('merchant_id', $merchant->id)->update([$isAdmin ? 'status' : 'merchant_status' => 0]);
            foreach ($brandIds as $brandId) MerchantBrand::withTrashed()->updateOrCreate(
                ['merchant_id' => $merchant->id, 'unique_brand_id' => $brandId],
                [$isAdmin ? 'status' : 'merchant_status' => 1, 'delete_time' => null],
            );
            MerchantGame::where('merchant_id', $merchant->id)->update([$isAdmin ? 'status' : 'merchant_status' => 0]);
            foreach ($games as $game) MerchantGame::withTrashed()->updateOrCreate(
                ['merchant_id' => $merchant->id, 'game_id' => $game['game_id']],
                ($isAdmin ? Arr::only($game, ['status', 'merchant_status', 'rate_value']) : Arr::only($game, ['merchant_status'])) + ['delete_time' => null],
            );
            return true;
        });
    }

    public function billing(int $id, array $data): bool
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能修改计费方案');
        $merchant = $this->findScoped($id);
        $mode = (int) $data['billing_mode'];
        if (!in_array($mode, [1, 2], true)) throw new ApiException('计费方案无效');
        $fields = ['billing_mode' => $mode];
        if ($mode === 2) {
            $metric = (int) ($data['monthly_metric'] ?? 0);
            $minFee = str_replace([',', ' '], '', (string) ($data['monthly_min_fee'] ?? '0'));
            $tiers = array_values($data['monthly_tiers'] ?? []);
            if (!in_array($metric, [1, 2], true) || !is_numeric($minFee) || bccomp($minFee, '0', 8) <= 0 || !$tiers) throw new ApiException('请填写正确的阶梯月费规则');
            foreach ($tiers as &$tier) {
                $tier = [
                    'min' => str_replace([',', ' '], '', (string) ($tier['min'] ?? '0')),
                    'fee' => str_replace([',', ' '], '', (string) ($tier['fee'] ?? '0')),
                ];
                if (!is_numeric($tier['min']) || !is_numeric($tier['fee']) || bccomp($tier['min'], '0', 8) < 0 || bccomp($tier['fee'], $minFee, 8) < 0) throw new ApiException('阶梯起始值或月费无效');
            }
            usort($tiers, fn ($a, $b) => bccomp($a['min'], $b['min'], 8));
            if (bccomp($tiers[0]['min'], '0', 8) !== 0) array_unshift($tiers, ['min' => '0', 'fee' => $minFee]);
            $fields += ['monthly_metric' => $metric, 'monthly_min_fee' => $minFee, 'monthly_tiers' => $tiers];
        }
        return $this->transaction(function () use ($merchant, $mode, $fields, $data) {
            $merchant->update($fields);
            if (isset($data['credits'])) return $this->saveCredits($merchant, (array) $data['credits']);
            foreach ($merchant->credits as $credit) $credit->update(['settlement_enabled' => $mode === 1 && $credit->currency_code !== 'GC' ? 1 : 0]);
            return true;
        });
    }

    public function billingState(int $id): array
    {
        $merchant = $this->findScoped($id);
        $preview = (int) $merchant->billing_mode === 2 ? (new MonthlyBillingService())->preview($merchant) : null;
        $games = (new OpenApiService())->games($merchant);
        $credits = $merchant->credits()->get();
        foreach ($credits as $credit) $credit->matched_games = (clone $games)->whereJsonContains('currency_codes', $credit->currency_code)->count();
        return [
            'merchant' => $merchant->only(['id', 'name', 'billing_mode', 'monthly_metric', 'monthly_min_fee', 'monthly_tiers']),
            'credits' => $credits,
            'bill' => MerchantMonthlyBill::where('merchant_id', $merchant->id)->latest('billing_month')->first(),
            'stats' => $preview ? $preview['stats'] : null,
            'next_fee' => $preview['next_fee'] ?? null,
        ];
    }

    public function billStatus(int $id, int $status, string $remark): bool
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能修改月费账单');
        if (!in_array($status, [0, 1, 2, 3], true)) throw new ApiException('账单状态无效');
        $bill = MerchantMonthlyBill::findOrFail($id);
        $this->findScoped((int) $bill->merchant_id);
        return $bill->update(['status' => $status, 'paid_time' => in_array($status, [1, 3], true) ? date('Y-m-d H:i:s') : null, 'remark' => $remark]);
    }

    public function adjustCredit(int $creditId, string $amount, int $direction, string $remark): int
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能调整额度');
        if (bccomp($amount, '0', 8) <= 0) throw new ApiException('金额必须大于 0');
        return $this->transaction(function () use ($creditId, $amount, $direction, $remark) {
            $credit = MerchantCredit::lockForUpdate()->findOrFail($creditId);
            $before = $credit->available_amount;
            $after = $direction === 1 ? bcadd($before, $amount, 8) : bcsub($before, $amount, 8);
            if (bccomp($after, '0', 8) < 0) throw new ApiException('可用额度不足');
            $credit->update(['available_amount' => $after]);
            return (int) MerchantBill::insertGetId([
                'bill_no' => mg_no('MC'), 'credit_id' => $credit->id, 'type' => $direction === 1 ? 1 : 3,
                'direction' => $direction, 'amount' => $amount, 'before_amount' => $before, 'after_amount' => $after,
                'source' => 'manual', 'remark' => $remark, 'created_by' => $this->adminInfo['id'], 'create_time' => date('Y-m-d H:i:s.v'),
            ]);
        });
    }

    private function findScoped(int $id): Merchant
    {
        $query = Merchant::whereKey($id);
        if (($ids = EnterpriseScope::merchantIds((int) $this->adminInfo['id'])) !== null) $query->whereIn('id', $ids);
        $merchant = $query->first();
        if (!$merchant) throw new ApiException('商户参数不存在');
        return $merchant;
    }

    private function normalizeCredits(array $items, bool $settlement): array
    {
        $items = array_values(array_map(function ($item) use ($settlement) {
            $item['currency_code'] = strtoupper(trim((string) ($item['currency_code'] ?? '')));
            if ($item['currency_code'] === '') throw new ApiException('币种无效');
            $item['rate_value'] = (string) ($item['rate_value'] ?? '0.03');
            if (preg_match('/^\d+(?:\.\d{1,10})?$/', $item['rate_value']) !== 1 || bccomp($item['rate_value'], '1', 10) > 0) throw new ApiException('费率无效');
            $item['rate_value'] = bcadd($item['rate_value'], '0', 10);
            $item['settlement_enabled'] = $settlement && $item['currency_code'] !== 'GC' ? 1 : 0;
            return $item;
        }, $items));
        $currencies = array_values(array_unique(array_column(array_filter($items, fn ($item) => (int) ($item['status'] ?? 1) === 1), 'currency_code')));
        sort($currencies);
        if (!$currencies) throw new ApiException('请至少启用一个币种');
        if (count(array_diff($currencies, ['SC', 'GC'])) > 1) throw new ApiException('普通币种只能启用一个');
        if (in_array('GC', $currencies, true) && !in_array('SC', $currencies, true)) throw new ApiException('启用 GC 时需要同时启用 SC');
        return $items;
    }

    private function saveCredits(Merchant $merchant, array $items): bool
    {
        MerchantCredit::where('merchant_id', $merchant->id)->update(['status' => 0]);
        foreach ($this->normalizeCredits($items, (int) $merchant->billing_mode === 1) as $item) {
            MerchantCredit::withTrashed()->updateOrCreate(
                ['merchant_id' => $merchant->id, 'currency_code' => $item['currency_code']],
                Arr::only($item, ['rate_value', 'settlement_enabled', 'status']) + ['delete_time' => null],
            );
        }
        return true;
    }
}
