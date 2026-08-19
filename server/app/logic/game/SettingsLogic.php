<?php

namespace app\logic\game;

use app\model\Config;
use app\model\ExchangeRate;
use app\service\game\ConfigService;
use app\service\game\EnterpriseScope;
use app\service\game\report\ExchangeRateService;
use app\service\game\report\PlatformStatsRebuildService;
use DateTimeZone;
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
        if (isset($values['platform_timezone']) && !in_array($values['platform_timezone'], DateTimeZone::listIdentifiers(), true)) throw new ApiException('平台时区无效');
        if (isset($values['platform_currency_code']) && preg_match('/^[A-Z]{3,16}$/', $values['platform_currency_code']) !== 1) throw new ApiException('平台统计币种无效');
        $current = (new ConfigService())->all();
        $changes = array_values(array_filter(['platform_timezone', 'platform_currency_code'], fn ($code) => isset($values[$code]) && $values[$code] !== ($current[$code] ?? null)));
        $rebuild = new PlatformStatsRebuildService();
        $status = $rebuild->status();
        if ($changes && in_array($status['status'], ['queued', 'running'], true)) throw new ApiException('平台统计正在重建，请完成后再修改');
        if (!$changes && $status['status'] === 'failed') $changes = array_values(array_intersect($status['changes'] ?? [], ['platform_timezone', 'platform_currency_code']));
        $this->transaction(function () use ($values) {
            foreach ($values as $code => $value) Config::where('code', $code)->update(['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });
        (new ConfigService())->rebuild();
        if ($changes) $rebuild->dispatch($changes);
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
