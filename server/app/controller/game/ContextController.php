<?php

namespace app\controller\game;

use app\model\Merchant;
use app\service\game\EnterpriseScope;
use app\service\game\ConfigService;
use plugin\saiadmin\basic\BaseController;
use support\Request;
use support\Response;

class ContextController extends BaseController
{
    public function index(Request $request): Response
    {
        $scope = EnterpriseScope::current((int) $this->adminInfo['id']);
        $ids = EnterpriseScope::merchantIds((int) $this->adminInfo['id']);
        $merchants = Merchant::with('enterprise:id,name')->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'enterprise_id', 'mch_id', 'name', 'timezone', 'status']);
        $selected = $merchants->firstWhere('id', (int) $request->header('X-Merchant-Id')) ?: ($scope ? $merchants->first() : null);
        $platformTimezone = (new ConfigService())->get('platform_timezone', 'UTC');
        return $this->success([
            'role' => !$scope ? 'super_admin' : ($scope->is_owner ? 'enterprise_owner' : 'enterprise_staff'),
            'role_name' => !$scope ? '平台超管' : ($scope->is_owner ? '企业负责人' : '企业子账号'),
            'merchant_id' => $selected?->id ?: 0,
            'timezone' => $selected?->timezone ?: $platformTimezone,
            'server_time' => time(),
            'merchants' => $merchants->map(fn ($merchant) => [
                'id' => $merchant->id, 'timezone' => $merchant->timezone,
                'label' => ($scope ? '' : $merchant->enterprise->name . ' / ') . $merchant->name . ' (' . $merchant->mch_id . ')',
            ])->values(),
        ]);
    }
}
