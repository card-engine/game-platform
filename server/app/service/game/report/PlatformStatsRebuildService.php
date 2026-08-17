<?php

namespace app\service\game\report;

use app\enum\RedisKey;
use app\service\game\ConfigService;
use DateTimeImmutable;
use DateTimeZone;
use plugin\saiadmin\exception\ApiException;
use support\Db;
use support\Redis as Cache;
use Throwable;
use Webman\RedisQueue\Redis;

class PlatformStatsRebuildService
{
    public function dispatch(array $changes): array
    {
        if (in_array($this->status()['status'], ['queued', 'running'], true)) throw new ApiException('平台统计正在重建，请完成后再修改');
        $status = [
            'id' => bin2hex(random_bytes(12)),
            'status' => 'queued',
            'changes' => array_values($changes),
            'progress' => 0,
            'message' => '平台统计等待重建',
            'update_time' => gmdate('Y-m-d H:i:s'),
        ];
        $this->writeStatus($status);
        try {
            Redis::send('game_platform_stats_rebuild', ['id' => $status['id']]);
        } catch (Throwable $e) {
            $this->writeStatus(array_merge($status, ['status' => 'failed', 'message' => $e->getMessage()]));
            throw $e;
        }
        return $status;
    }

    public function status(): array
    {
        $status = Cache::get(RedisKey::TempPlatformStatsRebuild->value);
        return $status ? json_decode($status, true) : ['status' => 'idle', 'progress' => 100];
    }

    public function rebuild(string $id): void
    {
        $status = $this->status();
        if (($status['id'] ?? null) !== $id || $status['status'] === 'completed') return;
        $status = array_merge($status, ['status' => 'running', 'progress' => 1, 'message' => '正在整理平台日期', 'update_time' => gmdate('Y-m-d H:i:s')]);
        $this->writeStatus($status);

        try {
            $timezone = new DateTimeZone((string) (new ConfigService())->get('platform_timezone', 'UTC'));
            $database = config('database.connections.mysql.database');
            $tables = Db::table('information_schema.tables')->selectRaw('TABLE_NAME as name')->where('table_schema', $database)
                ->whereRaw("table_name REGEXP '^mg_(bets|bills)_[0-9]{4}$'")
                ->orderBy('table_name')->pluck('name')->all();
            $betTables = array_values(array_filter($tables, fn ($table) => str_starts_with($table, 'mg_bets_')));

            if (in_array('platform_timezone', $status['changes'], true)) {
                foreach ($tables as $index => $table) {
                    Db::table($table)->select(['id', 'create_time'])->orderBy('id')->chunkById(1000, function ($rows) use ($table, $timezone) {
                        $idsByDate = [];
                        foreach ($rows as $row) {
                            $date = (new DateTimeImmutable($row->create_time, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
                            $idsByDate[$date][] = $row->id;
                        }
                        foreach ($idsByDate as $date => $ids) Db::table($table)->whereIn('id', $ids)->update(['platform_date' => $date]);
                    });
                    $status = array_merge($status, ['progress' => 5 + (int) ((($index + 1) / max(count($tables), 1)) * 25), 'message' => '正在重算平台日期', 'update_time' => gmdate('Y-m-d H:i:s')]);
                    $this->writeStatus($status);
                }
            }

            $dates = [];
            $hourBuckets = [];
            $monthBuckets = [];
            foreach ($betTables as $table) {
                foreach (Db::table($table)->select(['business_date', 'platform_date', 'create_time'])->whereNull('delete_time')->cursor() as $bet) {
                    $dates[$bet->business_date] = true;
                    $dates[$bet->platform_date] = true;
                    $local = (new DateTimeImmutable($bet->create_time, new DateTimeZone('UTC')))->setTimezone($timezone);
                    $hourBuckets[$local->format('Y-m-d-G')] = [$local->format('Y-m-d'), (int) $local->format('G')];
                    $monthBuckets[$local->format('Y-m')] = true;
                }
            }

            Db::table('mg_daily_stats')->delete();
            foreach (array_keys($dates) as $index => $date) {
                (new DailyStatService())->rebuild($date);
                $status = array_merge($status, ['progress' => 30 + (int) ((($index + 1) / max(count($dates), 1)) * 45), 'message' => '正在重建每日统计', 'update_time' => gmdate('Y-m-d H:i:s')]);
                $this->writeStatus($status);
            }

            Db::table('mg_hourly_stats')->where('merchant_id', 0)->delete();
            Db::table('mg_monthly_stats')->where('merchant_id', 0)->delete();
            $trend = new TrendStatService();
            foreach (array_values($hourBuckets) as [$date, $hour]) $trend->rebuildHour(0, $timezone->getName(), $date, $hour);
            foreach (array_keys($monthBuckets) as $month) $trend->rebuildMonth(0, $timezone->getName(), $month);

            $this->writeStatus(array_merge($status, [
                'status' => 'completed',
                'progress' => 100,
                'message' => '平台统计重建完成',
                'update_time' => gmdate('Y-m-d H:i:s'),
            ]), RedisKey::EXPIRE_1_DAY);
        } catch (Throwable $e) {
            $this->writeStatus(array_merge($status, [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'update_time' => gmdate('Y-m-d H:i:s'),
            ]));
            throw $e;
        }
    }

    private function writeStatus(array $status, int $expire = RedisKey::EXPIRE_2_DAYS): void
    {
        Cache::set(RedisKey::TempPlatformStatsRebuild->value, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'EX', $expire);
    }
}
