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
        if(!Schema::hasColumn('admin_users','company_id')) Schema::table('admin_users',fn(Blueprint $t)=>$t->unsignedBigInteger('company_id')->nullable());
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

    public function test_whole_groups_paginate_together_and_counts_follow_main_backend_capacity_rules(): void
    {
        for($i=0;$i<25;$i++) \App\Models\Department::create(['name'=>'Child '.$i,'slug'=>'child-'.$i,'parent_id'=>$this->teamA->id]);
        $this->hrMember($this->teamA,'manager');
        $this->get($this->base.'?per_page=20')->assertOk()->assertViewHas('rows',fn($rows)=>$rows->total()===2)
            ->assertViewHas('treeGroups',function($groups){$a=$groups->firstWhere('id',$this->teamA->id);return $a['children']->count()===25 && (int)$a['root']->manager_count===1;})
            ->assertSee('Child 24')->assertSee('data-department-toggle',false)->assertSee('人员结构');
        $this->get($this->base.'?search=Child%2024')->assertOk()->assertSee('Child 24')->assertDontSee('Child 23');
    }

    public function test_only_authorized_child_is_shown_and_parent_context_has_no_management_access(): void
    {
        $a=\App\Models\Department::create(['name'=>'Authorized child','slug'=>'authorized-child','parent_id'=>$this->outside->id]);
        $b=\App\Models\Department::create(['name'=>'Private sibling','slug'=>'private-sibling','parent_id'=>$this->outside->id]);
        DB::table('asset_computer_department_bindings')->insert(['source_department_id'=>$this->hrSource->id,'target_department_id'=>$a->id,'updated_by'=>1]);
        $this->get($this->base)->assertOk()->assertSee('Authorized child')->assertDontSee('Private sibling')
            ->assertDontSee('data-department-edit="'.$this->outside->id.'"',false)->assertDontSee('data-department-members="'.$this->outside->id.'"',false);
        $this->getJson($this->base.'/'.$this->outside->id)->assertNotFound();
        $this->getJson($this->base.'/'.$b->id)->assertNotFound();
    }

    public function test_candidates_require_write_department_and_company_scope_and_never_expose_other_memberships(): void
    {
        \App\Models\DingTalkCompanyGrant::create(['company_id'=>$this->company->id,'user_id'=>$this->hrActor->id,'created_by'=>1]);
        $valid=$this->hrMember($this->teamB); $valid->forceFill(['company_id'=>$this->company->id])->save();
        $valid->roles()->attach($this->outside->id,['position'=>'member']);
        $already=$this->hrMember($this->teamA); $outside=$this->hrMember($this->outside);
        $disabled=$this->hrMember($this->teamB);$disabled->forceFill(['status'=>0])->save();
        $other=$this->company->replicate();$other->corp_id='other-members';$other->login_slug='other-members';$other->save();
        $foreign=$this->hrMember($this->teamB);$foreign->forceFill(['company_id'=>$other->id])->save();
        $url=$this->base.'/'.$this->teamA->id.'/candidates';
        $response=$this->getJson($url)->assertOk()->assertJsonPath('rows.total',1)->assertJsonPath('rows.data.0.id',$valid->id);
        $this->assertSame([$this->teamB->id],array_column($response->json('rows.data.0.departments'),'id'));
        $response->assertDontSee('password')->assertDontSee('remember_token');
        $this->getJson($url.'?company_id='.$other->id)->assertUnprocessable();
        $this->getJson($url.'?department_id='.$this->outside->id)->assertUnprocessable();
        $this->getJson($url.'?company_id='.$this->company->id.'&department_id='.$this->teamB->id)->assertOk()->assertJsonPath('rows.total',1);
        \App\Models\DingTalkCompanyGrant::where('user_id',$this->hrActor->id)->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('rows.total',0);
        $this->postJson($this->base.'/'.$this->teamA->id.'/members',['user_ids'=>[$valid->id]])->assertUnprocessable();
        $this->hrActor=$this->hrMember($this->hrSource);
        $this->getJson($url)->assertForbidden();
        $this->postJson($this->base.'/'.$this->teamA->id.'/members',['user_ids'=>[$valid->id]])->assertForbidden();
    }

    public function test_batch_add_is_idempotent_and_preserves_existing_positions_outside_memberships_and_company(): void
    {
        $person=$this->hrMember($this->teamB,'deputy');$person->roles()->attach($this->outside->id,['position'=>'member']);
        \App\Models\DingTalkCompanyGrant::create(['company_id'=>$this->company->id,'user_id'=>$this->hrActor->id,'created_by'=>1]);
        $person->forceFill(['company_id'=>$this->company->id])->save();
        $identity=\App\Models\DingTalkIdentity::create(['corp_id'=>$this->company->corp_id,'user_id'=>'member-binding','union_id'=>'member-union','name'=>$person->name,'active'=>true]);
        $binding=\App\Models\DingTalkBinding::create(['identity_id'=>$identity->id,'account_id'=>$person->id,'active'=>true]);
        $old=$person->roles()->get()->mapWithKeys(fn($d)=>[$d->id=>$d->pivot->position])->all();
        $url=$this->base.'/'.$this->teamA->id.'/members';
        $this->postJson($url,['user_ids'=>[$person->id]])->assertOk()->assertJsonPath('count',1);
        $new=$person->fresh()->roles()->get()->mapWithKeys(fn($d)=>[$d->id=>$d->pivot->position])->all();
        foreach($old as $id=>$position)$this->assertSame($position,$new[$id]);
        $this->assertSame('member',$new[$this->teamA->id]);$this->assertSame($this->company->id,$person->fresh()->company_id);
        $this->assertSame(1,$person->dingTalkBindings()->count());$this->assertSame($identity->id,$binding->fresh()->identity_id);
        $this->postJson($url,['user_ids'=>[$person->id]])->assertOk()->assertJsonPath('count',0);
        $this->assertSame(1,$person->roles()->whereKey($this->teamA->id)->count());
        $manager=$this->hrMember($this->teamA,'manager');
        $this->postJson($url,['user_ids'=>[$manager->id]])->assertOk()->assertJsonPath('count',0);
        $this->assertSame('manager',$manager->roles()->whereKey($this->teamA->id)->first()->pivot->position);
        $this->getJson($url)->assertOk()->assertJsonPath('department.can_add',true)->assertJsonPath('department.counts.member',1);
    }

    public function test_mixed_scope_disabled_admin_or_revoked_target_rejects_entire_batch(): void
    {
        $valid=$this->hrMember($this->teamB);$outside=$this->hrMember($this->outside);
        $url=$this->base.'/'.$this->teamA->id.'/members';
        foreach([$outside->id,$this->hrOwner->id,999999] as $invalid){
            $this->postJson($url,['user_ids'=>[$valid->id,$invalid]])->assertUnprocessable();
            $this->assertFalse($valid->departments()->whereKey($this->teamA->id)->exists());
        }
        $valid->forceFill(['status'=>0])->save();$this->postJson($url,['user_ids'=>[$valid->id]])->assertUnprocessable();
        $valid->forceFill(['status'=>1])->save();
        DB::table('asset_computer_department_bindings')->where('target_department_id',$this->teamA->id)->delete();
        $this->postJson($url,['user_ids'=>[$valid->id]])->assertNotFound();
    }

    public function test_branch_add_is_blocked_and_assistant_child_conflict_rolls_back_previous_success(): void
    {
        $person=$this->hrMember($this->teamB);
        $child=\App\Models\Department::create(['name'=>'Child','slug'=>'leaf','parent_id'=>$this->teamA->id]);
        $this->getJson($this->base.'/'.$this->teamA->id.'/members')->assertOk()->assertJsonPath('department.can_add',false);
        $this->postJson($this->base.'/'.$this->teamA->id.'/members',['user_ids'=>[$person->id]])->assertUnprocessable();
        $this->getJson($this->base.'/'.$this->teamA->id.'/candidates')->assertUnprocessable();
        // Two assistant child departments cannot be assigned to the same account.
        $assistant=\App\Models\Department::create(['name'=>'Assistant','slug'=>'dept-000004']);
        $this->teamA->forceFill(['parent_id'=>$assistant->id])->save();$this->teamB->forceFill(['parent_id'=>$assistant->id])->save();
        $empty=\App\Models\Department::create(['name'=>'General','slug'=>'general']);
        DB::table('asset_computer_department_bindings')->insert(['source_department_id'=>$this->hrSource->id,'target_department_id'=>$empty->id,'updated_by'=>1]);
        $valid=$this->hrMember($empty);$conflict=$this->hrMember($this->teamA);
        $this->postJson($this->base.'/'.$this->teamB->id.'/members',['user_ids'=>[$valid->id,$conflict->id]])->assertUnprocessable();
        $this->assertFalse($valid->departments()->whereKey($this->teamB->id)->exists());
        $this->assertSame(0,DB::table('admin_department_change_logs')->where('event','hr_department_members_added')->count());
    }

    public function test_candidates_paginate_without_duplicates_and_batch_size_is_limited(): void
    {
        for($i=0;$i<25;$i++)$this->hrMember($this->teamB);
        $url=$this->base.'/'.$this->teamA->id;
        $first=$this->getJson($url.'/candidates?page=1')->assertOk()->assertJsonCount(20,'rows.data')->assertJsonPath('rows.total',25);
        $second=$this->getJson($url.'/candidates?page=2')->assertOk()->assertJsonCount(5,'rows.data');
        $ids=array_merge(array_column($first->json('rows.data'),'id'),array_column($second->json('rows.data'),'id'));
        $this->assertCount(25,array_unique($ids));
        $this->postJson($url.'/members',['user_ids'=>range(1,101)])->assertUnprocessable();
        $this->assertSame(0,$this->teamA->departmentMembers()->count());
    }
}
