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
        $db = DB::connection($connection);

        if (! $schema->hasTable('finance_transaction_types')) {
            $schema->create('finance_transaction_types', function (Blueprint $table): void {
                $table->id();
                $table->string('raw_type', 191)->unique();
                $table->string('category', 100)->nullable()->index();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_domain_groups')) {
            $schema->create('finance_domain_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 191)->unique();
                $table->string('owner', 100);
                $table->string('team', 100)->index();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_records')) {
            $schema->create('finance_records', function (Blueprint $table): void {
                $table->id();
                $table->string('dataset', 40)->index();
                $table->string('account', 191);
                $table->string('reference', 191)->index();
                $table->dateTime('occurred_at')->index();
                $table->string('source_timezone', 40);
                $table->string('domain', 191)->nullable()->index();
                $table->string('raw_type', 191)->nullable();
                $table->string('currency', 10)->nullable();
                $table->string('status', 100)->nullable();
                $table->char('fingerprint', 64)->unique();
                $table->json('payload');
                $table->unsignedInteger('imported_by')->nullable();
                $table->timestamps();
            });
        }

        $db->transaction(function () use ($db): void {
            $now = now();
            foreach (['types' => 'finance_transaction_types'] as $source => $table) {
                foreach (FinanceCatalog::source()[$source] as $row) {
                    $key = $source === 'types' ? 'raw_type' : 'domain';
                    if (! $db->table($table)->where($key, $row[$key])->exists()) {
                        $db->table($table)->insert([...$row, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
            foreach (['view', 'manage'] as $ability) {
                $db->table(config('admin.database.permissions_table'))->updateOrInsert(
                    ['slug' => 'finance.'.$ability],
                    ['name' => trans('finance.permissions.'.$ability, [], 'zh-CN'), 'http_method' => '', 'http_path' => '', 'updated_at' => $now],
                );
            }
            $portalId = $db->table('portals')->where('code', FinanceCatalog::PORTAL_CODE)->value('id');
            if (! $portalId) {
                $portalId = $db->table('portals')->insertGetId([
                    'code' => FinanceCatalog::PORTAL_CODE,
                    'name' => trans('finance.name', [], 'zh-CN'),
                    'remark' => trans('finance.remark', [], 'zh-CN'),
                    'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $ids = [];
            foreach (FinanceCatalog::menus() as $sort => [$code, $parent, $path, $icon]) {
                $key = ['portal_id' => $portalId, 'code' => 'finance_'.$code];
                $id = $db->table('portal_menus')->where($key)->value('id');
                if (! $id) {
                    $id = $db->table('portal_menus')->insertGetId([
                        ...$key, 'parent_id' => $parent ? $ids[$parent] : 0,
                        'title' => trans('finance.menus.'.$code, [], 'zh-CN'),
                        'title_key' => 'finance.menus.'.$code, 'path' => $path, 'icon' => $icon,
                        'permission_slug' => 'finance.view', 'sort' => ($sort + 1) * 10,
                        'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $ids[$code] = $id;
            }
            $managementId = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'backend_management')->value('id');
            if (! $managementId) {
                $managementId = $db->table('portal_menus')->insertGetId([
                    'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'backend_management',
                    'title_key' => 'portal.menu.backend_management', 'path' => '#', 'icon' => 'fa-cogs',
                    'sort' => 900, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if (! $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'menu_configuration')->exists()) {
                $db->table('portal_menus')->insert([
                    'portal_id' => $portalId, 'parent_id' => $managementId, 'code' => 'menu_configuration',
                    'title_key' => 'portal.menu.configuration', 'path' => '/management/menus', 'icon' => 'fa-list',
                    'sort' => 1, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve imported financial records, administrator menu changes and grants.
    }
};
