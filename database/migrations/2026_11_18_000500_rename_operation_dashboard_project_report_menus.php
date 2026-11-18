<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'yunying-kanban';

    public function up(): void
    {
        $this->renameMenus([
            'dashboard_operation_reports' => '订阅项目报表',
            'dashboard_fashion_operation_reports' => '单包服装项目报表',
            'dashboard_fashion_applovin_performance' => '单包服装 AppLovin 业绩报表',
        ]);
    }

    public function down(): void
    {
        $this->renameMenus([
            'dashboard_operation_reports' => 'Shopify运营报表',
            'dashboard_fashion_operation_reports' => '服装运营报表',
            'dashboard_fashion_applovin_performance' => '服装 AppLovin 业绩报表',
        ]);
    }

    /** @param array<string, string> $titles */
    private function renameMenus(array $titles): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) {
            return;
        }

        foreach ($titles as $code => $title) {
            $db->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $code)
                ->update([
                    'title' => $title,
                    'title_key' => '',
                    'updated_at' => now(),
                ]);
        }
    }
};
