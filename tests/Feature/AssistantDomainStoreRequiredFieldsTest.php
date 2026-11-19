<?php

namespace Tests\Feature;

use App\Portals\Assistant\AssistantDomainStoreSpreadsheetService;
use App\Portals\Assistant\Http\Controllers\DomainStoreController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
                'shop_name' => 'Test Store', 'domain' => 'test.example', 'domain_check_status' => 'not_checked',
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

    public function test_normal_save_and_import_both_require_team_project_and_user(): void
    {
        $row = $this->readRows([['team' => '', 'project' => '']])[0]['data'];
        self::assertTrue(Validator::make($row, $this->rules('storeRules'))->fails());
        self::assertTrue(Validator::make($row, $this->rules('importRules'))->fails());
    }

    public function test_import_requires_owner_and_department_and_all_four_fields(): void
    {
        foreach (['team', 'project', 'user_name', 'owner_user_id', 'operation_department_id'] as $field) {
            $row = $this->readRows([[$field => '']])[0]['data'];
            $validator = Validator::make($row, $this->rules('importRules'));
            self::assertTrue($validator->fails(), $field);
            self::assertArrayHasKey($field, $validator->failed());
        }
        $method = new \ReflectionMethod(DomainStoreController::class, 'batchRulesForFields');
        $controller = (new \ReflectionClass(DomainStoreController::class))->newInstanceWithoutConstructor();
        self::assertTrue(Validator::make(['user_name' => ' '], $method->invoke($controller, ['user_name']))->fails());
        self::assertArrayNotHasKey('team', $method->invoke($controller, ['remark']));
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
