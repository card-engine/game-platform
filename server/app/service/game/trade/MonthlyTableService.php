<?php

namespace app\service\game\trade;

use InvalidArgumentException;
use RuntimeException;
use support\Db;

class MonthlyTableService
{
    public function table(string $type, ?string $month = null): string
    {
        $month ??= gmdate('ym');
        if (!in_array($type, ['bets', 'bills'], true) || preg_match('/^\d{4}$/', $month) !== 1) {
            throw new InvalidArgumentException('月表参数无效');
        }
        $table = "mg_{$type}_{$month}";
        if (!Db::connection()->getSchemaBuilder()->hasTable($table)) $this->create($type, $month);
        return $table;
    }

    public function precreate(): array
    {
        $tables = [];
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 3; $i++) {
            $suffix = $month->modify("+{$i} month")->format('ym');
            $tables[] = $this->table('bets', $suffix);
            $tables[] = $this->table('bills', $suffix);
        }
        return $tables;
    }

    private function create(string $type, string $month): void
    {
        $lock = "mg_{$type}_{$month}";
        $acquired = (int) (array_values((array) Db::selectOne('SELECT GET_LOCK(?, 5) AS locked', [$lock]))[0] ?? 0);
        if ($acquired !== 1) throw new RuntimeException('月表创建锁获取失败');
        try {
            Db::statement("CREATE TABLE IF NOT EXISTS `mg_{$type}_{$month}` LIKE `mg_{$type}_template`");
        } finally {
            Db::selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
