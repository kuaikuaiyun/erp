<?php

use App\Portals\Finance\FinanceApplicationChanges;
use App\Portals\Finance\FinanceSummarySourceChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable(FinanceApplicationChanges::TABLE)) $schema->create(FinanceApplicationChanges::TABLE, function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->unsignedTinyInteger('id')->primary(); $table->string('mode', 20);
        });
        if ($db->table(FinanceApplicationChanges::TABLE)->where('id', 1)->value('mode') === 'application') return;
        $db->transaction(function () use ($db): void {
            $epoch = $db->table('finance_summary_changes')->insertGetId(['source' => 'app_tracking_init', 'payload' => '{}', 'scopes_resolved' => true]);
            foreach (['paypal', 'credit_card'] as $channel) {
                $db->table(FinanceSummarySourceChanges::TABLE)->updateOrInsert(['channel' => $channel, 'source_date' => '*'], ['revision' => $epoch]);
            }
            $db->table('finance_summary_periods')->increment('revision');
            $db->table(FinanceApplicationChanges::TABLE)->updateOrInsert(['id' => 1], ['mode' => 'application']);
        });
    }

    public function down(): void {}
};
