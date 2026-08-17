<?php

namespace app\logic\game;

use app\model\Enterprise;
use app\model\EnterpriseUser;
use app\model\Merchant;
use Illuminate\Support\Arr;
use plugin\saiadmin\app\cache\UserAuthCache;
use plugin\saiadmin\app\cache\UserInfoCache;
use plugin\saiadmin\app\cache\UserMenuCache;
use plugin\saiadmin\app\model\system\SystemRole;
use plugin\saiadmin\app\model\system\SystemUser;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;
use support\Db;

class PlatformAdminLogic extends BaseLogic
{
    private const ROLES = ['game_super_admin', 'enterprise_owner', 'enterprise_staff'];

    public function __construct()
    {
        $this->model = new SystemUser();
    }

    public function indexList(array $where): array
    {
        $this->assertSuper();
        $query = SystemUser::query()->join('sa_system_user_role as ur', 'ur.user_id', '=', 'sa_system_user.id')
            ->join('sa_system_role as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('mg_enterprise_users as eu', function ($join) {
                $join->on('eu.system_user_id', '=', 'sa_system_user.id')->whereNull('eu.delete_time');
            })->leftJoin('mg_enterprises as e', function ($join) {
                $join->on('e.id', '=', 'eu.enterprise_id')->whereNull('e.delete_time');
            })->whereIn('r.code', self::ROLES)->whereNull('r.delete_time')
            ->where(fn ($item) => $item->where('r.code', 'game_super_admin')->orWhereNotNull('eu.id'))
            ->select('sa_system_user.id', 'sa_system_user.username', 'sa_system_user.realname', 'sa_system_user.status', 'sa_system_user.login_time', 'sa_system_user.create_time',
                'r.code as role_code', 'r.name as role_name', 'eu.id as enterprise_user_id', 'eu.enterprise_id', 'eu.is_owner', 'e.name as enterprise_name')
            ->when($where['keyword'] ?? null, fn ($item, $value) => $item->where(fn ($search) => $search->where('sa_system_user.username', 'like', "%{$value}%")->orWhere('sa_system_user.realname', 'like', "%{$value}%")))
            ->when($where['role_code'] ?? null, fn ($item, $value) => $item->where('r.code', $value))
            ->when($where['enterprise_id'] ?? null, fn ($item, $value) => $item->where('eu.enterprise_id', $value))
            ->when(($where['status'] ?? '') !== '', fn ($item) => $item->where('sa_system_user.status', $where['status']));

        $page = max(1, (int) request()->input('page', 1));
        $limit = min(100, max(1, (int) request()->input('limit', 10)));
        $list = $query->orderByDesc('sa_system_user.id')->paginate($limit, ['*'], 'page', $page);
        $rows = $list->items();
        $relationIds = collect($rows)->pluck('enterprise_user_id')->filter()->all();
        $merchantIds = Db::table('mg_enterprise_user_merchants')->whereIn('enterprise_user_id', $relationIds)->whereNull('delete_time')
            ->get(['enterprise_user_id', 'merchant_id'])->groupBy('enterprise_user_id');
        $protectedId = $this->protectedSuperId();
        foreach ($rows as $row) {
            $row->merchant_ids = ($merchantIds[$row->enterprise_user_id] ?? collect())->pluck('merchant_id')->map(fn ($id) => (int) $id)->values()->all();
            $row->protected = (int) $row->id === $protectedId;
            $row->current = (int) $row->id === (int) $this->adminInfo['id'];
        }
        return [
            'current_page' => $list->currentPage(), 'per_page' => $list->perPage(), 'last_page' => $list->lastPage(),
            'has_more' => $list->hasMorePages(), 'total' => $list->total(), 'data' => $rows,
        ];
    }

    public function options(): array
    {
        $this->assertSuper();
        $owners = EnterpriseUser::where('is_owner', 1)->pluck('enterprise_id')->map(fn ($id) => (int) $id)->all();
        return [
            'roles' => [
                ['value' => 'game_super_admin', 'label' => '平台超管'],
                ['value' => 'enterprise_owner', 'label' => '企业负责人'],
                ['value' => 'enterprise_staff', 'label' => '企业子账号'],
            ],
            'enterprises' => Enterprise::orderBy('name')->get(['id', 'name', 'status'])->map(fn ($item) => $item->setAttribute('has_owner', in_array((int) $item->id, $owners, true))),
            'merchants' => Merchant::with('enterprise:id,name')->orderBy('name')->get(['id', 'enterprise_id', 'mch_id', 'name', 'status']),
        ];
    }

    public function add(array $data): int
    {
        $this->assertSuper();
        return $this->transaction(function () use ($data) {
            if (SystemUser::withTrashed()->where('username', $data['username'])->exists()) throw new ApiException('登录账号已存在');
            $user = SystemUser::create([
                'username' => $data['username'], 'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'realname' => $data['realname'] ?? $data['username'], 'is_super' => 0, 'status' => $data['status'] ?? 1,
                'remark' => ['game_super_admin' => 'MG 游戏超管', 'enterprise_owner' => 'MG 企业负责人', 'enterprise_staff' => 'MG 企业子账号'][$data['role_code']],
            ]);
            $roleId = SystemRole::where(['code' => $data['role_code'], 'status' => 1])->value('id');
            if (!$roleId) throw new ApiException('游戏平台角色未配置');
            $user->roles()->sync([$roleId]);
            if ($data['role_code'] !== 'game_super_admin') {
                $enterprise = Enterprise::lockForUpdate()->findOrFail($data['enterprise_id']);
                if ($data['role_code'] === 'enterprise_owner' && EnterpriseUser::where(['enterprise_id' => $enterprise->id, 'is_owner' => 1])->exists()) throw new ApiException('该企业已有负责人');
                $relation = EnterpriseUser::create([
                    'enterprise_id' => $enterprise->id, 'system_user_id' => $user->id,
                    'is_owner' => $data['role_code'] === 'enterprise_owner' ? 1 : 0, 'status' => ($data['status'] ?? 1) === 1 ? 1 : 0,
                ]);
                if ($data['role_code'] === 'enterprise_staff') $relation->merchants()->sync($this->merchantIds((int) $enterprise->id, $data['merchant_ids'] ?? []));
            }
            return (int) $user->id;
        });
    }

    public function edit($id, array $data): mixed
    {
        $this->assertSuper();
        $account = $this->account($id);
        if ($account['role_code'] !== $data['role_code']) throw new ApiException('账号身份不能直接修改');
        if (($account['protected'] || $id === (int) $this->adminInfo['id']) && (int) $data['status'] !== 1) throw new ApiException('当前账号不能停用');
        if (SystemUser::withTrashed()->where('username', $data['username'])->where('id', '<>', $id)->exists()) throw new ApiException('登录账号已存在');
        return $this->transaction(function () use ($id, $data, $account) {
            SystemUser::findOrFail($id)->update(Arr::only($data, ['username', 'realname', 'status']));
            if ($account['role_code'] !== 'game_super_admin') {
                $relation = EnterpriseUser::where('system_user_id', $id)->lockForUpdate()->firstOrFail();
                $relation->update(['status' => (int) $data['status'] === 1 ? 1 : 0]);
                if ($account['role_code'] === 'enterprise_staff') {
                    $enterprise = Enterprise::lockForUpdate()->findOrFail($data['enterprise_id']);
                    $relation->update(['enterprise_id' => $enterprise->id]);
                    $relation->merchants()->sync($this->merchantIds((int) $enterprise->id, $data['merchant_ids'] ?? []));
                }
            }
            $this->clearCache($id);
            return true;
        });
    }

    public function password(int $id, string $password): bool
    {
        $this->assertSuper();
        $this->account($id);
        $result = SystemUser::whereKey($id)->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
        $this->clearCache($id);
        return $result > 0;
    }

    public function destroy($id): bool
    {
        $this->assertSuper();
        $account = $this->account($id);
        if ($account['protected']) throw new ApiException('平台主超管禁止删除');
        if ($id === (int) $this->adminInfo['id']) throw new ApiException('不能删除当前登录账号');
        return $this->transaction(function () use ($id) {
            if ($relation = EnterpriseUser::where('system_user_id', $id)->first()) {
                $relation->merchants()->detach();
                $relation->delete();
            }
            SystemUser::findOrFail($id)->delete();
            $this->clearCache($id);
            return true;
        });
    }

    private function account(int $id): array
    {
        $user = SystemUser::with('roles:id,code')->findOrFail($id);
        $role = $user->roles->first(fn ($item) => in_array($item->code, self::ROLES, true));
        if (!$role) throw new ApiException('游戏平台账号不存在');
        return ['role_code' => $role->code, 'protected' => $role->code === 'game_super_admin' && $id === $this->protectedSuperId()];
    }

    private function merchantIds(int $enterpriseId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) throw new ApiException('请至少选择一个商户参数');
        $valid = Merchant::where('enterprise_id', $enterpriseId)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($valid) !== count($ids)) throw new ApiException('商户参数范围无效');
        return $valid;
    }

    private function protectedSuperId(): int
    {
        return (int) SystemUser::whereHas('roles', fn ($query) => $query->where('code', 'game_super_admin')->where('status', 1))->min('sa_system_user.id');
    }

    private function assertSuper(): void
    {
        if ((int) $this->adminInfo['id'] === 1) return;
        if (!collect($this->adminInfo['roleList'] ?? [])->contains(fn ($role) => $role['code'] === 'game_super_admin' && (int) $role['status'] === 1)) throw new ApiException('仅平台超管可操作');
    }

    private function clearCache(int $id): void
    {
        UserInfoCache::clearUserInfo($id);
        UserAuthCache::clearUserAuth($id);
        UserMenuCache::clearUserMenu($id);
    }
}
