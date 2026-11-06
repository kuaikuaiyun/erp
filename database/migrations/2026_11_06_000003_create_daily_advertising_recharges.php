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
        if (! $schema->hasTable('daily_advertising_recharges')) {
            $schema->create('daily_advertising_recharges', function (Blueprint $table): void {
                $table->id();
                $table->date('report_date')->index();
                $table->unsignedBigInteger('operator_user_id')->index();
                $table->string('operator_name', 100);
                $table->unsignedBigInteger('operation_department_id')->nullable()->index();
                $table->string('operation_department_name', 100)->nullable();
                $table->json('entries');
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->string('processed_by_name', 100)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentKey = ['portal_id' => $portalId, 'code' => 'assistant_daily_submissions'];
        $db->table('portal_menus')->updateOrInsert($parentKey, ['parent_id'=>0,'title'=>'日常提报','title_key'=>'','path'=>'#','icon'=>'fa-calendar-check-o','sort'=>35,'is_enabled'=>1,'updated_at'=>now(),'created_at'=>now()]);
        $parentId = (int) $db->table('portal_menus')->where($parentKey)->value('id');
        $values = ['parent_id'=>$parentId,'title'=>'广告充值处理','title_key'=>'','path'=>'/assistant/daily-submissions/advertising-recharges','icon'=>'fa-credit-card','sort'=>1,'is_enabled'=>1,'updated_at'=>now()];
        if ($schema->hasColumn('portal_menus','permission_slug')) $values['permission_slug']='portal.zhuli.daily.ad_recharge';
        $db->table('portal_menus')->updateOrInsert(['portal_id'=>$portalId,'code'=>'assistant_daily_advertising_recharges'], [...$values,'created_at'=>now()]);

        $permissions = config('admin.database.permissions_table'); $roles = config('admin.database.roles_table'); $pivot = config('admin.database.role_permissions_table');
        if (!$schema->hasTable($permissions)||!$schema->hasTable($roles)||!$schema->hasTable($pivot)) return;
        $permissionId=$db->table($permissions)->where('slug','portal.zhuli.daily.ad_recharge')->value('id');
        if(!$permissionId)$permissionId=$db->table($permissions)->insertGetId(['name'=>'[助理系统] 广告充值处理','slug'=>'portal.zhuli.daily.ad_recharge','http_method'=>'','http_path'=>'','created_at'=>now(),'updated_at'=>now()]);
        foreach($db->table($roles)->where('slug','!=','administrator')->pluck('id') as $roleId)$db->table($pivot)->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId],['created_at'=>now(),'updated_at'=>now()]);
    }

    public function down(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);$db=DB::connection($connection);
        if($schema->hasTable('portal_menus'))$db->table('portal_menus')->whereIn('code',['assistant_daily_advertising_recharges','assistant_daily_submissions'])->delete();
        $permissions=config('admin.database.permissions_table');if($schema->hasTable($permissions)){ $id=$db->table($permissions)->where('slug','portal.zhuli.daily.ad_recharge')->value('id'); if($id){foreach([config('admin.database.role_permissions_table'),config('admin.database.user_permissions_table')] as $table)if($schema->hasTable($table))$db->table($table)->where('permission_id',$id)->delete();$db->table($permissions)->where('id',$id)->delete();}}
        $schema->dropIfExists('daily_advertising_recharges');
    }
};
