<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use App\Portals\Assistant\Http\Controllers\DomainStoreController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AssistantDomainStoreCreateRulesTest extends TestCase
{
    private function rules(string $method): array
    {
        $controller = (new \ReflectionClass(DomainStoreController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller, $method))->invoke($controller);
    }

    private function valid(): array
    {
        return [
            'shop_name' => 'Test', 'domain' => 'example.com', 'domain_check_status' => 'not_checked',
            'team' => ['Team'], 'project' => 'Project', 'shop_status' => 'in_use',
            'site_builder' => 'nbc', 'domain_expires_at' => '2027-11-21', 'email_bound' => '0',
        ];
    }

    public function test_create_requires_expiry_builder_and_explicit_email_choice(): void
    {
        $data = $this->valid();
        $data['shop_status'] = array_key_first(AssistantDomainStore::shopStatusLabels());
        $rules = $this->rules('createRules');
        self::assertFalse(Validator::make($data, $rules)->fails());
        foreach (['site_builder', 'domain_expires_at', 'email_bound'] as $field) {
            $missing = $data;
            unset($missing[$field]);
            self::assertArrayHasKey($field, Validator::make($missing, $rules)->errors()->toArray());
            $missing[$field] = '';
            self::assertArrayHasKey($field, Validator::make($missing, $rules)->errors()->toArray());
        }
        foreach (['0', '1', false, true] as $choice) {
            self::assertFalse(Validator::make(array_replace($data, ['email_bound' => $choice]), $rules)->fails());
        }
        self::assertTrue(Validator::make(array_replace($data, ['domain_expires_at' => 'invalid-date']), $rules)->fails());
    }

    public function test_edit_import_and_batch_keep_existing_optional_expiry_and_builder(): void
    {
        $data = $this->valid();
        $data['shop_status'] = array_key_first(AssistantDomainStore::shopStatusLabels());
        unset($data['site_builder'], $data['domain_expires_at']);
        self::assertFalse(Validator::make($data, $this->rules('storeRules'))->fails());
        $data['owner_user_id'] = 1;
        $data['operation_department_id'] = 1;
        self::assertFalse(Validator::make($data, $this->rules('importRules'))->fails());
    }

    private function form(string $mode, mixed $emailBound = null): \DOMXPath
    {
        app('view')->share('errors', new ViewErrorBag());
        $store = new AssistantDomainStore([
            'email_bound' => $emailBound,
            'domain_check_status' => AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED,
            'shop_status' => array_key_first(AssistantDomainStore::shopStatusLabels()),
        ]);
        $store->id = 1;
        $html = view('portals.assistant.domain-stores.form', [
            'store' => $store, 'formMode' => $mode, 'modalForm' => true,
            'canAssignStoreOwner' => false, 'canManageQuickOptions' => true, 'canManageDepartmentAccess' => false,
            'domainCheckLabels' => AssistantDomainStore::domainCheckLabels(),
            'shopStatusLabels' => AssistantDomainStore::shopStatusLabels(),
            'quickOptions' => [], 'siteBuilderOptions' => AssistantDomainStore::siteBuilderOptions(),
            'erpOptions' => [], 'creditCardOptions' => [], 'walletOptions' => [], 'paypalOptions' => [], 'otherPaymentOptions' => [],
        ])->render();
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_create_form_has_no_email_selection_and_requires_new_fields(): void
    {
        $xpath = $this->form('create');
        self::assertSame('', $xpath->evaluate('string(//input[@name="email_bound"]/@value)'));
        self::assertSame(0, $xpath->query('//input[@name="email_bound"]/../button[contains(@class,"is-selected")]')->length);
        foreach (['domain_expires_at', 'site_builder'] as $field) {
            self::assertSame(1, $xpath->query('//input[@name="'.$field.'"][@required]')->length);
            self::assertSame(1, $xpath->query('//*[@data-store-required-field="'.$field.'"]')->length);
        }
        self::assertSame(1, $xpath->query('//*[@data-store-required-field="email_bound"]')->length);
    }

    public function test_edit_keeps_existing_no_choice_and_optional_fields(): void
    {
        $xpath = $this->form('edit', false);
        self::assertSame('0', $xpath->evaluate('string(//input[@name="email_bound"]/@value)'));
        self::assertSame(1, $xpath->query('//input[@name="email_bound"]/../button[@data-option-value="0"][contains(@class,"is-selected")]')->length);
        foreach (['domain_expires_at', 'site_builder'] as $field) {
            self::assertSame(0, $xpath->query('//input[@name="'.$field.'"][@required]')->length);
            self::assertSame(0, $xpath->query('//*[@data-store-required-field="'.$field.'"]')->length);
        }
    }

    public function test_create_keeps_email_no_choice_after_validation_failure(): void
    {
        request()->setLaravelSession(session()->driver());
        session()->flashInput(['email_bound' => '0']);
        $xpath = $this->form('create');
        self::assertSame('0', $xpath->evaluate('string(//input[@name="email_bound"]/@value)'));
        self::assertSame(1, $xpath->query('//input[@name="email_bound"]/../button[@data-option-value="0"][contains(@class,"is-selected")]')->length);
    }
}
