<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('logistics_paypal_disputes')) {
            $columns = ['subscription_cancelled', 'add_on_cancelled', 'first_handled_at', 'first_handled_by', 'first_handled_by_name'];
            $missing = array_filter($columns, fn (string $column): bool => ! $schema->hasColumn('logistics_paypal_disputes', $column));
            if ($missing !== []) $schema->table('logistics_paypal_disputes', function (Blueprint $table) use ($missing): void {
                if (in_array('subscription_cancelled', $missing, true)) $table->boolean('subscription_cancelled')->nullable()->index();
                if (in_array('add_on_cancelled', $missing, true)) $table->boolean('add_on_cancelled')->nullable()->index();
                if (in_array('first_handled_at', $missing, true)) $table->dateTime('first_handled_at')->nullable()->index();
                if (in_array('first_handled_by', $missing, true)) $table->unsignedInteger('first_handled_by')->nullable();
                if (in_array('first_handled_by_name', $missing, true)) $table->string('first_handled_by_name', 100)->nullable();
            });
        }
        if (! $schema->hasTable('logistics_paypal_dispute_operations')) $schema->create('logistics_paypal_dispute_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logistics_paypal_dispute_id');
            $table->unsignedInteger('operator_id')->nullable();
            $table->string('operator_name', 100)->nullable();
            $table->string('action', 50);
            $table->longText('payload')->nullable();
            $table->timestamps();
            $table->index('logistics_paypal_dispute_id', 'ppdo_dispute_id_idx');
            $table->index('operator_id', 'ppdo_operator_id_idx');
        });
        if ($schema->hasTable('logistics_paypal_dispute_operations') && ! $schema->hasIndex('logistics_paypal_dispute_operations', 'ppdo_dispute_id_idx')) {
            $schema->table('logistics_paypal_dispute_operations', fn (Blueprint $table) => $table->index('logistics_paypal_dispute_id', 'ppdo_dispute_id_idx'));
        }
        if ($schema->hasTable('logistics_paypal_dispute_operations') && ! $schema->hasIndex('logistics_paypal_dispute_operations', 'ppdo_operator_id_idx')) {
            $schema->table('logistics_paypal_dispute_operations', fn (Blueprint $table) => $table->index('operator_id', 'ppdo_operator_id_idx'));
        }
        $this->installMenu($connection);
    }

    public function down(): void { /* 人工处理审计记录不随代码回滚删除。 */ }

    private function installMenu(?string $connection): void
    {
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'wuliu')->value('id');
        $parentId = $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'logistics_reports')->value('id');
        if (! $portalId || ! $parentId) return;
        $values = ['parent_id' => $parentId, 'title' => 'Paypal争议分析', 'title_key' => 'logistics.paypal_dispute_analysis.menu', 'path' => '/paypal-dispute-analysis', 'icon' => 'fa-exclamation-circle', 'sort' => 25, 'is_enabled' => true, 'updated_at' => now()];
        $existing = $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'logistics_paypal_dispute_analysis')->value('id');
        if ($existing) $database->table('portal_menus')->where('id', $existing)->update($values);
        else $database->table('portal_menus')->insert(['portal_id' => $portalId, 'code' => 'logistics_paypal_dispute_analysis', ...$values, 'created_at' => now()]);
    }
};
