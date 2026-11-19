<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Admin\Controllers\Organization\AccountController;
use App\Models\AdminUser;
use App\Models\DingTalkCompany;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AccountCompanyAffiliationTest extends TestCase
{
    use DingTalkTestEnvironment { setUp as protected setUpAuthentication; }

    protected function setUp(): void
    {
        $this->setUpAuthentication();
        (require database_path('migrations/2026_11_19_000100_add_company_id_to_admin_users.php'))->up();
    }

    private function valid($value, ?AdminUser $account = null): bool
    {
        $controller = app(AccountController::class);
        $rules = (new \ReflectionMethod($controller, 'accountRules'))->invoke($controller, $account);
        return Validator::make(['company_id' => $value], ['company_id' => $rules['company_id']])->passes();
    }

    public function test_nullable_affiliation_and_enabled_company_can_be_saved_and_loaded(): void
    {
        $account = $this->account();
        self::assertNull($account->fresh()->company_id);
        self::assertTrue($this->valid(null));
        self::assertTrue($this->valid($this->company->id));
        $account->company_id = $this->company->id;
        $account->save();
        self::assertSame($this->company->id, $account->fresh()->company_id);
        self::assertSame($this->company->id, $account->fresh()->company->id);
        $account->company_id = null;
        $account->save();
        self::assertNull($account->fresh()->company_id);
    }

    public function test_invalid_and_multiple_values_are_rejected(): void
    {
        self::assertFalse($this->valid(999999));
        self::assertFalse($this->valid('invalid'));
        self::assertFalse($this->valid([$this->company->id]));
    }

    public function test_inactive_company_can_only_be_retained_by_its_existing_account(): void
    {
        $account = $this->account();
        $account->company_id = $this->company->id;
        $account->save();
        $this->company->update(['enabled' => false]);
        self::assertFalse($this->valid($this->company->id));
        self::assertTrue($this->valid($this->company->id, $account));
        $this->company->delete();
        self::assertFalse($this->valid($this->company->id));
        self::assertTrue($this->valid($this->company->id, $account));
        self::assertSame($this->company->id, $account->fresh()->company->id);
    }

    public function test_options_include_only_enabled_companies_and_the_current_archived_company(): void
    {
        $account = $this->account();
        $account->company_id = $this->company->id;
        $account->save();
        $this->company->delete();
        $controller = app(AccountController::class);
        $method = new \ReflectionMethod($controller, 'companyOptions');
        self::assertArrayNotHasKey($this->company->id, $method->invoke($controller));
        $options = $method->invoke($controller, $account);
        self::assertArrayHasKey($this->company->id, $options);
        self::assertStringContainsString(__('organization.accounts.company_archived'), $options[$this->company->id]);
    }
}
