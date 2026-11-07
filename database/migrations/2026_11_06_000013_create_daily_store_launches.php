<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);$db=DB::connection($connection);
        if(!$schema->hasTable('daily_store_launches'))$schema->create('daily_store_launches',function(Blueprint $table):void{$table->id();$table->unsignedBigInteger('operator_user_id')->index();$table->string('operator_name',100);$table->unsignedBigInteger('operation_department_id')->nullable()->index();$table->string('operation_department_name',100)->nullable();$table->unsignedBigInteger('assistant_department_id')->index();$table->unsignedBigInteger('project_id')->index();$table->string('project_name');$table->unsignedBigInteger('website_system_id')->index();$table->string('website_system_name');$table->string('email')->nullable();$table->text('remark')->nullable();$table->string('status',20)->default('pending')->index();$table->text('response_text')->nullable();$table->unsignedBigInteger('processed_by')->nullable();$table->string('processed_by_name',100)->nullable();$table->timestamp('processed_at')->nullable();$table->timestamps();});
        if(!$schema->hasTable('portals')||!$schema->hasTable('portal_menus'))return;$portalId=$db->table('portals')->where('code','zhuli')->value('id');if(!$portalId)return;$parentId=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_daily_submissions'])->value('id');if(!$parentId)return;
        $values=['parent_id'=>$parentId,'title'=>'下店需求处理','title_key'=>'','path'=>'/assistant/daily-submissions/store-launches','icon'=>'fa-shopping-bag','sort'=>3,'is_enabled'=>1,'updated_at'=>now()];if($schema->hasColumn('portal_menus','permission_slug'))$values['permission_slug']='portal.zhuli.daily.store_launch';$db->table('portal_menus')->updateOrInsert(['portal_id'=>$portalId,'code'=>'assistant_daily_store_launches'],[...$values,'created_at'=>now()]);
        $permissions=config('admin.database.permissions_table');$roles=config('admin.database.roles_table');$pivot=config('admin.database.role_permissions_table');if(!$schema->hasTable($permissions)||!$schema->hasTable($roles)||!$schema->hasTable($pivot))return;$id=$db->table($permissions)->where('slug','portal.zhuli.daily.store_launch')->value('id');if(!$id)$id=$db->table($permissions)->insertGetId(['name'=>'[助理系统] 下店需求处理','slug'=>'portal.zhuli.daily.store_launch','http_method'=>'','http_path'=>'','created_at'=>now(),'updated_at'=>now()]);foreach($db->table($roles)->where('slug','!=','administrator')->pluck('id') as $roleId)$db->table($pivot)->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$id],['created_at'=>now(),'updated_at'=>now()]);
    }
    public function down():void{}
};
