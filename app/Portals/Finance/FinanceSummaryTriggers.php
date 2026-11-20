<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

class FinanceSummaryTriggers
{
    public static function available(Connection $db): bool
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $names = [];
        foreach (['record', 'supplement', 'payment', 'ledger', 'account', 'type', 'rate', 'statement',
            'dependency_order', 'dependency_reference', 'dependency_item', 'dependency_sku',
            'dependency_fashion', 'dependency_fashion_item', 'dependency_store', 'dependency_owner',
            ...FinanceLiveSourceChanges::ATTRIBUTION_SOURCES] as $source) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $names[] = "finance_summary_{$source}_{$event}";
            }
        }

        return $db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->whereIn('TRIGGER_NAME', $names)->count() === count($names);
    }

    public static function install(Connection $db, string $sql): void
    {
        try {
            $db->unprepared($sql);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1359) return; // Already installed.
            self::throwInstallationError($exception);
        }
    }

    /** Test the actual migration connection before any existing trigger is dropped. */
    public static function assertCanInstall(Connection $db): void
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) return;
        $name = 'finance_install_probe_'.bin2hex(random_bytes(8));
        try {
            // A local variable has no effect on records, revisions, or session state.
            $db->unprepared("CREATE TRIGGER `{$name}` BEFORE INSERT ON finance_records FOR EACH ROW
                BEGIN DECLARE finance_install_probe TINYINT DEFAULT 0; END");
        } catch (QueryException $exception) {
            self::throwInstallationError($exception);
        }
        $db->unprepared("DROP TRIGGER `{$name}`");
    }

    private static function throwInstallationError(QueryException $exception): never
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);
        if (in_array($code, [1142, 1227, 1419], true)) {
            throw new \RuntimeException('财务变更追踪触发器安装失败（MySQL '.$code.'）：当前迁移连接没有所需权限。'
                .'开启 binlog 的数据库还受 log_bin_trust_function_creators 限制。'
                .'请由数据库管理员使用具备创建触发器权限的迁移账号执行迁移；不要忽略此错误或手工标记迁移完成。'
                .'权限修复后执行 php artisan migrate --force，再执行 php artisan finance:summaries:repair-triggers 和 php artisan finance:summaries:status。',
                0, $exception);
        }
        throw $exception;
    }
}
