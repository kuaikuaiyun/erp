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
        if ($schema->hasTable('logistics_credit_card_refunds')) {
            $missing = array_filter(['subscription_cancelled', 'add_on_cancelled', 'first_handled_at', 'first_handled_by', 'first_handled_by_name'],
                fn ($column) => !$schema->hasColumn('logistics_credit_card_refunds', $column));
            if ($missing !== []) $schema->table('logistics_credit_card_refunds', function (Blueprint $table) use ($missing): void {
                if (in_array('subscription_cancelled', $missing, true)) $table->boolean('subscription_cancelled')->nullable();
                if (in_array('add_on_cancelled', $missing, true)) $table->boolean('add_on_cancelled')->nullable();
                if (in_array('first_handled_at', $missing, true)) $table->dateTime('first_handled_at')->nullable()->index('ccr_first_handled_idx');
                if (in_array('first_handled_by', $missing, true)) $table->unsignedInteger('first_handled_by')->nullable();
                if (in_array('first_handled_by_name', $missing, true)) $table->string('first_handled_by_name', 100)->nullable();
            });
        }
        if (!$schema->hasTable('logistics_credit_card_refund_operations')) {
            $schema->create('logistics_credit_card_refund_operations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_credit_card_refund_id')->index('ccro_refund_idx');
                $table->unsignedInteger('operator_id')->nullable();
                $table->string('operator_name', 100)->nullable();
                $table->string('action', 50);
                $table->longText('payload')->nullable();
                $table->timestamps();
            });
        }
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'wuliu')->value('id');
        if (!$portalId) return;
        $parentId = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'logistics_reports')->value('id');
        if (!$parentId) return;
        // Follow the existing dashboard's access policy; do not grant roles or permissions.
        $permission = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'logistics_paypal_dispute_analysis')->value('permission_slug');
        $values = ['parent_id'=>$parentId, 'title'=>__('credit_card_analysis.menu'), 'title_key'=>'credit_card_analysis.menu',
            'path'=>'/credit-card-analysis', 'permission_slug'=>$permission, 'icon'=>'fa-credit-card', 'sort'=>26, 'is_enabled'=>true, 'updated_at'=>now()];
        $existing = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'logistics_credit_card_analysis');
        if (!$existing->exists()) {
            $db->table('portal_menus')->insert(['portal_id'=>$portalId, 'code'=>'logistics_credit_card_analysis', ...$values, 'created_at'=>now()]);
        } else {
            $existing->update(['is_enabled'=>true, 'updated_at'=>now()]);
        }
    }

    public function down(): void
    {
        // Keep manual decisions and their audit trail when application code is rolled back.
        $connection = config('admin.database.connection') ?: config('database.default');
        if (Schema::connection($connection)->hasTable('portal_menus') && Schema::connection($connection)->hasTable('portals')) {
            $portalId = DB::connection($connection)->table('portals')->where('code','wuliu')->value('id');
            if ($portalId) DB::connection($connection)->table('portal_menus')->where('portal_id',$portalId)->where('code', 'logistics_credit_card_analysis')->update(['is_enabled'=>false]);
        }
    }
};
