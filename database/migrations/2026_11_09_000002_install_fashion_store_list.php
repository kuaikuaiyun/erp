<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);
        if(!$schema->hasTable('portals')||!$schema->hasTable('portal_menus'))return;$db=DB::connection($connection);$portalId=$db->table('portals')->where('code','fuzhuang')->value('id');if(!$portalId)return;$now=now();
        $permissions=config('admin.database.permissions_table');$roles=config('admin.database.roles_table');$pivot=config('admin.database.role_permissions_table');$permissionId=null;
        if($schema->hasTable($permissions)){$permission=$db->table($permissions)->where('slug','portal.fuzhuang.stores')->first(['id']);$values=['name'=>'[服装运营系统] 店铺列表','http_method'=>'GET,PATCH','http_path'=>"/fashion/stores\r\n/fashion/stores/*",'updated_at'=>$now];if($permission){$db->table($permissions)->where('id',$permission->id)->update($values);$permissionId=(int)$permission->id;}else{$permissionId=(int)$db->table($permissions)->insertGetId(['slug'=>'portal.fuzhuang.stores',...$values,'created_at'=>$now]);}}
        $menu=['parent_id'=>0,'title'=>'店铺列表','title_key'=>'fashion.stores.menu','path'=>'/fashion/stores','permission_slug'=>'portal.fuzhuang.stores','icon'=>'fa-shopping-bag','sort'=>15,'is_enabled'=>1,'updated_at'=>$now];$existing=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'fashion_stores'])->first(['id']);$existing?$db->table('portal_menus')->where('id',$existing->id)->update($menu):$db->table('portal_menus')->insert(['portal_id'=>$portalId,'code'=>'fashion_stores',...$menu,'created_at'=>$now]);
        if(!$permissionId||!$schema->hasTable($pivot)||!$schema->hasTable($roles))return;$source=$db->table($permissions)->where('slug','portal.fuzhuang.performance_report')->value('id');$roleIds=$source?$db->table($pivot)->where('permission_id',$source)->pluck('role_id'):collect();$admin=$db->table($roles)->where('slug','administrator')->value('id');if($admin)$roleIds->push($admin);foreach($roleIds->unique() as $roleId)$db->table($pivot)->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId],['created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$db=DB::connection($connection);$portalId=$db->table('portals')->where('code','fuzhuang')->value('id');if($portalId)$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'fashion_stores'])->delete();$permissions=config('admin.database.permissions_table');$id=$db->table($permissions)->where('slug','portal.fuzhuang.stores')->value('id');if($id){$db->table(config('admin.database.role_permissions_table'))->where('permission_id',$id)->delete();$db->table($permissions)->where('id',$id)->delete();}
    }
};
