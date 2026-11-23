<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetAccountsTest.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssetDepartmentsTest extends TestCase
{
    use AssetAccountsEnvironment { setUp as private accountsSetUp; }
    private string $base='/asset-manager/departments';
    protected function setUp(): void
    {
        $this->accountsSetUp();
        if(!Schema::hasColumn('admin_roles','short_name')) Schema::table('admin_roles',fn(Blueprint $t)=>$t->string('short_name')->nullable());
        (require database_path('migrations/2026_11_23_000200_install_asset_department_management.php'))->up();
    }

    public function test_authorized_departments_only_and_basic_edit_preserves_code_parent_and_permissions(): void
    {
        $this->get($this->base)->assertOk()->assertSee($this->teamA->name)->assertDontSee($this->outside->name);
        $this->getJson($this->base.'/'.$this->outside->id)->assertNotFound();
        $before=$this->teamA->only(['slug','parent_id']);
        $this->putJson($this->base.'/'.$this->teamA->id,['name'=>'New department','short_name'=>'ND','sort'=>23])->assertOk();
        $this->assertSame($before,$this->teamA->fresh()->only(['slug','parent_id']));
        $this->assertSame('ND',$this->teamA->fresh()->short_name);
        $this->assertSame(1,DB::table('admin_department_change_logs')->where('event','department_updated')->count());
        foreach(['slug'=>'forged','parent_id'=>$this->outside->id,'permissions'=>[1]] as $key=>$value) {
            $this->putJson($this->base.'/'.$this->teamA->id,['name'=>'X','sort'=>0,$key=>$value])->assertUnprocessable();
        }
    }

    public function test_readonly_member_cannot_edit_and_members_are_paginated_without_sensitive_fields(): void
    {
        for($i=0;$i<22;$i++)$this->hrMember($this->teamA);
        $this->hrActor=$this->hrMember($this->hrSource);
        $this->get($this->base)->assertOk();
        $this->putJson($this->base.'/'.$this->teamA->id,['name'=>'X','sort'=>0])->assertForbidden();
        $this->getJson($this->base.'/'.$this->teamA->id.'/members')->assertOk()->assertJsonPath('total',22)->assertJsonCount(20,'data')->assertDontSee('password')->assertDontSee('remember_token');
        $this->getJson($this->base.'/'.$this->outside->id.'/members')->assertNotFound();
    }

    public function test_create_delete_and_permission_routes_do_not_exist_and_disabled_menu_blocks_access(): void
    {
        $this->postJson($this->base,['name'=>'Forbidden'])->assertStatus(405);
        $this->deleteJson($this->base.'/'.$this->teamA->id)->assertStatus(405);
        $this->putJson($this->base.'/'.$this->teamA->id.'/permissions',[])->assertStatus(405);
        DB::table('portal_menus')->where('code','asset_manager_departments')->update(['is_enabled'=>false]);
        $this->getJson($this->base.'/'.$this->teamA->id)->assertForbidden();
        $this->assertDatabaseHas('admin_roles',['id'=>$this->teamA->id]);
    }
}
