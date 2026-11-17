<?php

use App\Portals\AssetManager\AssetManagerCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $portal = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if (! $portal) {
            throw new RuntimeException(__('asset_manager.missing_portal', [], 'zh-CN'));
        }
        foreach (AssetManagerCatalog::MODELS as $kind => $class) {
            $table = (new $class)->getTable();
            if (! $schema->hasTable($table)) {
                $schema->create($table, function (Blueprint $t) use ($kind) {
                    $t->id();
                    foreach (AssetManagerCatalog::fields($kind) as $key => $type) {
                        if (in_array($type, ['user', 'department'])) {
                            $t->unsignedBigInteger($key)->nullable()->index();
                        } elseif ($type === 'money') {
                            $t->decimal($key, 18, 2)->nullable();
                        } elseif ($type === 'date') {
                            $t->date($key)->nullable()->index();
                        } else {
                            $t->text($key)->nullable();
                        }
                    } $t->string('user_snapshot')->nullable();
                    $t->string('department_snapshot')->nullable();
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->timestamps();
                    $t->index('created_at');
                });
            }
        }
        if (! $schema->hasTable('asset_ipfoxy_accounts')) {
            $schema->create('asset_ipfoxy_accounts', function (Blueprint $t) {
                $t->id();
                $t->string('label')->unique();
                $t->string('api_id')->unique();
                $t->text('api_token');
                $t->timestamp('last_synced_at')->nullable();
                $t->string('last_status')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('asset_ipfoxy_proxies')) {
            $schema->create('asset_ipfoxy_proxies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->index();
                $t->string('proxy_id');
                $t->string('host')->nullable()->index();
                $t->string('public_ip')->nullable();
                $t->string('port', 20)->nullable();
                $t->string('protocol', 30)->nullable();
                $t->string('country', 20)->nullable();
                $t->timestamp('expires_at')->nullable()->index();
                $t->boolean('missing')->default(false);
                $t->timestamps();
                $t->unique(['account_id', 'proxy_id'], 'asset_ipfoxy_account_proxy_unique');
            });
        }
        foreach (['computers', 'resources', 'codex-renewals', 'ipfoxy'] as $i => $kind) {
            $slug = AssetManagerCatalog::permission($kind);
            foreach ([$slug, $slug.'.manage'] as $permission) {
                if (! $db->table(config('admin.database.permissions_table'))->where('slug', $permission)->exists()) {
                    $db->table(config('admin.database.permissions_table'))->insert(['slug' => $permission, 'name' => __('asset_manager.titles.'.$kind, [], 'zh-CN').(str_ends_with($permission, '.manage') ? ' / '.__('asset_manager.manage', [], 'zh-CN') : ''), 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if (! $db->table('portal_menus')->where('portal_id', $portal)->where('code', 'asset_manager_'.$kind)->exists()) {
                $db->table('portal_menus')->insert(['portal_id' => $portal, 'parent_id' => 0, 'code' => 'asset_manager_'.$kind, 'title' => null, 'title_key' => 'asset_manager.titles.'.$kind, 'path' => '/asset-manager/'.$kind, 'permission_slug' => $slug, 'icon' => 'fa-cubes', 'sort' => ($i + 1) * 10, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $id = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if ($id) {
            $db->table('portal_menus')->where('portal_id', $id)->where('code','like','asset_manager_%')->update(['is_enabled' => false]);
        }
    }
};
