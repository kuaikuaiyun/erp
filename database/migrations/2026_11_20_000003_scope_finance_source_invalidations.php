<?php

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
        if (! $schema->hasTable(FinanceSummarySourceChanges::TABLE)) $schema->create(FinanceSummarySourceChanges::TABLE, function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('channel', 12);
            $table->string('source_date', 10);
            $table->unsignedBigInteger('revision');
            $table->primary(['channel', 'source_date']);
        });
        if (! $schema->hasColumn('finance_summary_changes', 'scopes_resolved')) $schema->table('finance_summary_changes', function (Blueprint $table): void {
            $table->boolean('scopes_resolved')->default(false)->index();
        });
        FinanceSummarySourceChanges::install($db);
        // Prior publications could miss a refund posted in a later month.
        $db->table('finance_summary_periods')->increment('revision');
        // Re-installing incomplete tracking must also invalidate files created
        // before the gap. Use the outbox sequence without leaving a reset event
        // for an older worker to consume during deployment.
        $db->transaction(function () use ($db): void {
            $revision = $db->table('finance_summary_changes')->insertGetId(['source' => 'scope_reset', 'payload' => '{}', 'scopes_resolved' => true]);
            foreach (['paypal', 'credit_card'] as $channel) {
                if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $db->statement('INSERT INTO '.FinanceSummarySourceChanges::TABLE.' (channel, source_date, revision) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE revision = GREATEST(revision, VALUES(revision))', [$channel, '*', $revision]);
                } else {
                    $db->table(FinanceSummarySourceChanges::TABLE)->updateOrInsert(['channel' => $channel, 'source_date' => '*'], ['revision' => $revision]);
                }
            }
            $db->table('finance_summary_changes')->where('id', $revision)->delete();
        });
    }

    public function down(): void
    {
        // Keep revision evidence and guards: rolling back must not silently enable stale reuse.
    }
};
