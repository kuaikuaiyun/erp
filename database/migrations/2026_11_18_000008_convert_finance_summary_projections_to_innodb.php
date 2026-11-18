<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_summary_rows') || ! $schema->hasTable('finance_summary_periods')) {
            return;
        }

        // Projection rows are derived cache data. Emptying them makes the engine
        // and primary-key conversion short and avoids locking a disposable table.
        $db->table('finance_summary_rows')->truncate();
        $db->table('finance_summary_periods')->update(['published' => 0]);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $rows = $db->getQueryGrammar()->wrapTable('finance_summary_rows');
        $periods = $db->getQueryGrammar()->wrapTable('finance_summary_periods');
        if (! $schema->hasColumn('finance_summary_rows', 'timezone')) {
            $db->statement("ALTER TABLE {$rows}
                ENGINE=InnoDB,
                ADD COLUMN `timezone` VARCHAR(32) NOT NULL DEFAULT 'Asia/Shanghai' AFTER `channel`,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (`month`,`channel`,`timezone`,`revision`,`transactions`,`position`),
                DROP INDEX `finance_summary_account_idx`,
                ADD INDEX `finance_summary_account_idx` (`month`,`channel`,`timezone`,`revision`,`transactions`,`account`),
                DROP INDEX `finance_summary_currency_idx`,
                ADD INDEX `finance_summary_currency_idx` (`month`,`channel`,`timezone`,`revision`,`transactions`,`currency`)");
        } else {
            $db->statement("ALTER TABLE {$rows} ENGINE=InnoDB");
        }
        $db->statement("ALTER TABLE {$periods} ENGINE=InnoDB");
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_summary_rows') || ! $schema->hasColumn('finance_summary_rows', 'timezone')) {
            return;
        }
        $db->table('finance_summary_rows')->truncate();
        $db->table('finance_summary_periods')->update(['published' => 0]);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $rows = $db->getQueryGrammar()->wrapTable('finance_summary_rows');
        $db->statement("ALTER TABLE {$rows}
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (`month`,`channel`,`revision`,`transactions`,`position`),
            DROP INDEX `finance_summary_account_idx`,
            ADD INDEX `finance_summary_account_idx` (`month`,`channel`,`revision`,`transactions`,`account`),
            DROP INDEX `finance_summary_currency_idx`,
            ADD INDEX `finance_summary_currency_idx` (`month`,`channel`,`revision`,`transactions`,`currency`),
            DROP COLUMN `timezone`");
    }
};
