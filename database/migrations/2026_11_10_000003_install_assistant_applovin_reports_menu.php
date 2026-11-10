<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection(config('admin.database.connection')?:config('database.default'));
        $portalId=$db->table('portals')->where('code','zhuli')->value('id'); if(!$portalId)return;
        $now=now();$slug='portal.zhuli.daily.applovin_spends';
        $parentId=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_applovin_reports'])->value('id');
        if(!$parentId)$parentId=$db->table('portal_menus')->insertGetId(['portal_id'=>$portalId,'parent_id'=>0,'code'=>'assistant_applovin_reports','title'=>'AppLovin报表','title_key'=>'','path'=>'#','permission_slug'=>$slug,'icon'=>'fa-area-chart','sort'=>7,'is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_daily_applovin_spends'])->update(['parent_id'=>$parentId,'sort'=>1,'icon'=>'fa-line-chart','updated_at'=>$now]);
        $exists=$db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_applovin_performance'])->exists();
        if(!$exists)$db->table('portal_menus')->insert(['portal_id'=>$portalId,'parent_id'=>$parentId,'code'=>'assistant_applovin_performance','title'=>'AppLovin业绩报表','title_key'=>'','path'=>'/assistant/applovin-performance-reports','permission_slug'=>$slug,'icon'=>'fa-bar-chart','sort'=>2,'is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void
    {
        $db=DB::connection(config('admin.database.connection')?:config('database.default'));$portalId=$db->table('portals')->where('code','zhuli')->value('id');if(!$portalId)return;
        $db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_applovin_performance'])->delete();
        $db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_daily_applovin_spends'])->update(['parent_id'=>0,'sort'=>7]);
        $db->table('portal_menus')->where(['portal_id'=>$portalId,'code'=>'assistant_applovin_reports'])->delete();
    }
};
