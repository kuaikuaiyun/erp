<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'zhuli';

    private const MENU_CODE = 'assistant_domain_stores';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')) {
            $schema->create('assistant_domain_stores', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('site_builder', 10)->nullable();
                $table->string('shop_name', 100);
                $table->string('domain', 191);
                $table->date('domain_expires_at')->nullable();
                $table->string('domain_check_status', 20)->default('no');
                $table->string('email', 191)->nullable();
                $table->boolean('email_bound')->default(false);
                $table->boolean('email_purchased')->default(false);
                $table->string('team', 100)->nullable();
                $table->string('project', 100)->nullable();
                $table->string('user_name', 100)->nullable();
                $table->string('erp', 10)->nullable();
                $table->string('credit_card', 100)->nullable();
                $table->string('wallet', 100)->nullable();
                $table->string('paypal', 100)->nullable();
                $table->string('other_payment', 100)->nullable();
                $table->text('shop_login_url')->nullable();
                $table->string('shop_status', 20)->default('in_use');
                $table->date('plan_expires_at')->nullable();
                $table->string('payment_card', 255)->nullable();
                $table->text('remark')->nullable();
                $table->unsignedInteger('last_operator_id')->nullable();
                $table->string('last_operator_name', 100)->nullable();
                $table->timestamps();

                $table->index('domain', 'assistant_store_domain_index');
                $table->index('shop_status', 'assistant_store_status_index');
                $table->index('domain_check_status', 'assistant_store_domain_check_index');
                $table->index('team', 'assistant_store_team_index');
                $table->index('updated_at', 'assistant_store_updated_index');
            });
        }

        if (!$schema->hasTable('assistant_domain_store_change_logs')) {
            $schema->create('assistant_domain_store_change_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('domain_store_id');
                $table->string('event', 20);
                $table->longText('changes')->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name_snapshot', 100)->nullable();
                $table->timestamps();

                $table->index(['domain_store_id', 'created_at'], 'assistant_store_history_store_index');
                $table->index(['actor_id', 'created_at'], 'assistant_store_history_actor_index');
            });
        }

        $this->installPortalMenu($connection);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if ($schema->hasTable('portal_menus') && $schema->hasTable('portals')) {
            $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');

            if ($portalId) {
                $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', self::MENU_CODE)
                    ->delete();
            }
        }

        $schema->dropIfExists('assistant_domain_store_change_logs');
        $schema->dropIfExists('assistant_domain_stores');
    }

    private function installPortalMenu(?string $connection): void
    {
        $database = DB::connection($connection);

        if (!Schema::connection($connection)->hasTable('portals')
            || !Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        $portalId = $database->table('portals')
            ->where('code', self::PORTAL_CODE)
            ->value('id');

        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $values = [
            'parent_id' => 0,
            'title' => '域名与店铺信息管理',
            'title_key' => 'assistant.domain_stores.menu',
            'path' => '/domain-stores',
            'icon' => 'fa-globe',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ];
        $menu = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', self::MENU_CODE)
            ->first(['id']);

        if ($menu) {
            $database->table('portal_menus')->where('id', $menu->id)->update($values);

            return;
        }

        $database->table('portal_menus')->insert([
            'portal_id' => $portalId,
            'code' => self::MENU_CODE,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }
};

