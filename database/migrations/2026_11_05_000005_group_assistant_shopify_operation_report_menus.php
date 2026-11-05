<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'zhuli';

    private const PARENT_CODE = 'assistant_shopify_operation_reports';

    /** @var array<string, int> */
    private const CHILDREN = [
        'assistant_operation_reports' => 1,
        'assistant_operation_report_reviews' => 2,
        'assistant_advertising_spend_summary' => 3,
        'assistant_advertising_spend_analysis' => 4,
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) return;

        $database->transaction(function () use ($database, $portalId): void {
            $parentId = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::PARENT_CODE)
                ->value('id');

            $parentValues = [
                'parent_id' => 0,
                'title' => 'Shopify运营报表',
                'title_key' => 'assistant.shopify_operation_reports.menu',
                'path' => '#',
                'icon' => 'fa-bar-chart',
                'sort' => 24,
                'is_enabled' => true,
                'updated_at' => now(),
            ];

            if ($parentId) {
                $database->table('portal_menus')->where('id', $parentId)->update($parentValues);
            } else {
                $parentId = $database->table('portal_menus')->insertGetId([
                    'portal_id' => $portalId,
                    'code' => self::PARENT_CODE,
                    ...$parentValues,
                    'created_at' => now(),
                ]);
            }

            foreach (self::CHILDREN as $code => $sort) {
                $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', $code)
                    ->update([
                        'parent_id' => $parentId,
                        'sort' => $sort,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) return;

        $originalSorts = [
            'assistant_operation_reports' => 24,
            'assistant_operation_report_reviews' => 25,
            'assistant_advertising_spend_summary' => 25,
            'assistant_advertising_spend_analysis' => 26,
        ];

        $database->transaction(function () use ($database, $portalId, $originalSorts): void {
            foreach ($originalSorts as $code => $sort) {
                $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', $code)
                    ->update([
                        'parent_id' => 0,
                        'sort' => $sort,
                        'updated_at' => now(),
                    ]);
            }

            $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::PARENT_CODE)
                ->delete();
        });
    }
};
