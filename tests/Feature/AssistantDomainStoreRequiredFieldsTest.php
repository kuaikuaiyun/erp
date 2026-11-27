<?php

namespace Tests\Feature;

use App\Portals\Assistant\AssistantDomainStoreSpreadsheetService;
use App\Portals\Assistant\Http\Controllers\DomainStoreController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AssistantDomainStoreRequiredFieldsTest extends TestCase
{
    private function rules(string $method): array
    {
        $controller = (new \ReflectionClass(DomainStoreController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller, $method))->invoke($controller);
    }

    private function readRows(array $records, bool $legacy = false, bool $xlsx = false): array
    {
        $service = app(AssistantDomainStoreSpreadsheetService::class);
        $fields = (new \ReflectionClass($service))->getConstant('IMPORT_FIELDS');
        $headers = $service->importHeaders();
        if ($legacy) {
            foreach (['team', 'project'] as $field) {
                $headers[array_search($field, $fields, true)] = __('assistant.domain_stores.fields.'.$field);
            }
        }
        $rows = [$headers];
        foreach ($records as $record) {
            $data = array_replace([
                'site_builder' => 'shopify', 'shop_name' => 'Test Store', 'domain' => 'test.example',
                'domain_expires_at' => '2027-01-01', 'domain_check_status' => 'not_checked',
                'email_bound' => '否', 'shop_status' => '在用', 'team' => 'Team A', 'project' => 'Project A',
                'user_name' => 'User A', 'owner_user_id' => 1, 'operation_department_id' => 1,
            ], $record);
            $rows[] = array_map(fn ($field) => $data[$field] ?? '', $fields);
        }
        $path = tempnam(sys_get_temp_dir(), 'erp-required-');
        try {
            if ($xlsx) {
                $book = new Spreadsheet();
                $book->getActiveSheet()->fromArray($rows);
                (new Xlsx($book))->save($path);
                $book->disconnectWorksheets();
            } else {
                $stream = fopen($path, 'wb');
                foreach ($rows as $row) fputcsv($stream, $row);
                fclose($stream);
            }
            return $service->readImportFile(new UploadedFile($path, $xlsx ? 'stores.xlsx' : 'stores.csv', null, null, true));
        } finally {
            unlink($path);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('zh-CN');
    }

    public function test_new_and_legacy_headers_both_read_valid_csv_and_xlsx_rows(): void
    {
        foreach ([false, true] as $legacy) {
            foreach ([false, true] as $xlsx) {
                $rows = $this->readRows([['team' => 'Team A,Team B']], $legacy, $xlsx);
                self::assertSame(['Team A', 'Team B'], $rows[0]['data']['team']);
                self::assertSame('Project A', $rows[0]['data']['project']);
                self::assertFalse(Validator::make($rows[0]['data'], $this->rules('importRules'))->fails());
            }
        }
        $service = app(AssistantDomainStoreSpreadsheetService::class);
        self::assertContains('团队（必填）', $service->importHeaders());
        self::assertContains('项目（必填）', $service->importHeaders());
        $exportHeaders = (new \ReflectionMethod($service, 'exportHeaders'))->invoke($service);
        self::assertContains('团队', $exportHeaders);
        self::assertContains('项目', $exportHeaders);
        self::assertNotContains('团队（必填）', $exportHeaders);
    }

    public function test_mixed_import_rows_report_each_missing_field_and_keep_valid_rows_valid(): void
    {
        $rows = $this->readRows([
            [], ['team' => ''], ['project' => ''], ['project' => '   '], ['team' => ', ,'], [],
        ]);
        $invalid = [];
        foreach ($rows as $row) {
            $validator = Validator::make($row['data'], $this->rules('importRules'));
            if ($validator->fails()) $invalid[$row['row']] = array_keys($validator->failed());
        }
        self::assertSame([3 => ['team'], 4 => ['project'], 5 => ['project'], 6 => ['team']], $invalid);
        self::assertCount(6, $rows);
    }

    public function test_normal_save_and_import_both_require_team_and_project(): void
    {
        $row = $this->readRows([['team' => '', 'project' => '']])[0]['data'];
        self::assertTrue(Validator::make($row, $this->rules('storeRules'))->fails());
        self::assertTrue(Validator::make($row, $this->rules('importRules'))->fails());
    }

    public function test_import_matches_create_requirements_but_allows_empty_ownership(): void
    {
        foreach ([
            'site_builder', 'shop_name', 'domain', 'domain_expires_at',
            'domain_check_status', 'email_bound', 'team', 'project', 'shop_status',
        ] as $field) {
            $row = $this->readRows([[$field => '']])[0]['data'];
            $validator = Validator::make($row, $this->rules('importRules'));
            self::assertTrue($validator->fails(), $field);
            self::assertArrayHasKey($field, $validator->failed());
        }
        $row = $this->readRows([['owner_user_id' => '', 'operation_department_id' => '']])[0]['data'];
        self::assertFalse(Validator::make($row, $this->rules('importRules'))->fails());

        foreach ([
            ['owner_user_id' => 1, 'operation_department_id' => ''],
            ['owner_user_id' => '', 'operation_department_id' => 1],
        ] as $ownership) {
            self::assertTrue(Validator::make(
                array_replace($row, $ownership),
                $this->rules('importRules'),
            )->fails());
        }
        $method = new \ReflectionMethod(DomainStoreController::class, 'batchRulesForFields');
        $controller = (new \ReflectionClass(DomainStoreController::class))->newInstanceWithoutConstructor();
        self::assertFalse(Validator::make(['user_name' => null], $method->invoke($controller, ['user_name']))->fails());
        self::assertArrayNotHasKey('team', $method->invoke($controller, ['remark']));
    }

    public function test_user_is_optional_for_save_and_import(): void
    {
        foreach ([false, true] as $xlsx) {
            $row = $this->readRows([['user_name' => '']], false, $xlsx)[0]['data'];
            foreach (['storeRules', 'importRules'] as $method) {
                foreach (['', null] as $value) {
                    self::assertFalse(Validator::make(array_replace($row, ['user_name' => $value]), $this->rules($method))->fails());
                }
            }
        }
        $headers = app(AssistantDomainStoreSpreadsheetService::class)->importHeaders();
        self::assertContains(__('assistant.domain_stores.fields.user_name'), $headers);
        self::assertNotContains(__('assistant.domain_stores.import.required_header', ['header' => __('assistant.domain_stores.fields.user_name')]), $headers);
        foreach (['site_builder', 'domain_expires_at'] as $field) {
            self::assertContains(
                __('assistant.domain_stores.import.required_header', [
                    'header' => __('assistant.domain_stores.fields.'.$field),
                ]),
                $headers,
            );
        }
        foreach (['owner_user_id', 'operation_department_id'] as $field) {
            $label = $field === 'owner_user_id' ? '店铺归属人ID' : '运营归属部门ID';
            self::assertContains($label, $headers);
            self::assertNotContains(
                __('assistant.domain_stores.import.required_header', ['header' => $label]),
                $headers,
            );
        }
        $source = file_get_contents(resource_path('views/portals/assistant/domain-stores/form.blade.php'));
        self::assertStringNotContainsString('data-store-required-field="user_name"', $source);
    }

    public function test_optional_import_columns_may_be_omitted_from_the_workbook(): void
    {
        $service = app(AssistantDomainStoreSpreadsheetService::class);
        $reflection = new \ReflectionClass($service);
        $requiredFields = $reflection->getConstant('REQUIRED_IMPORT_FIELDS');
        $allFields = $reflection->getConstant('IMPORT_FIELDS');
        $allHeaders = array_combine($allFields, $service->importHeaders());
        $headers = array_map(static fn (string $field): string => $allHeaders[$field], $requiredFields);

        $map = (new \ReflectionMethod($service, 'headerMap'))->invoke($service, $headers);

        self::assertSame($requiredFields, array_values($map));
        self::assertNotContains('owner_user_id', $map);
        self::assertNotContains('operation_department_id', $map);
    }

    public function test_download_template_matches_import_validation_rules(): void
    {
        $response = app(AssistantDomainStoreSpreadsheetService::class)->templateResponse();
        ob_start();
        ($response->getCallback())();
        $contents = ob_get_clean();
        self::assertIsString($contents);

        $path = tempnam(sys_get_temp_dir(), 'erp-domain-template-');
        try {
            file_put_contents($path, $contents);
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
            $headers = app(AssistantDomainStoreSpreadsheetService::class)->importHeaders();
            self::assertSame($headers, $sheet->rangeToArray('A1:AE1', null, true, true, false)[0]);

            $optionSheet = $book->getSheetByName('_import_options');
            self::assertNotNull($optionSheet);
            self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $optionSheet->getSheetState());

            $siteBuilder = $sheet->getDataValidation('A2');
            self::assertSame(DataValidation::TYPE_LIST, $siteBuilder->getType());
            self::assertFalse($siteBuilder->getAllowBlank());
            self::assertStringStartsWith('DomainStoreImportOption', $siteBuilder->getFormula1());

            $team = $sheet->getDataValidation('H2');
            self::assertSame(DataValidation::TYPE_LIST, $team->getType());
            self::assertSame(DataValidation::STYLE_INFORMATION, $team->getErrorStyle());
            self::assertFalse($team->getAllowBlank());

            $domainExpiry = $sheet->getDataValidation('D2');
            self::assertSame(DataValidation::TYPE_DATE, $domainExpiry->getType());
            self::assertFalse($domainExpiry->getAllowBlank());
            self::assertSame('DATE(2000,1,1)', $domainExpiry->getFormula1());

            $planExpiry = $sheet->getDataValidation('T2');
            self::assertSame(DataValidation::TYPE_DATE, $planExpiry->getType());
            self::assertTrue($planExpiry->getAllowBlank());

            foreach (['K2', 'L2'] as $cell) {
                $ownership = $sheet->getDataValidation($cell);
                self::assertSame(DataValidation::TYPE_WHOLE, $ownership->getType());
                self::assertTrue($ownership->getAllowBlank());
                self::assertSame('1', $ownership->getFormula1());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_forms_compile_and_batch_requirements_only_apply_to_selected_fields(): void
    {
        foreach (['form', 'index', 'partials.batch-edit-form'] as $view) {
            $source = file_get_contents(resource_path('views/portals/assistant/domain-stores/'.str_replace('.', '/', $view).'.blade.php'));
            $compiled = app('blade.compiler')->compileString($source);
            self::assertNotEmpty(token_get_all($compiled, TOKEN_PARSE));
        }
        foreach ([['team', 'project'], ['remark']] as $fields) {
            $html = view('portals.assistant.domain-stores.partials.batch-edit-form', [
                'selectedIds' => [1], 'selectedCount' => 1, 'selectedFields' => $fields, 'quickOptions' => [],
            ])->render();
            foreach (['team', 'project'] as $field) {
                if (in_array($field, $fields, true)) {
                    self::assertStringContainsString('data-store-required-field="'.$field.'"', $html);
                    self::assertStringContainsString('请填写或选择', $html);
                } else {
                    self::assertStringNotContainsString('data-store-required-field="'.$field.'"', $html);
                }
            }
        }
    }
}
