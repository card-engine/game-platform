<?php

namespace app\command;

use app\service\database\SystemDataService;
use PDO;
use RuntimeException;
use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand('db:upgrade', '同步数据库结构和系统内置数据')]
class DbUpgradeCommand extends Command
{
    private PDO $pdo;
    private OutputInterface $output;
    private bool $dryRun = false;
    private int $changes = 0;

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '仅显示目标库差异');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->pdo = Db::connection()->getPdo();
        $this->output = $output;
        $this->dryRun = (bool) $input->getOption('dry-run');
        $target = (string) config('database.connections.mysql.database');
        $prefix = '__mg_schema_' . getmypid() . '_';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $target) || !preg_match('/^__mg_schema_\d+_$/', $prefix)) throw new RuntimeException('数据库配置无效');

        $locked = (int) $this->value('SELECT GET_LOCK(?, 30)', ['mg:db:upgrade']);
        if ($locked !== 1) throw new RuntimeException('数据库升级锁获取失败');

        $result = self::FAILURE;
        $references = [];
        try {
            $this->pdo->exec("USE `{$target}`");
            $schema = (string) file_get_contents(base_path('database/schema.sql'));
            foreach (explode(";\n\n", trim($schema)) as $sql) {
                if (!preg_match('/^CREATE TABLE `([^`]+)`/', $sql, $match)) {
                    $this->pdo->exec($sql . ';');
                    continue;
                }
                $references[$match[1]] = $prefix . $match[1];
                $create = preg_replace('/^CREATE TABLE `[^`]+`/', "CREATE TABLE `{$target}`.`{$references[$match[1]]}`", $sql, 1);
                $this->pdo->exec($create . ';');
            }

            foreach ($references as $table => $reference) $this->syncTable($target, $reference, $target, $table);
            foreach ($this->tables($target) as $table) {
                if (preg_match('/^mg_(bets|bills)_\d{4}$/', $table, $match)) {
                    $this->syncTable($target, $references["mg_{$match[1]}_template"], $target, $table);
                }
            }

            if (!$this->dryRun) (new SystemDataService())->sync();
            $output->writeln(($this->dryRun ? '预计变更：' : '完成变更：') . $this->changes);
            $result = self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
        } finally {
            try {
                foreach (array_reverse($references) as $table) $this->pdo->exec("DROP TABLE IF EXISTS `{$target}`.`{$table}`");
            } catch (Throwable $e) {
                $output->writeln('<error>临时对比表清理失败：' . $e->getMessage() . '</error>');
                $result = self::FAILURE;
            }
            try {
                $this->value('SELECT RELEASE_LOCK(?)', ['mg:db:upgrade']);
            } catch (Throwable $e) {
                $output->writeln('<error>数据库升级锁释放失败：' . $e->getMessage() . '</error>');
                $result = self::FAILURE;
            }
        }
        return $result;
    }

    private function syncTable(string $sourceDb, string $sourceTable, string $targetDb, string $targetTable): void
    {
        if (!$this->tableExists($targetDb, $targetTable)) {
            $row = $this->pdo->query("SHOW CREATE TABLE `{$sourceDb}`.`{$sourceTable}`")->fetch(PDO::FETCH_NUM);
            $sql = preg_replace('/^CREATE TABLE `[^`]+`/', "CREATE TABLE `{$targetDb}`.`{$targetTable}`", (string) $row[1]);
            $this->apply('CREATE TABLE', $targetTable, $sql);
            return;
        }

        $sourceColumns = $this->columns($sourceDb, $sourceTable);
        $targetColumns = $this->columns($targetDb, $targetTable);
        foreach ($sourceColumns as $name => $column) {
            $definition = $this->columnDefinition($column);
            if (!isset($targetColumns[$name])) {
                $this->apply('ADD COLUMN', "{$targetTable}.{$name}", "ALTER TABLE `{$targetDb}`.`{$targetTable}` ADD COLUMN `{$name}` {$definition}");
            } elseif ($this->columnSignature($column) !== $this->columnSignature($targetColumns[$name])) {
                $this->apply('MODIFY COLUMN', "{$targetTable}.{$name}", "ALTER TABLE `{$targetDb}`.`{$targetTable}` MODIFY COLUMN `{$name}` {$definition}");
            }
        }

        $sourceIndexes = $this->indexes($sourceDb, $sourceTable);
        $targetIndexes = $this->indexes($targetDb, $targetTable);
        foreach ($sourceIndexes as $name => $index) {
            $definition = $this->indexDefinition($name, $index);
            if (!isset($targetIndexes[$name])) {
                $this->apply('ADD INDEX', "{$targetTable}.{$name}", "ALTER TABLE `{$targetDb}`.`{$targetTable}` ADD {$definition}");
            } elseif ($index !== $targetIndexes[$name]) {
                $drop = $name === 'PRIMARY' ? 'DROP PRIMARY KEY' : "DROP INDEX `{$name}`";
                $this->apply('REPLACE INDEX', "{$targetTable}.{$name}", "ALTER TABLE `{$targetDb}`.`{$targetTable}` {$drop}, ADD {$definition}");
            }
        }

        $source = $this->tableOptions($sourceDb, $sourceTable);
        $target = $this->tableOptions($targetDb, $targetTable);
        if ($source !== $target) {
            $charset = explode('_', $source['collation'])[0];
            $sql = "ALTER TABLE `{$targetDb}`.`{$targetTable}` ENGINE={$source['engine']} DEFAULT CHARACTER SET {$charset} COLLATE {$source['collation']} ROW_FORMAT={$source['row_format']} COMMENT=" . $this->quote($source['comment']);
            $this->apply('ALTER TABLE', $targetTable, $sql);
        }
    }

    private function tables(string $database): array
    {
        $statement = $this->pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME');
        $statement->execute([$database]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function tableExists(string $database, string $table): bool
    {
        return (bool) $this->value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]);
    }

    private function columns(string $database, string $table): array
    {
        $statement = $this->pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_COMMENT,CHARACTER_SET_NAME,COLLATION_NAME,DATA_TYPE,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$database, $table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[$column['COLUMN_NAME']] = $column;
        return $columns;
    }

    private function columnSignature(array $column): string
    {
        unset($column['COLUMN_NAME']);
        return json_encode($column, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function columnDefinition(array $column): string
    {
        $sql = $column['COLUMN_TYPE'];
        if ($column['CHARACTER_SET_NAME']) $sql .= " CHARACTER SET {$column['CHARACTER_SET_NAME']} COLLATE {$column['COLLATION_NAME']}";
        if ($column['GENERATION_EXPRESSION'] !== '') {
            $storage = str_contains(strtoupper((string) $column['EXTRA']), 'STORED') ? 'STORED' : 'VIRTUAL';
            return $sql . ' GENERATED ALWAYS AS (' . $column['GENERATION_EXPRESSION'] . ") {$storage} COMMENT " . $this->quote((string) $column['COLUMN_COMMENT']);
        }
        $sql .= $column['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL';
        if ($column['COLUMN_DEFAULT'] !== null) {
            $default = (string) $column['COLUMN_DEFAULT'];
            $sql .= ' DEFAULT ' . (preg_match('/^(CURRENT_TIMESTAMP(?:\(\d+\))?|NULL)$/i', $default) ? $default : $this->quote($default));
        }
        $extra = trim(str_ireplace('DEFAULT_GENERATED', '', (string) $column['EXTRA']));
        if ($extra !== '') $sql .= ' ' . $extra;
        return $sql . ' COMMENT ' . $this->quote((string) $column['COLUMN_COMMENT']);
    }

    private function indexes(string $database, string $table): array
    {
        $statement = $this->pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,INDEX_TYPE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$database, $table]);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['INDEX_NAME'];
            $indexes[$name]['unique'] = !(bool) $row['NON_UNIQUE'];
            $indexes[$name]['type'] = $row['INDEX_TYPE'];
            $indexes[$name]['columns'][] = [$row['COLUMN_NAME'], $row['SUB_PART'], $row['COLLATION']];
        }
        return $indexes;
    }

    private function indexDefinition(string $name, array $index): string
    {
        $prefix = match (true) {
            $name === 'PRIMARY' => 'PRIMARY KEY',
            $index['type'] === 'FULLTEXT' => "FULLTEXT KEY `{$name}`",
            $index['type'] === 'SPATIAL' => "SPATIAL KEY `{$name}`",
            $index['unique'] => "UNIQUE KEY `{$name}`",
            default => "KEY `{$name}`",
        };
        $columns = array_map(function (array $column) {
            $sql = "`{$column[0]}`" . ($column[1] ? "({$column[1]})" : '');
            return $sql . ($column[2] === 'D' ? ' DESC' : '');
        }, $index['columns']);
        return $prefix . ' (' . implode(',', $columns) . ')' . (!in_array($index['type'], ['BTREE', 'FULLTEXT', 'SPATIAL'], true) ? " USING {$index['type']}" : '');
    }

    private function tableOptions(string $database, string $table): array
    {
        $statement = $this->pdo->prepare('SELECT ENGINE engine,TABLE_COLLATION collation,TABLE_COMMENT comment,ROW_FORMAT row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $statement->execute([$database, $table]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function value(string $sql, array $bindings): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        return $statement->fetchColumn();
    }

    private function apply(string $action, string $name, string $sql): void
    {
        $this->changes++;
        $this->output->writeln("{$action}: {$name}");
        if (!$this->dryRun) $this->pdo->exec($sql);
    }

    private function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }
}
