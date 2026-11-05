<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'fuzhuang';
    private const PERMISSION = 'portal.fuzhuang.advertising_expenses';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('fashion_advertising_reports')) {
            $schema->create('fashion_advertising_reports', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->date('report_date')->index();
                $table->unsignedInteger('submitter_id')->index();
                $table->string('submitter_name', 100);
                $table->decimal('total_revenue_usd', 18, 2)->default(0);
                $table->unsignedInteger('total_order_count')->default(0);
                $table->decimal('total_ad_spend_usd', 18, 2)->default(0);
                $table->decimal('roi', 18, 4)->default(0);
                $table->timestamps();
                $table->unique(['report_date', 'submitter_id'], 'fashion_ad_report_date_user_unique');
            });
        }

        if (!$schema->hasTable('fashion_performance_entries')) {
            $schema->create('fashion_performance_entries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('fashion_advertising_report_id')->index();
                $table->string('store_name', 191);
                $table->string('domain', 255)->nullable();
                $table->decimal('revenue_usd', 18, 2)->default(0);
                $table->unsignedInteger('order_count')->default(0);
                $table->string('screenshot_path', 500)->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('fashion_ad_spend_entries')) {
            $schema->create('fashion_ad_spend_entries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('fashion_advertising_report_id')->index();
                $table->string('agency', 100);
                $table->string('account_name', 191);
                $table->string('account_number', 191);
                $table->decimal('book_spend_usd', 18, 2)->default(0);
                $table->decimal('actual_spend_usd', 18, 2)->default(0);
                $table->string('screenshot_path', 500)->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $now = now();
        $portal = $db->table('portals')->where('code', self::PORTAL_CODE)->first(['id']);
        $portalValues = [
            'name' => '服装运营系统',
            'remark' => '服装业务运营数据及广告费用管理',
            'status' => 'enabled',
            'updated_at' => $now,
        ];
        if ($portal) {
            $db->table('portals')->where('id', $portal->id)->update($portalValues);
            $portalId = (int) $portal->id;
        } else {
            $portalId = (int) $db->table('portals')->insertGetId([
                'code' => self::PORTAL_CODE,
                ...$portalValues,
                'created_at' => $now,
            ]);
        }

        $permissionId = null;
        $permissionsTable = config('admin.database.permissions_table');
        if ($schema->hasTable($permissionsTable)) {
            $permission = $db->table($permissionsTable)->where('slug', self::PERMISSION)->first(['id']);
            $values = [
                'name' => '[服装运营系统] 广告费填写',
                'http_method' => 'GET,POST,PUT,DELETE',
                'http_path' => "/fashion/advertising-expenses\r\n/fashion/advertising-expenses/*",
                'updated_at' => $now,
            ];
            if ($permission) {
                $db->table($permissionsTable)->where('id', $permission->id)->update($values);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) $db->table($permissionsTable)->insertGetId([
                    'slug' => self::PERMISSION,
                    ...$values,
                    'created_at' => $now,
                ]);
            }
        }

        $menuValues = [
            'parent_id' => 0,
            'title' => '广告费填写',
            'title_key' => 'fashion.advertising_expenses.menu',
            'path' => '/fashion/advertising-expenses',
            'permission_slug' => self::PERMISSION,
            'icon' => 'fa-line-chart',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $now,
        ];
        $menu = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_advertising_expenses')->first(['id']);
        if ($menu) {
            $db->table('portal_menus')->where('id', $menu->id)->update($menuValues);
        } else {
            $db->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => 'fashion_advertising_expenses',
                ...$menuValues,
                'created_at' => $now,
            ]);
        }

        if ($permissionId) {
            $administratorId = $db->table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id');
            $pivot = config('admin.database.role_permissions_table');
            if ($administratorId && !$db->table($pivot)->where('role_id', $administratorId)->where('permission_id', $permissionId)->exists()) {
                $db->table($pivot)->insert(['role_id' => $administratorId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // 业务数据不自动删除，避免回滚误删已填写报表。
    }
};
