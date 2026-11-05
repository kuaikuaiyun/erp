<?php

use App\Portals\Finance\FinanceCatalog;
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
        if (! $schema->hasTable('finance_monthly_exchange_rates')) {
            $schema->create('finance_monthly_exchange_rates', function (Blueprint $table): void {
                $table->id();
                $table->char('month', 7);
                $table->char('currency', 3);
                $table->decimal('cny_rate', 20, 8);
                $table->string('source', 255)->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['month', 'currency']);
            });
        }
        if (! $schema->hasTable('finance_transaction_type_mappings')) {
            $schema->create('finance_transaction_type_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('source_field', 40);
                $table->string('match_type', 20);
                $table->string('match_value', 191);
                $table->string('category', 100);
                $table->string('direction', 20);
                $table->string('loss_subject', 40)->nullable();
                $table->integer('priority')->default(100);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_period_locks')) {
            $schema->create('finance_period_locks', function (Blueprint $table): void {
                $table->id();
                $table->char('month', 7)->unique();
                $table->json('report_snapshot')->nullable();
                $table->unsignedInteger('locked_by')->nullable();
                $table->timestamps();
            });
        }
        if (! $schema->hasColumn('finance_payment_transactions', 'accounting_month')) {
            $schema->table('finance_payment_transactions', function (Blueprint $table): void {
                $table->char('accounting_month', 7)->nullable()->index();
                $table->string('category', 100)->nullable();
                $table->string('direction', 20)->nullable();
                $table->string('loss_subject', 40)->nullable();
                $table->decimal('monthly_rate', 20, 8)->nullable();
                $table->decimal('cny_amount', 36, 8)->nullable();
            });
        }
        $db = DB::connection($connection);
        $portal = $db->table('portals')->where('code', FinanceCatalog::PORTAL_CODE)->first();
        if ($portal && ! $db->table('portal_menus')->where('portal_id', $portal->id)->where('code', 'finance_accounting')->exists()) {
            $db->table('portal_menus')->insert([
                'portal_id' => $portal->id, 'parent_id' => $db->table('portal_menus')->where('portal_id', $portal->id)->where('code', 'finance_settings')->value('id') ?? 0,
                'code' => 'finance_accounting', 'title_key' => 'finance.menus.accounting', 'title' => '会计设置',
                'path' => '/finance/settings/accounting', 'icon' => 'fa-calendar', 'permission_slug' => 'finance.manage',
                'sort' => 150, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve accounting rules, audit fields and locked report snapshots.
    }
};
