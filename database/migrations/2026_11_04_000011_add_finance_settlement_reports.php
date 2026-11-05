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
        $db = DB::connection($connection);
        if (! Schema::connection($connection)->hasTable('finance_settlement_supplements')) {
            Schema::connection($connection)->create('finance_settlement_supplements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('finance_record_id')->unique();
                $table->json('payload');
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
        $portal = $db->table('portals')->where('code', 'caiwu')->value('id');
        $parent = $db->table('portal_menus')->where('portal_id', $portal)->where('code', 'finance_reports')->value('id');
        if (! $portal || ! $parent) {
            return;
        }
        foreach (['paypal-settlement' => 'PayPal结算明细', 'credit-settlement' => '信用卡结算明细'] as $code => $title) {
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portal, 'code' => 'finance_'.$code], [
                'parent_id' => $parent, 'title' => $title, 'title_key' => 'finance.menus.'.$code,
                'path' => '/finance/reports/'.$code, 'icon' => 'fa-list-alt', 'permission_slug' => 'finance.view',
                'sort' => $code === 'paypal-settlement' ? 10 : 20, 'is_enabled' => true, 'updated_at' => now(),
            ]);
        }
        $db->table('portal_menus')->where('portal_id', $portal)->where('code', 'finance_summary')->update(['sort' => 30]);
        $db->table('portal_menus')->where('portal_id', $portal)->where('code', 'finance_losses')->update(['sort' => 40]);
    }

    public function down(): void {}
};
