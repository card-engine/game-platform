<?php

namespace app\service\mgs;

use InvalidArgumentException;
use RuntimeException;
use support\Db;

class MgsTableService
{
    public function table(string $type, ?string $month = null): string
    {
        $month ??= gmdate('ym');
        if (!in_array($type, ['bets', 'bills'], true) || preg_match('/^\d{4}$/', $month) !== 1) throw new InvalidArgumentException('MGS 月表参数无效');
        $table = "mgs_{$type}_{$month}";
        if (!Db::connection()->getSchemaBuilder()->hasTable($table)) {
            $lock = "mgs_{$type}_{$month}";
            $locked = (int) (array_values((array) Db::selectOne('SELECT GET_LOCK(?, 5) AS locked', [$lock]))[0] ?? 0);
            if ($locked !== 1) throw new RuntimeException('MGS 月表创建锁获取失败');
            try {
                Db::statement("CREATE TABLE IF NOT EXISTS `{$table}` LIKE `mgs_{$type}_template`");
            } finally {
                Db::selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        return $table;
    }

    public function recent(): array
    {
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        $tables = [];
        for ($i = 0; $i < 3; $i++) {
            $suffix = $month->modify("-{$i} month")->format('ym');
            $tables[] = $this->table('bets', $suffix);
            $tables[] = $this->table('bills', $suffix);
        }
        return $tables;
    }
}
