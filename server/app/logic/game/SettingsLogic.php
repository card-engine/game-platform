<?php

namespace app\logic\game;

use app\model\Config;
use app\model\ExchangeRate;
use app\service\game\ConfigService;
use app\service\game\EnterpriseScope;
use app\service\game\report\ExchangeRateService;
use app\service\game\report\PlatformStatsRebuildService;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class SettingsLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new ExchangeRate();
    }

    public function configs(): array
    {
        $this->superAdmin();
        return Config::orderBy('type')->orderBy('id')->get()->toArray();
    }

    public function save(array $values): bool
    {
        $this->superAdmin();
        if (array_diff(array_keys($values), ['exchange_rate_display_codes'])) throw new ApiException('平台统计时区和币种在首次安装后只读');
        (new \app\validate\game\SettingsValidate())->scene('save')->failException()->check($values);
        Config::where('code', 'exchange_rate_display_codes')->update(['value' => json_encode(array_values(array_unique($values['exchange_rate_display_codes'])))]);
        (new ConfigService())->rebuild();
        return true;
    }

    public function rebuildStatus(): array
    {
        $this->superAdmin();
        return (new PlatformStatsRebuildService())->status();
    }

    public function exchangeRates(array $where): array
    {
        $this->superAdmin();
        $query = ExchangeRate::when($where['date_start'] ?? null, fn ($q, $date) => $q->where('rate_date', '>=', $date))
            ->when($where['date_end'] ?? null, fn ($q, $date) => $q->where('rate_date', '<=', $date));
        return $this->getList($query->orderByDesc('rate_date'));
    }

    public function syncExchangeRate(): array
    {
        $this->superAdmin();
        return (new ExchangeRateService())->sync()->toArray();
    }

    private function superAdmin(): void
    {
        if (!EnterpriseScope::isGameSuperAdmin((int) $this->adminInfo['id'])) throw new ApiException('仅游戏超管可操作');
    }
}
