<?php

namespace app\logic\game;

use app\model\Enterprise;
use app\model\EnterpriseUser;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\service\game\EnterpriseScope;
use app\service\game\SecretService;
use Illuminate\Support\Arr;
use plugin\saiadmin\app\model\system\SystemRole;
use plugin\saiadmin\app\model\system\SystemUser;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class EnterpriseLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new Enterprise();
    }

    public function indexList(array $where): array
    {
        $query = $this->search($where)->withCount(['merchants', 'users']);
        if ($scope = EnterpriseScope::current((int) $this->adminInfo['id'])) $query->whereKey($scope->enterprise_id);
        return $this->getList($query);
    }

    public function addWithOwner(array $data): int
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能创建企业');
        return $this->transaction(function () use ($data) {
            $enterprise = Enterprise::create(Arr::only($data, ['name', 'merchant_limit', 'timezone', 'default_language', 'status', 'remark']) + ['status' => 1]);
            $user = SystemUser::create([
                'username' => $data['username'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'realname' => $data['realname'] ?? $data['username'],
                'is_super' => 0,
                'status' => 1,
                'remark' => 'MG 企业负责人',
            ]);
            $user->roles()->sync([SystemRole::where('code', 'enterprise_owner')->where('status', 1)->value('id') ?: throw new ApiException('企业负责人角色未配置')]);
            EnterpriseUser::create(['enterprise_id' => $enterprise->id, 'system_user_id' => $user->id, 'is_owner' => 1, 'status' => 1]);
            if (!empty($data['create_merchant'])) {
                $merchant = $data['merchant'] ?? [];
                $currencies = array_values(array_unique(array_filter(array_map(fn ($currency) => strtoupper(trim((string) $currency)), (array) ($merchant['currency_codes'] ?? [])))));
                if (empty($merchant['name']) || !$currencies) throw new ApiException('请完整填写商户参数和币种');
                if (count(array_diff($currencies, ['SC', 'GC'])) > 1) throw new ApiException('普通币种只能启用一个');
                if (in_array('GC', $currencies, true) && !in_array('SC', $currencies, true)) throw new ApiException('启用 GC 时需要同时启用 SC');
                $merchantModel = Merchant::create([
                    'enterprise_id' => $enterprise->id,
                    'name' => $merchant['name'],
                    'wallet_mode' => 1,
                    'callback_url' => $merchant['callback_url'] ?? null,
                    'secret' => SecretService::encrypt(bin2hex(random_bytes(16))),
                    'ip_whitelist' => $merchant['ip_whitelist'] ?? [],
                    'language_codes' => ($merchant['language_codes'] ?? []) ?: ['en'],
                    'default_language' => $merchant['default_language'] ?? 'en',
                    'timezone' => $merchant['timezone'] ?? $enterprise->timezone,
                    'timeout_ms' => 5000,
                    'billing_mode' => 1,
                    'status' => 1,
                ]);
                $merchantModel->update(['mch_id' => (string) id2big((int) $merchantModel->id)]);
                foreach ($currencies as $currency) MerchantCredit::create([
                    'merchant_id' => $merchantModel->id, 'currency_code' => $currency,
                    'rate_value' => bcdiv((string) ($merchant['rate_percent'] ?? 3), '100', 10),
                    'settlement_enabled' => $currency === 'GC' ? 0 : 1, 'status' => 1,
                ]);
            }
            return (int) $enterprise->id;
        });
    }

    public function edit($id, array $data): mixed
    {
        if (EnterpriseScope::current((int) $this->adminInfo['id'])) throw new ApiException('企业账号不能修改企业配置');
        return parent::edit($id, Arr::only($data, ['name', 'merchant_limit', 'timezone', 'default_language', 'status', 'remark']));
    }

    public function users(int $enterpriseId): array
    {
        if ($scope = EnterpriseScope::current((int) $this->adminInfo['id'])) $enterpriseId = (int) EnterpriseScope::owner((int) $this->adminInfo['id'])->enterprise_id;
        return EnterpriseUser::with('user:id,username,realname,status,login_time', 'merchants:id,name,mch_id')->where('enterprise_id', $enterpriseId)->get()->toArray();
    }

    public function addUser(array $data): int
    {
        $scope = EnterpriseScope::current((int) $this->adminInfo['id']);
        $enterpriseId = $scope ? (int) EnterpriseScope::owner((int) $this->adminInfo['id'])->enterprise_id : (int) $data['enterprise_id'];
        if (empty($data['merchant_ids'])) throw new ApiException('请至少选择一个商户参数');
        return $this->transaction(function () use ($data, $enterpriseId) {
            $user = SystemUser::create([
                'username' => $data['username'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'realname' => $data['realname'] ?? $data['username'],
                'is_super' => 0,
                'status' => 1,
                'remark' => 'MG 企业子账号',
            ]);
            $user->roles()->sync([SystemRole::where('code', 'enterprise_staff')->where('status', 1)->value('id') ?: throw new ApiException('企业子账号角色未配置')]);
            $relation = EnterpriseUser::create(['enterprise_id' => $enterpriseId, 'system_user_id' => $user->id, 'is_owner' => 0, 'status' => 1]);
            $relation->merchants()->sync($this->merchantIds($enterpriseId, $data['merchant_ids'] ?? []));
            return (int) $user->id;
        });
    }

    public function setUserMerchants(int $id, array $merchantIds): bool
    {
        $relation = EnterpriseUser::findOrFail($id);
        if ($scope = EnterpriseScope::current((int) $this->adminInfo['id'])) {
            EnterpriseScope::owner((int) $this->adminInfo['id']);
            if ((int) $relation->enterprise_id !== (int) $scope->enterprise_id || $relation->is_owner) throw new ApiException('不能操作该账号');
        }
        $relation->merchants()->sync($this->merchantIds((int) $relation->enterprise_id, $merchantIds));
        return true;
    }

    public function setUserStatus(int $id, int $status): bool
    {
        $relation = EnterpriseUser::findOrFail($id);
        if ($scope = EnterpriseScope::current((int) $this->adminInfo['id'])) {
            EnterpriseScope::owner((int) $this->adminInfo['id']);
            if ((int) $relation->enterprise_id !== (int) $scope->enterprise_id || $relation->is_owner) throw new ApiException('不能操作该账号');
        }
        return $this->transaction(function () use ($relation, $status) {
            $relation->update(['status' => $status]);
            return SystemUser::whereKey($relation->system_user_id)->update(['status' => $status ? 1 : 2]) > 0;
        });
    }

    public function setUserPassword(int $id, string $password): bool
    {
        $relation = EnterpriseUser::findOrFail($id);
        if ($scope = EnterpriseScope::current((int) $this->adminInfo['id'])) {
            EnterpriseScope::owner((int) $this->adminInfo['id']);
            if ((int) $relation->enterprise_id !== (int) $scope->enterprise_id || $relation->is_owner) throw new ApiException('不能操作该账号');
        }
        return SystemUser::whereKey($relation->system_user_id)->update(['password' => password_hash($password, PASSWORD_DEFAULT)]) > 0;
    }

    public function enterpriseRoles(): array
    {
        return SystemRole::where('code', 'like', 'enterprise_%')->where('status', 1)->get(['id', 'name', 'code'])->toArray();
    }

    private function merchantIds(int $enterpriseId, array $merchantIds): array
    {
        $merchantIds = array_values(array_unique(array_map('intval', $merchantIds)));
        $valid = Merchant::where('enterprise_id', $enterpriseId)->whereIn('id', $merchantIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($valid) !== count($merchantIds)) throw new ApiException('商户参数范围无效');
        return $valid;
    }
}
