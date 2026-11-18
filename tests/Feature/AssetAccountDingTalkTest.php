<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetAccountsTest.php';

use App\Models\DingTalkAudit;
use App\Models\DingTalkBinding;
use App\Models\DingTalkCompanyGrant;
use App\Services\DingTalk\BindingAccessService;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\DirectoryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetAccountDingTalkTest extends TestCase
{
    use AssetAccountsEnvironment;

    private function grantCompany(): void
    {
        DingTalkCompanyGrant::firstOrCreate(['company_id' => $this->company->id, 'user_id' => $this->hrActor->id], ['created_by' => 1]);
    }

    public function test_dingtalk_options_use_hr_department_mapping_but_keep_company_grants(): void
    {
        $target = $this->hrMember($this->teamA);
        $outside = $this->hrMember($this->outside);
        $this->getJson($this->hrUrl.'/'.$target->id.'/dingtalk')->assertOk()->assertJsonCount(0, 'companies');
        $this->grantCompany();
        $this->getJson($this->hrUrl.'/'.$target->id.'/dingtalk')->assertOk()->assertJsonPath('account.id', $target->id)->assertJsonCount(1, 'companies')->assertDontSee('app_secret');
        $this->getJson($this->hrUrl.'/'.$outside->id.'/dingtalk')->assertForbidden();
        $this->getJson($this->hrUrl.'/'.$this->hrOwner->id.'/dingtalk')->assertForbidden();
    }

    public function test_actual_bind_unbind_and_audits_use_existing_rules_and_restore_shared_access_service(): void
    {
        $target = $this->hrMember($this->teamA);
        $this->grantCompany();
        $this->operatorBinding = app(BindingService::class)->bind($this->hrActor->id, 'user-b', false, $this->company->id);
        $original = new BindingAccessService;
        app()->instance(BindingAccessService::class, $original);
        $payload = ['account_id' => $target->id, 'company_id' => $this->company->id, 'user_id' => 'user-a'];
        $this->postJson($this->hrUrl.'/dingtalk/bindings', $payload)->assertOk();
        $this->assertSame($original, app(BindingAccessService::class));
        $binding = DingTalkBinding::where('account_id', $target->id)->firstOrFail();
        $this->assertTrue($binding->active);
        $this->postJson($this->hrUrl.'/dingtalk/bindings', $payload)->assertOk();
        $this->assertSame(1, DingTalkAudit::where('action', 'binding.created')->where('metadata->target_account_id', $target->id)->count());
        $this->postJson($this->hrUrl.'/dingtalk/bindings', array_replace($payload, ['user_id' => 'user-b']))->assertUnprocessable();
        $this->getJson($this->hrUrl.'/dingtalk/audits?account_id='.$target->id.'&kind=operation')->assertOk()->assertJsonCount(1, 'rows');
        $this->deleteJson($this->hrUrl.'/dingtalk/bindings/'.$binding->id)->assertOk();
        $this->assertFalse($binding->fresh()->active);
        $this->getJson($this->hrUrl.'/dingtalk/audits?account_id='.$target->id.'&kind=operation')->assertJsonCount(2, 'rows');
        $this->assertSame($original, app(BindingAccessService::class));
        $this->assertSame($this->hrActor->id, (int) DingTalkAudit::where('metadata->target_account_id', $target->id)->first()->account_id);
    }

    public function test_company_revocation_and_department_revocation_block_binding_operations(): void
    {
        $target = $this->hrMember($this->teamA);
        $this->grantCompany();
        $this->operatorBinding = app(BindingService::class)->bind($this->hrActor->id, 'user-b', false, $this->company->id);
        $payload = ['account_id' => $target->id, 'company_id' => $this->company->id, 'user_id' => 'user-a'];
        DingTalkCompanyGrant::where('user_id', $this->hrActor->id)->delete();
        $this->postJson($this->hrUrl.'/dingtalk/bindings', $payload)->assertForbidden();
        $this->grantCompany();
        DB::table('asset_computer_department_bindings')->delete();
        $this->postJson($this->hrUrl.'/dingtalk/bindings', $payload)->assertForbidden();
        $this->assertSame(0, DingTalkBinding::where('account_id', $target->id)->count());
    }

    public function test_revoked_operator_identity_cannot_bind_even_with_department_and_company_grants(): void
    {
        $target = $this->hrMember($this->teamA);
        $this->grantCompany();
        $this->operatorBinding = app(BindingService::class)->bind($this->hrActor->id, 'user-b', false, $this->company->id);
        $this->operatorBinding->update(['active' => false]);
        $this->postJson($this->hrUrl.'/dingtalk/bindings', ['account_id' => $target->id, 'company_id' => $this->company->id, 'user_id' => 'user-a'])->assertForbidden();
        $this->assertSame(0, DingTalkBinding::where('account_id', $target->id)->count());
    }

    public function test_directory_requires_granted_company_and_normal_members_cannot_use_dingtalk_endpoints(): void
    {
        $directory = $this->mock(DirectoryService::class);
        $directory->shouldReceive('members')->once()->andReturn(['members' => [], 'has_more' => false, 'next_cursor' => 0]);
        $directory->shouldReceive('departments')->once()->andReturn([]);
        $url = $this->hrUrl.'/dingtalk/directory?company_id='.$this->company->id.'&department=1&cursor=0';
        $this->getJson($url)->assertForbidden();
        $this->grantCompany();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'departments');
        DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => 'member']);
        $this->hrActor->unsetRelation('roles')->unsetRelation('departments');
        $this->getJson($url)->assertForbidden();
        $this->getJson($this->hrUrl.'/dingtalk/company-grants')->assertForbidden();
    }

    public function test_only_original_binding_owner_can_assign_company_grants(): void
    {
        $manager = $this->hrActor;
        $this->getJson($this->hrUrl.'/dingtalk/company-grants')->assertForbidden();
        $this->hrActor = $this->hrOwner;
        $this->getJson($this->hrUrl.'/dingtalk/company-grants')->assertOk()->assertSee($manager->username);
        $this->postJson($this->hrUrl.'/dingtalk/company-grants', ['company_id' => $this->company->id, 'user_ids' => [$manager->id]])->assertOk();
        $this->assertTrue(DingTalkCompanyGrant::where('company_id',$this->company->id)->where('user_id',$manager->id)->exists());
    }
}
