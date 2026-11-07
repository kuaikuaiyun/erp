<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'daily-report';
    private const MENUS = [
        ['code'=>'daily_report_advertising_recharge','title'=>'广告充值','path'=>'/operation/daily-submissions/advertising-recharge','icon'=>'fa-credit-card','slug'=>'portal.daily-report.ad_recharge'],
        ['code'=>'daily_report_account_opening','title'=>'开户需求','path'=>'/operation/daily-submissions/account-opening','icon'=>'fa-user-plus','slug'=>'portal.daily-report.account_opening'],
        ['code'=>'daily_report_store_launch','title'=>'下店需求','path'=>'/operation/daily-submissions/store-launch','icon'=>'fa-shopping-bag','slug'=>'portal.daily-report.store_launch'],
        ['code'=>'daily_report_ad_account_auth','title'=>'广告账号授权','path'=>'/operation/daily-submissions/advertising-account-authorization','icon'=>'fa-key','slug'=>'portal.daily-report.ad_account_auth'],
        ['code'=>'daily_report_account_store_transfer','title'=>'账号/店铺弃用转让','path'=>'/operation/daily-submissions/account-store-transfer','icon'=>'fa-exchange','slug'=>'portal.daily-report.account_transfer'],
    ];

    public function up(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);
        if(!$schema->hasTable('portals')||!$schema->hasTable('portal_menus'))return;$db=DB::connection($connection);
        $db->transaction(function()use($db,$schema):void{
            $now=now();$sourcePortalId=$db->table('portals')->where('code','yunying')->value('id');$portalId=$db->table('portals')->where('code',self::PORTAL_CODE)->value('id');
            if(!$portalId)$portalId=$db->table('portals')->insertGetId(['code'=>self::PORTAL_CODE,'name'=>'日常提报系统','remark'=>'运营日常需求提报及 AppLovin 账号消耗管理。','status'=>'enabled','created_at'=>$now,'updated_at'=>$now]);
            $parentId=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'daily_report_submissions'])->value('id');
            if(!$parentId)$parentId=$db->table('portal_menus')->insertGetId(['portal_id'=>$portalId,'parent_id'=>0,'code'=>'daily_report_submissions','title'=>'日常提报','title_key'=>'','path'=>'#','icon'=>'fa-calendar-check-o','sort'=>10,'is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
            foreach(self::MENUS as $sort=>$menu){$values=['parent_id'=>$parentId,'title'=>$menu['title'],'title_key'=>'','path'=>$menu['path'],'icon'=>$menu['icon'],'sort'=>$sort+1,'is_enabled'=>1,'updated_at'=>$now];if($schema->hasColumn('portal_menus','permission_slug'))$values['permission_slug']=$menu['slug'];$db->table('portal_menus')->updateOrInsert(['portal_id'=>$portalId,'code'=>$menu['code']],[...$values,'created_at'=>$now]);$this->permission($db,$schema,$menu,$now);}
            $managementId=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'backend_management'])->value('id');if(!$managementId)$managementId=$db->table('portal_menus')->insertGetId(['portal_id'=>$portalId,'parent_id'=>0,'code'=>'backend_management','title'=>null,'title_key'=>'portal.menu.backend_management','path'=>'#','icon'=>'fa-cogs','sort'=>900,'is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
            $db->table('portal_menus')->updateOrInsert(['portal_id'=>$portalId,'code'=>'menu_configuration'],['parent_id'=>$managementId,'title'=>null,'title_key'=>'portal.menu.configuration','path'=>'/management/menus','icon'=>'fa-list','sort'=>1,'is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
            if($sourcePortalId&&$schema->hasTable('portal_departments'))foreach($db->table('portal_departments')->where('portal_id',$sourcePortalId)->get() as $grant)$db->table('portal_departments')->updateOrInsert(['portal_id'=>$portalId,'department_id'=>$grant->department_id],['includes_descendants'=>$grant->includes_descendants,'granted_by'=>$grant->granted_by,'granted_at'=>$grant->granted_at?:$now,'created_at'=>$now,'updated_at'=>$now]);
            if($sourcePortalId)$db->table('portal_menus')->where(['portal_id'=>$sourcePortalId,'code'=>'operation_daily_submissions'])->update(['is_enabled'=>0,'updated_at'=>$now]);
        });
    }

    private function permission($db,$schema,array $menu,$now):void
    {
        $permissions=config('admin.database.permissions_table');$roles=config('admin.database.roles_table');$pivot=config('admin.database.role_permissions_table');if(!$schema->hasTable($permissions)||!$schema->hasTable($roles)||!$schema->hasTable($pivot))return;$id=$db->table($permissions)->where('slug',$menu['slug'])->value('id');if(!$id)$id=$db->table($permissions)->insertGetId(['name'=>'[日常提报系统] '.$menu['title'],'slug'=>$menu['slug'],'http_method'=>'','http_path'=>'','created_at'=>$now,'updated_at'=>$now]);foreach($db->table($roles)->where('slug','!=','administrator')->pluck('id') as $roleId)$db->table($pivot)->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$id],['created_at'=>$now,'updated_at'=>$now]);
    }

    public function down():void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);if(!$schema->hasTable('portals'))return;$db=DB::connection($connection);$source=$db->table('portals')->where('code','yunying')->value('id');if($source)$db->table('portal_menus')->where(['portal_id'=>$source,'code'=>'operation_daily_submissions'])->update(['is_enabled'=>1,'updated_at'=>now()]);
    }
};
