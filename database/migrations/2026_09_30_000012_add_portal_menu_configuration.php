<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portal_menus')) {
            return;
        }

        if (!$schema->hasColumn('portal_menus', 'title')) {
            $schema->table('portal_menus', function ($table): void {
                $table->string('title', 191)->nullable();
            });
        }

        $database = DB::connection($connection);
        $timestamp = now();

        $database->table('portals')
            ->orderBy('id')
            ->each(function (object $portal) use ($database, $timestamp): void {
                $management = $database->table('portal_menus')
                    ->where('portal_id', $portal->id)
                    ->where('code', 'backend_management')
                    ->first(['id']);

                if (!$management) {
                    $managementId = $database->table('portal_menus')->insertGetId([
                        'portal_id' => $portal->id,
                        'parent_id' => 0,
                        'code' => 'backend_management',
                        'title' => null,
                        'title_key' => 'portal.menu.backend_management',
                        'path' => '#',
                        'icon' => 'fa-cogs',
                        'sort' => 900,
                        'is_enabled' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                } else {
                    $managementId = (int) $management->id;
                }

                $configuration = $database->table('portal_menus')
                    ->where('portal_id', $portal->id)
                    ->where('code', 'menu_configuration')
                    ->exists();

                if ($configuration) {
                    return;
                }

                $database->table('portal_menus')->insert([
                    'portal_id' => $portal->id,
                    'parent_id' => $managementId,
                    'code' => 'menu_configuration',
                    'title' => null,
                    'title_key' => 'portal.menu.configuration',
                    'path' => '/management/menus',
                    'icon' => 'fa-list',
                    'sort' => 1,
                    'is_enabled' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
    }

    /**
     * Portal menus are business configuration and are intentionally retained.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};


