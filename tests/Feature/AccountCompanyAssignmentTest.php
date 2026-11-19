<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Admin\Controllers\Organization\AccountController;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountCompanyAssignmentTest extends TestCase
{
    use DingTalkTestEnvironment { setUp as protected setUpAuthentication; }

    protected function setUp(): void
    {
        $this->setUpAuthentication();
        (require database_path('migrations/2026_11_19_000100_add_company_id_to_admin_users.php'))->up();
    }

    public function test_batch_updates_only_selected_accounts_and_repeated_submission_is_idempotent(): void
    {
        $actor = $this->account(true); $a = $this->account(); $b = $this->account(); $untouched = $this->account();
        $request = $this->browser(); Admin::guard()->login($actor);
        $request->merge(['account_ids'=>[$a->id, $b->id], 'company_id'=>$this->company->id]);
        $pivotBefore = DB::table('admin_role_users')->get()->toJson();
        $controller = app(AccountController::class);
        $result = $controller->assignCompany($request)->getData(true);
        self::assertSame(2, $result['changed']);
        self::assertSame($this->company->id, $a->fresh()->company_id);
        self::assertSame($this->company->id, $b->fresh()->company_id);
        self::assertNull($untouched->fresh()->company_id);
        self::assertSame(0, $controller->assignCompany($request)->getData(true)['changed']);
        self::assertSame($pivotBefore, DB::table('admin_role_users')->get()->toJson());
        self::assertSame(0, DB::table('dingtalk_bindings')->count());
    }

    public function test_missing_account_causes_the_entire_batch_to_fail(): void
    {
        $actor = $this->account(true); $a = $this->account();
        $request = $this->browser(); Admin::guard()->login($actor);
        $request->merge(['account_ids'=>[$a->id, 999999], 'company_id'=>$this->company->id]);
        try { app(AccountController::class)->assignCompany($request); self::fail('Missing account accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('account_ids', $e->errors()); }
        self::assertNull($a->fresh()->company_id);
    }

    public function test_disabled_archived_and_nonexistent_targets_are_rejected(): void
    {
        $actor = $this->account(true); $a = $this->account();
        $request = $this->browser(); Admin::guard()->login($actor);
        $request->merge(['account_ids'=>[$a->id], 'company_id'=>$this->company->id]);
        $this->company->update(['enabled'=>false]);
        try { app(AccountController::class)->assignCompany($request); self::fail('Disabled company accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('company_id', $e->errors()); }
        $this->company->update(['enabled'=>true]); $this->company->delete();
        try { app(AccountController::class)->assignCompany($request); self::fail('Archived company accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('company_id', $e->errors()); }
        $request->merge(['company_id'=>999999]);
        try { app(AccountController::class)->assignCompany($request); self::fail('Missing company accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('company_id', $e->errors()); }
        self::assertNull($a->fresh()->company_id);
    }

    public function test_non_administrator_cannot_preview_or_submit_assignment(): void
    {
        $actor = $this->account(); $request = $this->browser(); Admin::guard()->login($actor);
        $request->merge(['account_ids'=>[$actor->id], 'company_id'=>$this->company->id]);
        $controller = app(AccountController::class);
        $this->denied(fn ()=>$controller->companyAssignmentOptions($request), 403);
        $this->denied(fn ()=>$controller->assignCompany($request), 403);
        self::assertNull($actor->fresh()->company_id);
    }

    public function test_empty_and_duplicate_selections_are_rejected_and_preview_has_no_credentials(): void
    {
        $actor = $this->account(true); $request = $this->browser(); Admin::guard()->login($actor);
        $controller = app(AccountController::class);
        foreach ([[], [$actor->id, $actor->id]] as $ids) {
            $request->merge(['account_ids'=>$ids, 'company_id'=>$this->company->id]);
            try { $controller->assignCompany($request); self::fail('Invalid selection accepted'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
        $request->merge(['account_ids'=>[$actor->id]]);
        $data = $controller->companyAssignmentOptions($request)->getData(true);
        self::assertSame(['id','name'], array_keys($data['companies'][0]));
        self::assertSame($actor->id, $data['accounts'][0]['id']);
    }
}
