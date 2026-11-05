<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'yunying';

    private const PARENT_CODE = 'operation_performance_reports';

    private const CHILD_CODE = 'operation_reports';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');

        if (! $portalId) {
            return;
        }

        $database->transaction(function () use ($database, $portalId): void {
            $parentId = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::PARENT_CODE)
                ->value('id');

            $parentValues = [
                'parent_id' => 0,
                'title' => '业绩报表',
                'title_key' => 'operation.performance_reports.menu',
                'path' => '#',
                'icon' => 'fa-line-chart',
                'sort' => 5,
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

            $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::CHILD_CODE)
                ->update([
                    'parent_id' => $parentId,
                    'sort' => 1,
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');

        if (! $portalId) {
            return;
        }

        $database->transaction(function () use ($database, $portalId): void {
            $parentId = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::PARENT_CODE)
                ->value('id');

            if ($parentId) {
                $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', self::CHILD_CODE)
                    ->where('parent_id', $parentId)
                    ->update([
                        'parent_id' => 0,
                        'sort' => 5,
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
