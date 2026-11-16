<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';
use App\Admin\Controllers\DingTalkCompanyGrantController;
use App\Admin\Controllers\DingTalkBindingController;
use App\Models\DingTalkCompanyGrant;
use App\Services\DingTalk\BindingAccessService;
use App\Services\DingTalk\BindingService;
use Encore\Admin\Facades\Admin;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class DingTalkCompanyGrantTest extends TestCase
{
    use DingTalkTestEnvironment;
    private function assign($users): void
    {
        $request=$this->browser(); $request->merge(['company_id'=>$this->company->id,'user_ids'=>$users]);
        app(DingTalkCompanyGrantController::class)->store($request);
    }
    public function test_only_account_one_can_assign_companies_and_regular_members_cannot_be_assigned(): void
    {
        $owner=$this->account(true); $other=$this->account(true); $manager=$this->manager(); $member=$this->account();
        Admin::guard()->setUser($other); $this->denied(fn()=>$this->assign([$manager->id]),403);
        Admin::guard()->setUser($owner); $this->assign([$manager->id]); self::assertSame(1,DingTalkCompanyGrant::query()->count());
        try { $this->assign([$member->id]); self::fail('Ordinary member is not eligible'); }
        catch (ValidationException) { self::assertSame([$manager->id],DingTalkCompanyGrant::query()->pluck('user_id')->all()); }
    }
    public function test_managers_and_deputies_are_deduplicated_and_department_scope_applies_to_binding(): void
    {
        $owner=$this->account(true); $manager=$this->manager(); $deputy=$this->manager('deputy'); $target=$this->memberOf($manager); $outside=$this->memberOf($deputy);
        Admin::guard()->setUser($owner); $this->assign([$manager->id,$deputy->id]);
        self::assertSame(2,app(BindingAccessService::class)->managers()->count());
        Admin::guard()->setUser($manager); $access=app(BindingAccessService::class);
        $access->assertAccount($target); $access->assertCorp('dingcorp-test');
        $this->denied(fn()=>$access->assertAccount($outside),403); $this->denied(fn()=>$access->assertAccount($owner),403);
        self::assertSame([$this->company->id],$access->companies()->pluck('id')->all());
        $manager->roles()->detach(); self::assertFalse($access->canAccess()); $this->denied(fn()=>$access->assertAccount($target),403);
    }
    public function test_company_assignment_removal_does_not_unbind_employees(): void
    {
        $owner=$this->account(true); $manager=$this->manager(); $target=$this->memberOf($manager);
        $binding=app(BindingService::class)->bind($target->id,'user-a');
        Admin::guard()->setUser($owner); $this->assign([$manager->id]); $this->assign([]);
        Admin::guard()->setUser($manager); self::assertCount(0,app(BindingAccessService::class)->companies());
        $this->denied(fn()=>app(BindingAccessService::class)->assertCorp('dingcorp-test'),403); self::assertTrue($binding->fresh()->active);
    }
    public function test_unassigned_manager_gets_no_company_options_and_can_only_inspect_own_department_accounts(): void
    {
        $this->account(true); $manager=$this->manager(); $target=$this->memberOf($manager); $outside=$this->account();
        Admin::guard()->setUser($manager);
        $response=app(DingTalkBindingController::class)->options($target->id,app(BindingAccessService::class))->getData(true);
        self::assertSame([],$response['companies']); $this->denied(fn()=>app(DingTalkBindingController::class)->options($outside->id,app(BindingAccessService::class)),403);
    }
    public function test_account_audit_history_is_scoped_by_subject_instead_of_the_operator_account(): void
    {
        $owner=$this->account(true); $manager=$this->manager(); $target=$this->memberOf($manager); $outside=$this->account();
        $first=\App\Models\DingTalkAudit::query()->create(['action'=>'binding.created','account_id'=>$owner->id,
            'metadata'=>['target_account_id'=>$target->id,'target_name'=>'Member A'],'created_at'=>now()]);
        \App\Models\DingTalkAudit::query()->create(['action'=>'binding.removed','account_id'=>$target->id,
            'metadata'=>['target_account_id'=>$outside->id],'created_at'=>now()]);
        \App\Models\DingTalkAudit::query()->create(['action'=>'login.succeeded','account_id'=>$target->id,'metadata'=>[],'created_at'=>now()]);
        Admin::guard()->setUser($manager); $access=app(BindingAccessService::class);
        self::assertSame([$first->id],$access->accountAudits($target,'operation')->pluck('id')->all());
        self::assertSame(1,$access->accountAudits($target,'login')->count());
        $this->denied(fn()=>$access->accountAudits($outside,'operation'),403);
    }
}
