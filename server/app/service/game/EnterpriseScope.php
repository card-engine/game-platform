<?php

namespace app\service\game;

use app\model\EnterpriseUser;
use app\model\Merchant;
use plugin\saiadmin\app\model\system\SystemUser;
use plugin\saiadmin\exception\ApiException;

class EnterpriseScope
{
    public static function isGameSuperAdmin(int $systemUserId): bool
    {
        $user = SystemUser::with('roles:id,code,status')->find($systemUserId);
        return $user && (int) $user->status === 1 && (
            ($systemUserId === 1 && (int) $user->is_super === 1)
            || $user->roles->contains(fn ($role) => $role->code === 'game_super_admin' && (int) $role->status === 1)
        );
    }

    public static function current(int $systemUserId): ?EnterpriseUser
    {
        $scope = EnterpriseUser::with('enterprise', 'user.roles:id,code,status')->where('system_user_id', $systemUserId)->first();
        if (!$scope) {
            if (!self::isGameSuperAdmin($systemUserId)) throw new ApiException('无游戏平台访问权限');
            return null;
        }
        $role = $scope->is_owner ? 'enterprise_owner' : 'enterprise_staff';
        if ((int) $scope->status !== 1 || (int) $scope->user?->status !== 1 || (int) $scope->enterprise?->status !== 1) throw new ApiException('企业账号已停用');
        if (!$scope->user->roles->contains(fn ($item) => $item->code === $role && (int) $item->status === 1)) throw new ApiException('企业账号角色配置错误');
        return $scope;
    }

    public static function owner(int $systemUserId): EnterpriseUser
    {
        $scope = self::current($systemUserId);
        if (!$scope || !$scope->is_owner) throw new ApiException('只有企业负责人可以管理子账号');
        return $scope;
    }

    public static function merchantIds(int $systemUserId): ?array
    {
        $scope = self::current($systemUserId);
        if (!$scope) return null;
        if ($scope->is_owner) return Merchant::where('enterprise_id', $scope->enterprise_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $scope->merchants()->where('mg_merchants.enterprise_id', $scope->enterprise_id)->pluck('mg_merchants.id')->map(fn ($id) => (int) $id)->all();
    }
}
