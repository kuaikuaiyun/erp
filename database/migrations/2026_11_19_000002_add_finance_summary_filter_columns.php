<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = ['payment_channel', 'owner', 'team', 'summary_team', 'subscription', 'payment_method'];

    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_summary_rows') || $schema->hasColumn('finance_summary_rows', 'payment_channel')) {
            return;
        }

        $schema->table('finance_summary_rows', function (Blueprint $table): void {
            $table->string('payment_channel', 20)->nullable()->after('currency');
            $table->string('owner', 191)->nullable()->after('payment_channel');
            $table->string('team', 100)->nullable()->after('owner');
            $table->string('summary_team', 100)->nullable()->after('team');
            $table->string('subscription', 100)->nullable()->after('summary_team');
            $table->string('payment_method', 100)->nullable()->after('subscription');
        });

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table = $db->getQueryGrammar()->wrapTable('finance_summary_rows');
            $payload = $db->getQueryGrammar()->wrap('payload');
            $assignments = [];
            foreach (['payment_channel' => 'payment_channel', 'owner' => 'owner', 'team' => 'team',
                'summary_team' => '_summary_team', 'subscription' => 'subscription', 'payment_method' => 'payment_method'] as $column => $field) {
                $assignments[] = "`{$column}` = NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$payload}, '$.{$field}')), 'null')";
            }
            $db->statement("UPDATE {$table} SET ".implode(', ', $assignments));
        } else {
            foreach ($db->table('finance_summary_rows')->orderBy('month')->orderBy('channel')->orderBy('position')->cursor() as $row) {
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                $db->table('finance_summary_rows')->where([
                    'month' => $row->month, 'channel' => $row->channel, 'timezone' => $row->timezone,
                    'revision' => $row->revision, 'transactions' => $row->transactions, 'position' => $row->position,
                ])->update([
                    'payment_channel' => $payload['payment_channel'] ?? null,
                    'owner' => $payload['owner'] ?? null,
                    'team' => $payload['team'] ?? null,
                    'summary_team' => $payload['_summary_team'] ?? null,
                    'subscription' => $payload['subscription'] ?? null,
                    'payment_method' => $payload['payment_method'] ?? null,
                ]);
            }
        }

        $schema->table('finance_summary_rows', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->index(['month', 'channel', 'timezone', 'revision', 'transactions', $column],
                    'finance_summary_'.$column.'_idx');
            }
        });
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_summary_rows') || ! $schema->hasColumn('finance_summary_rows', 'payment_channel')) {
            return;
        }

        $schema->table('finance_summary_rows', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->dropIndex('finance_summary_'.$column.'_idx');
            }
            $table->dropColumn(self::COLUMNS);
        });
    }
};
