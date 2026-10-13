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

        if (!$schema->hasTable('logistics_paypal_accounts')) {
            $schema->create('logistics_paypal_accounts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('account_name', 191);
                $table->string('merchant_id', 100)->nullable()->unique();
                $table->string('account_email', 191)->nullable()->unique();
                $table->string('client_id', 255);
                $table->text('client_secret');
                $table->string('environment', 20)->default('live')->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->longText('access_token')->nullable();
                $table->dateTime('token_expires_at')->nullable();
                $table->text('granted_scopes')->nullable();
                $table->dateTime('last_sync_started_at')->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->dateTime('last_sync_from')->nullable();
                $table->dateTime('last_sync_to')->nullable();
                $table->string('last_sync_status', 30)->nullable()->index();
                $table->text('last_sync_message')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->string('created_by_name', 100)->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->string('updated_by_name', 100)->nullable();
                $table->timestamps();
            });
        }

        if ($schema->hasTable('logistics_orders') && !$schema->hasColumn('logistics_orders', 'paypal_account_id')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('paypal_account_id')->nullable()->index();
            });
        }

        if (!$schema->hasTable('logistics_paypal_refunds')) {
            $schema->create('logistics_paypal_refunds', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_account_id')->index();
                $table->unsignedBigInteger('logistics_order_id')->nullable()->index();
                $table->string('refund_transaction_id', 100);
                $table->string('original_transaction_id', 100)->nullable()->index();
                $table->string('event_code', 20)->nullable();
                $table->string('status', 50)->nullable();
                $table->decimal('refund_amount_usd', 18, 4)->nullable();
                $table->decimal('original_amount', 18, 4)->nullable();
                $table->string('currency_code', 10)->nullable();
                $table->string('invoice_id', 191)->nullable();
                $table->text('refund_reason')->nullable();
                $table->dateTime('refunded_at')->nullable()->index();
                $table->longText('raw_payload')->nullable();
                $table->dateTime('synced_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['logistics_paypal_account_id', 'refund_transaction_id'],
                    'logistics_paypal_refund_account_transaction_unique',
                );
                $table->index(
                    ['logistics_paypal_account_id', 'logistics_order_id'],
                    'logistics_paypal_refund_account_order_index',
                );
            });
        }

        if (!$schema->hasTable('logistics_paypal_disputes')) {
            $schema->create('logistics_paypal_disputes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_account_id')->index();
                $table->unsignedBigInteger('logistics_order_id')->nullable()->index();
                $table->string('dispute_id', 100);
                $table->string('disputed_transaction_id', 100)->nullable()->index();
                $table->string('reason', 100)->nullable();
                $table->string('status', 50)->nullable()->index();
                $table->string('dispute_state', 100)->nullable();
                $table->string('life_cycle_stage', 100)->nullable();
                $table->string('channel', 100)->nullable();
                $table->decimal('dispute_amount_usd', 18, 4)->nullable();
                $table->decimal('original_amount', 18, 4)->nullable();
                $table->string('currency_code', 10)->nullable();
                $table->dateTime('dispute_created_at')->nullable()->index();
                $table->dateTime('dispute_updated_at')->nullable();
                $table->dateTime('seller_response_due_at')->nullable();
                $table->string('outcome_code', 100)->nullable();
                $table->longText('raw_payload')->nullable();
                $table->dateTime('synced_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['logistics_paypal_account_id', 'dispute_id'],
                    'logistics_paypal_dispute_account_dispute_unique',
                );
                $table->index(
                    ['logistics_paypal_account_id', 'logistics_order_id'],
                    'logistics_paypal_dispute_account_order_index',
                );
            });
        }

        $this->installMenu($connection);
    }

    public function down(): void
    {
        // PayPal 风控属于财务审计数据，回滚代码时保留历史账号、退款与纠纷记录。
    }

    private function installMenu(?string $connection): void
    {
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'wuliu')->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $parent = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'logistics_reports')
            ->first(['id']);

        if ($parent) {
            $parentId = (int) $parent->id;
            $database->table('portal_menus')->where('id', $parentId)->update([
                'title' => '数据大盘',
                'title_key' => 'logistics.dashboard.menu',
                'path' => '#',
                'icon' => 'fa-dashboard',
                'is_enabled' => true,
                'updated_at' => $timestamp,
            ]);
        } else {
            $parentId = (int) $database->table('portal_menus')->insertGetId([
                'portal_id' => $portalId,
                'code' => 'logistics_reports',
                'parent_id' => 0,
                'title' => '数据大盘',
                'title_key' => 'logistics.dashboard.menu',
                'path' => '#',
                'icon' => 'fa-dashboard',
                'sort' => 30,
                'is_enabled' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $values = [
            'parent_id' => $parentId,
            'title' => 'PayPal账号风控',
            'title_key' => 'logistics.paypal_risk.menu',
            'path' => '/paypal-risk',
            'icon' => 'fa-paypal',
            'sort' => 20,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ];
        $menu = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'logistics_paypal_risk')
            ->first(['id']);

        if ($menu) {
            $database->table('portal_menus')->where('id', $menu->id)->update($values);
        } else {
            $database->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => 'logistics_paypal_risk',
                ...$values,
                'created_at' => $timestamp,
            ]);
        }
    }
};
