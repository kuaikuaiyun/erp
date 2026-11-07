<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection=config('admin.database.connection')?:config('database.default');$schema=Schema::connection($connection);$db=DB::connection($connection);
        if($schema->hasTable('portal_menus')){
            $dailyPortalId=$db->table('portals')->where('code','daily-report')->value('id');
            if($dailyPortalId)$db->table('portal_menus')->where(['portal_id'=>$dailyPortalId,'code'=>'daily_report_applovin_spends'])->delete();
            $operationPortalId=$db->table('portals')->where('code','yunying')->value('id');
            if($operationPortalId)$db->table('portal_menus')->where(['portal_id'=>$operationPortalId,'code'=>'operation_daily_applovin_spends'])->update(['parent_id'=>0,'is_enabled'=>1,'updated_at'=>now()]);
        }
        $permissions=config('admin.database.permissions_table');
        if($schema->hasTable($permissions)){
            $id=$db->table($permissions)->where('slug','portal.daily-report.applovin_spends')->value('id');
            if($id){foreach([config('admin.database.role_permissions_table'),config('admin.database.user_permissions_table')] as $table)if($schema->hasTable($table))$db->table($table)->where('permission_id',$id)->delete();$db->table($permissions)->where('id',$id)->delete();}
        }
    }
    public function down():void{}
};
