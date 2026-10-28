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
        $database = DB::connection($connection);

        if (! $schema->hasTable('assistant_advertising_media')) {
            $schema->create('assistant_advertising_media', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 50)->unique();
                $table->boolean('is_enabled')->default(true)->index();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if ($schema->hasTable('portals') && $schema->hasTable('portal_menus')) {
            $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
            if ($portalId) {
                $database->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => 'assistant_advertising_info'],
                    ['parent_id' => 0, 'title' => '广告信息', 'title_key' => 'assistant.advertising_info.menu', 'path' => '#', 'icon' => 'fa-bullhorn', 'sort' => 30, 'is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                );
                $parentId = (int) $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', 'assistant_advertising_info')
                    ->value('id');
                $database->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => 'assistant_advertising_media'],
                    ['parent_id' => $parentId, 'title' => '广告社媒', 'title_key' => 'assistant.advertising_media.menu', 'path' => '/advertising-media', 'icon' => 'fa-circle-o', 'sort' => 10, 'is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (Schema::connection($connection)->hasTable('portals')) {
            $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
            if ($portalId) {
                $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'assistant_advertising_media')->delete();
                $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'assistant_advertising_info')->delete();
            }
        }
        Schema::connection($connection)->dropIfExists('assistant_advertising_media');
    }
};
