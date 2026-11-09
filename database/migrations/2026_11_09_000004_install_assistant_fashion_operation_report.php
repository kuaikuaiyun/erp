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
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection); $now = now();
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_fashion_reports'])->value('id');
        if (! $parentId) return;
        $slug = 'portal.zhuli.fashion_operation_reports';
        $values = ['parent_id'=>$parentId,'title'=>'服装运营报表','title_key'=>'','path'=>'/assistant/fashion-operation-reports','icon'=>'fa-bar-chart','sort'=>2,'is_enabled'=>1,'updated_at'=>$now];
        if ($schema->hasColumn('portal_menus','permission_slug')) $values['permission_slug']=$slug;
        $db->table('portal_menus')->updateOrInsert(['portal_id'=>$portalId,'code'=>'assistant_fashion_operation_reports'],[...$values,'created_at'=>$now]);
        $permissions=config('admin.database.permissions_table');$pivot=config('admin.database.role_permissions_table');
        if(!$schema->hasTable($permissions)||!$schema->hasTable($pivot))return;
        $permissionId=$db->table($permissions)->where('slug',$slug)->value('id');
        if(!$permissionId)$permissionId=$db->table($permissions)->insertGetId(['name'=>'[助理系统] 服装运营报表','slug'=>$slug,'http_method'=>'GET','http_path'=>'/assistant/fashion-operation-reports','created_at'=>$now,'updated_at'=>$now]);
        $sourceId=$db->table($permissions)->where('slug','portal.zhuli.fashion_advertising_reviews')->value('id');
        if($sourceId)foreach($db->table($pivot)->where('permission_id',$sourceId)->pluck('role_id') as $roleId)$db->table($pivot)->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId],['created_at'=>$now,'updated_at'=>$now]);
        $userPivot=config('admin.database.user_permissions_table');
        if($sourceId&&$schema->hasTable($userPivot))foreach($db->table($userPivot)->where('permission_id',$sourceId)->pluck('user_id') as $userId)$db->table($userPivot)->updateOrInsert(['user_id'=>$userId,'permission_id'=>$permissionId],['created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);$db=DB::connection($connection);
        if($schema->hasTable('portal_menus'))$db->table('portal_menus')->where('code','assistant_fashion_operation_reports')->delete();
    }
};
