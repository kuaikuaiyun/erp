<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection(config('admin.database.connection') ?: config('database.default'));
        $menus=config('admin.database.menu_table');
        $parent=$db->table($menus)->where('title',\Illuminate\Support\Facades\Lang::get('configuration.menu.configuration_center',[],'zh-CN'))->value('id');
        if (!$parent) $parent=$db->table($menus)->whereIn('uri',['configuration/basic','configuration/captcha','configuration/watermark'])->value('parent_id');
        $role=$db->table(config('admin.database.roles_table'))->where('slug','administrator')->value('id');
        if (!$parent || !$role) throw new RuntimeException(__('dingtalk.company.menu_missing'));
        if (!Schema::hasTable('dingtalk_companies')) Schema::create('dingtalk_companies', function (Blueprint $t): void {
            $t->id(); $t->string('name',190); $t->string('corp_id',128)->unique(); $t->string('app_key',190);
            $t->text('app_secret'); $t->text('redirect_uri'); $t->text('callback_token'); $t->text('callback_aes_key');
            $t->string('callback_owner_key',190); $t->boolean('enabled')->default(true); $t->unsignedBigInteger('version')->default(1);
            $t->unsignedInteger('member_count')->nullable(); $t->string('directory_generation',40)->nullable();
            $t->json('directory_state')->nullable(); $t->timestamp('directory_synced_at')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        if (!Schema::hasTable('dingtalk_company_grants')) Schema::create('dingtalk_company_grants', function (Blueprint $t): void {
            $t->id(); $t->foreignId('company_id')->constrained('dingtalk_companies'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('created_by'); $t->timestamps(); $t->unique(['company_id','user_id']);
        });
        if (!Schema::hasTable('dingtalk_directory_members')) Schema::create('dingtalk_directory_members', function (Blueprint $t): void {
            $t->id(); $t->foreignId('company_id')->constrained('dingtalk_companies'); $t->string('generation',40);
            $t->string('user_id',128); $t->string('name',190); $t->json('department_ids');
            $t->unique(['company_id','generation','user_id'], 'ding_directory_member_unique');
        });
        if (!Schema::hasColumn('dingtalk_challenges','company_id')) Schema::table('dingtalk_challenges', function (Blueprint $t): void { $t->unsignedBigInteger('company_id')->nullable()->index(); });
        if (!Schema::hasColumn('dingtalk_challenges','company_version')) Schema::table('dingtalk_challenges', function (Blueprint $t): void { $t->unsignedBigInteger('company_version')->nullable(); });
        // The preceding grant workflow was local-only and has no retained business data.
        Schema::dropIfExists('dingtalk_binding_grants');
        $db->transaction(function () use ($db,$parent,$role): void {
            $menus=config('admin.database.menu_table'); $permissions=config('admin.database.permissions_table');
            $oldMenus=$db->table($menus)->where('uri','auth/dingtalk/bindings')->pluck('id');
            $db->table(config('admin.database.role_menu_table'))->whereIn('menu_id',$oldMenus)->delete();
            $db->table($menus)->whereIn('id',$oldMenus)->delete();
            $oldPermissions=$db->table($permissions)->where('slug','dingtalk.bindings')->pluck('id');
            foreach ([config('admin.database.role_permissions_table'),config('admin.database.user_permissions_table'),'admin_user_permission_overrides','admin_department_permission_scopes'] as $table) {
                if ($db->getSchemaBuilder()->hasTable($table)) $db->table($table)->whereIn('permission_id',$oldPermissions)->delete();
            }
            $db->table($permissions)->whereIn('id',$oldPermissions)->delete();
            $permission=$db->table($permissions)->where('slug','configuration.dingtalk')->value('id');
            if (!$permission) $permission=$db->table($permissions)->insertGetId(['slug'=>'configuration.dingtalk','name'=>\Illuminate\Support\Facades\Lang::get('dingtalk.company.title',[],'zh-CN'),
                'http_method'=>'GET,POST,DELETE', 'http_path'=>"/configuration/dingtalk\n/configuration/dingtalk/*", 'created_at'=>now(),'updated_at'=>now()]);
            $role=$db->table(config('admin.database.roles_table'))->where('slug','administrator')->value('id');
            if (!$role) throw new RuntimeException(__('dingtalk.console.admin_required'));
            $rolePermissions=config('admin.database.role_permissions_table');
            if (!$db->table($rolePermissions)->where('role_id',$role)->where('permission_id',$permission)->exists()) {
                $row=['role_id'=>$role,'permission_id'=>$permission];
                foreach (['created_at','updated_at'] as $stamp) if ($db->getSchemaBuilder()->hasColumn($rolePermissions,$stamp)) $row[$stamp]=now();
                $db->table($rolePermissions)->insert($row);
            }
            $menu=$db->table($menus)->where('uri','configuration/dingtalk')->value('id');
            if (!$menu) $menu=$db->table($menus)->insertGetId(['parent_id'=>$parent,'order'=>30,'title'=>\Illuminate\Support\Facades\Lang::get('dingtalk.company.title',[],'zh-CN'),
                'icon'=>'fa-link','uri'=>'configuration/dingtalk','permission'=>'configuration.dingtalk','created_at'=>now(),'updated_at'=>now()]);
            $roleMenus=config('admin.database.role_menu_table');
            if (!$db->table($roleMenus)->where('role_id',$role)->where('menu_id',$menu)->exists()) {
                $row=['role_id'=>$role,'menu_id'=>$menu];
                foreach (['created_at','updated_at'] as $stamp) if ($db->getSchemaBuilder()->hasColumn($roleMenus,$stamp)) $row[$stamp]=now();
                $db->table($roleMenus)->insert($row);
            }
        });
    }
    public function down(): void
    {
        $db=DB::connection(config('admin.database.connection') ?: config('database.default'));
        $menuIds=$db->table(config('admin.database.menu_table'))->where('uri','configuration/dingtalk')->pluck('id');
        $db->table(config('admin.database.role_menu_table'))->whereIn('menu_id',$menuIds)->delete();
        $db->table(config('admin.database.menu_table'))->whereIn('id',$menuIds)->delete();
        $permissionIds=$db->table(config('admin.database.permissions_table'))->where('slug','configuration.dingtalk')->pluck('id');
        foreach ([config('admin.database.role_permissions_table'),config('admin.database.user_permissions_table'),'admin_user_permission_overrides','admin_department_permission_scopes'] as $table) {
            if ($db->getSchemaBuilder()->hasTable($table)) $db->table($table)->whereIn('permission_id',$permissionIds)->delete();
        }
        $db->table(config('admin.database.permissions_table'))->whereIn('id',$permissionIds)->delete();
        Schema::table('dingtalk_challenges', function (Blueprint $t): void { $t->dropColumn(['company_id','company_version']); });
        Schema::dropIfExists('dingtalk_directory_members'); Schema::dropIfExists('dingtalk_company_grants'); Schema::dropIfExists('dingtalk_companies');
    }
};
