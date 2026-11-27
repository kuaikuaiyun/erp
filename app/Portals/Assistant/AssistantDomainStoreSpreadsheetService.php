<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreQuickOption;
use App\Models\Department;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantDomainStoreSpreadsheetService
{
    private const TEMPLATE_DROPDOWN_LAST_ROW = 2001;

    private const EXPORT_FIELDS = [
        'site_builder',
        'shop_name',
        'domain',
        'domain_expires_at',
        'domain_check_status',
        'email',
        'email_bound',
        'email_purchased',
        'team',
        'project',
        'owner_user',
        'user_name',
        'erp',
        'credit_card',
        'wallet',
        'paypal',
        'other_payment',
        'shop_login_url',
        'shop_status',
        'plan_expires_at',
        'payment_card',
        'remark',
        'store_prefix',
        'tongtu_prefix',
        'shopify_client_id',
        'shopify_client_secret',
    ];

    private const IMPORT_FIELDS = [
        'site_builder',
        'shop_name',
        'domain',
        'domain_expires_at',
        'domain_check_status',
        'email',
        'email_bound',
        'team',
        'project',
        'user_name',
        'owner_user_id',
        'operation_department_id',
        'erp',
        'credit_card',
        'wallet',
        'paypal',
        'other_payment',
        'shop_login_url',
        'shop_status',
        'plan_expires_at',
        'payment_card',
        'remark',
        'store_prefix',
        'tongtu_prefix',
        'shopify_client_id',
        'shopify_client_secret',
        'xshoppy_api_key',
        'xshoppy_password',
        'shopline_app_token',
        'nbc_app_token',
        'shopyy_app_token',
    ];

    private const REQUIRED_IMPORT_FIELDS = [
        'site_builder',
        'team',
        'project',
        'shop_name',
        'domain',
        'domain_expires_at',
        'domain_check_status',
        'email_bound',
        'shop_status',
    ];

    /** @return list<string> */
    public function importHeaders(): array
    {
        return array_map(fn (string $field): string => $this->importHeaderLabel($field), self::IMPORT_FIELDS);
    }

    /** @return list<string> */
    private function exportHeaders(): array
    {
        return array_map(
            fn (string $field): string => $field === 'owner_user'
                ? __('assistant.domain_stores.export.owner_user')
                : (in_array($field, ['team', 'project'], true)
                    ? AssistantDomainStore::fieldLabel($field)
                    : $this->importHeaderLabel($field)),
            self::EXPORT_FIELDS,
        );
    }

    public function exportResponse(iterable $stores): StreamedResponse
    {
        $headers = [
            __('assistant.domain_stores.fields.date'),
            ...$this->exportHeaders(),
            __('assistant.domain_stores.fields.last_operator'),
            __('assistant.domain_stores.fields.operation_time'),
        ];
        $rows = [];

        foreach ($stores as $store) {
            $rows[] = $this->exportRow($store);
        }

        return $this->spreadsheetResponse(
            $headers,
            $rows,
            __('assistant.domain_stores.import.export_file_name', [
                'timestamp' => now('Asia/Shanghai')->format('Ymd-His'),
            ]),
        );
    }

    public function templateResponse(): StreamedResponse
    {
        return $this->spreadsheetResponse(
            $this->importHeaders(),
            [],
            __('assistant.domain_stores.import.template_file_name'),
            $this->templateDropdownOptions(),
        );
    }

    /**
     * @return list<array{row: int, data: array<string, mixed>}>
     */
    public function readImportFile(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (!in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.invalid_extension'));
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.unreadable'));
        }

        $rows = $sheet->toArray(null, false, true, false);

        if ($rows === []) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.empty_file'));
        }

        $headers = array_shift($rows);

        if (!is_array($headers)) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.missing_headers'));
        }

        $headerMap = $this->headerMap($headers);

        $importRows = [];

        foreach ($rows as $index => $row) {
            $data = $this->defaults();

            foreach ($headerMap as $column => $field) {
                $value = $this->importValue($field, $row[$column] ?? null);

                if ($value !== null || !$this->hasDefaultValue($field)) {
                    $data[$field] = $value;
                }
            }

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $importRows[] = [
                'row' => $index + 2,
                'data' => $data,
            ];
        }

        if ($importRows === []) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.no_records'));
        }

        if (count($importRows) > 2000) {
            throw new InvalidArgumentException(__('assistant.domain_stores.import.too_many_records'));
        }

        return $this->resolveOwnershipReferences($importRows);
    }

    /**
     * Import templates are maintained by people, so ownership columns commonly
     * contain the visible user/department names instead of database IDs. Resolve
     * both forms here and leave unknown or ambiguous values untouched so the
     * normal row validation can report them without aborting the whole import.
     *
     * @param  list<array{row: int, data: array<string, mixed>}>  $rows
     *
     * @return list<array{row: int, data: array<string, mixed>}>
     */
    private function resolveOwnershipReferences(array $rows): array
    {
        $departmentNames = collect($rows)
            ->pluck('data.operation_department_id')
            ->filter(fn (mixed $value): bool => $this->isTextReference($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->unique(fn (string $value): string => $this->normalizeReference($value))
            ->values();
        $ownerNames = collect($rows)
            ->pluck('data.owner_user_id')
            ->filter(fn (mixed $value): bool => $this->isTextReference($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->unique(fn (string $value): string => $this->normalizeReference($value))
            ->values();

        $departmentsByName = $departmentNames->isEmpty()
            ? collect()
            : Department::business()
                ->whereIn('name', $departmentNames->all())
                ->get(['id', 'name'])
                ->groupBy(fn (Department $department): string => $this->normalizeReference($department->name));
        $owners = $ownerNames->isEmpty()
            ? collect()
            : AdminUser::query()
                ->with('departments:id,name')
                ->where(function ($query) use ($ownerNames): void {
                    $query->whereIn('name', $ownerNames->all())
                        ->orWhereIn('username', $ownerNames->all());
                })
                ->get(['id', 'name', 'username']);
        $ownersByName = collect();
        foreach ($owners as $owner) {
            foreach (array_unique([$owner->name, $owner->username]) as $reference) {
                if (filled($reference)) {
                    $key = $this->normalizeReference($reference);
                    $ownersByName->put($key, $ownersByName->get($key, collect())->push($owner));
                }
            }
        }

        return collect($rows)->map(function (array $row) use ($departmentsByName, $ownersByName): array {
            $data = $row['data'];
            $departmentId = null;
            $departmentReference = $data['operation_department_id'] ?? null;

            if ($this->isTextReference($departmentReference)) {
                $matches = $departmentsByName->get($this->normalizeReference($departmentReference), collect());
                if ($matches->count() === 1) {
                    $departmentId = (int) $matches->first()->getKey();
                    $data['operation_department_id'] = $departmentId;
                }
            } elseif (filled($departmentReference) && is_numeric($departmentReference)) {
                $departmentId = (int) $departmentReference;
            }

            $ownerReference = $data['owner_user_id'] ?? null;
            if ($this->isTextReference($ownerReference)) {
                $reference = $this->normalizeReference($ownerReference);
                $matches = $ownersByName->get($reference, collect())
                    ->filter(function (AdminUser $owner) use ($reference): bool {
                        return $this->normalizeReference($owner->name) === $reference
                            || $this->normalizeReference($owner->username) === $reference;
                    });

                if ($departmentId !== null) {
                    $matches = $matches->filter(
                        fn (AdminUser $owner): bool => $owner->departments->contains('id', $departmentId),
                    );
                }

                if ($matches->count() === 1) {
                    $data['owner_user_id'] = (int) $matches->first()->getKey();
                }
            }

            $row['data'] = $data;

            return $row;
        })->all();
    }

    private function isTextReference(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && !ctype_digit(trim($value));
    }

    private function normalizeReference(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /** @return list<mixed> */
    private function exportRow(AssistantDomainStore $store): array
    {
        return [
            $store->created_at?->timezone('Asia/Shanghai')->format('Y-m-d'),
            $store->site_builder,
            $store->shop_name,
            $store->domain,
            $store->domain_expires_at?->format('Y-m-d'),
            AssistantDomainStore::domainCheckLabels()[$store->domain_check_status] ?? $store->domain_check_status,
            $store->email,
            $store->email_bound ? __('assistant.domain_stores.common.yes') : __('assistant.domain_stores.common.no'),
            $store->email_purchased ? __('assistant.domain_stores.common.yes') : __('assistant.domain_stores.common.no'),
            AssistantDomainStore::displayMultiValue($store->team),
            $store->project,
            $store->ownerUser?->name ?: $store->ownerUser?->username ?: '',
            $store->user_name,
            AssistantDomainStore::displayMultiValue($store->erp),
            $store->credit_card,
            $store->wallet,
            $store->paypal,
            $store->other_payment,
            $store->shop_login_url,
            AssistantDomainStore::shopStatusLabels()[$store->shop_status] ?? $store->shop_status,
            $store->plan_expires_at?->format('Y-m-d'),
            $store->payment_card,
            $store->remark,
            $store->store_prefix,
            $store->tongtu_prefix,
            $store->shopify_client_id,
            $store->shopify_client_secret,
            $store->last_operator_name,
            $store->updated_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 导入列以表头名称映射，不依赖 Excel 中的列位置。
     *
     * @param list<mixed> $headers
     * @return array<int, string>
     */
    private function headerMap(array $headers): array
    {
        $map = [];
        $seenFields = [];
        $duplicateHeaders = [];
        $headersByLabel = $this->importHeadersByLabel();
        $headersByLabel[__('assistant.domain_stores.import.legacy_headers.domain_check_status')] = 'domain_check_status';

        foreach ($headers as $column => $header) {
            $label = $this->normalizeHeader($header);

            if ($label === '') {
                continue;
            }

            if (!array_key_exists($label, $headersByLabel)) {
                // Allow supplementary user-maintained columns, but never map them into system data.
                continue;
            }

            $field = $headersByLabel[$label];

            if (isset($seenFields[$field])) {
                $duplicateHeaders[] = $label;

                continue;
            }

            $seenFields[$field] = true;
            $map[$column] = $field;
        }

        // Optional columns may be removed from a user-maintained template. Only
        // fields required by the create form must be present in the worksheet.
        $missingFields = array_values(array_diff(self::REQUIRED_IMPORT_FIELDS, array_keys($seenFields)));
        $missingHeaders = array_map(fn (string $field): string => $this->importHeaderLabel($field), $missingFields);

        if ($missingHeaders !== [] || $duplicateHeaders !== []) {
            $problems = [];

            if ($missingHeaders !== []) {
                $problems[] = __('assistant.domain_stores.import.header_missing', [
                    'headers' => implode('、', $missingHeaders),
                ]);
            }

            if ($duplicateHeaders !== []) {
                $problems[] = __('assistant.domain_stores.import.header_duplicate', [
                    'headers' => implode('、', array_values(array_unique($duplicateHeaders))),
                ]);
            }

            throw new InvalidArgumentException(
                __('assistant.domain_stores.import.header_invalid', [
                    'problems' => implode('；', $problems),
                ]),
            );
        }

        return $map;
    }

    /** @return array<string, string> */
    private function importHeadersByLabel(): array
    {
        $headers = [];

        foreach (self::IMPORT_FIELDS as $field) {
            $headers[$this->importHeaderLabel($field)] = $field;
            $baseLabel = $this->importBaseHeaderLabel($field);
            $headers[$baseLabel] = $field;
            // Keep templates downloaded before required-field changes importable.
            $headers[__('assistant.domain_stores.import.required_header', ['header' => $baseLabel])] = $field;
        }

        return $headers;
    }

    private function importHeaderLabel(string $field): string
    {
        $label = $this->importBaseHeaderLabel($field);

        return in_array($field, self::REQUIRED_IMPORT_FIELDS, true)
            ? __('assistant.domain_stores.import.required_header', ['header' => $label])
            : $label;
    }

    private function importBaseHeaderLabel(string $field): string
    {
        return match ($field) {
            'user_name' => __('assistant.domain_stores.fields.user_name'),
            'owner_user_id' => '店铺归属人ID',
            'operation_department_id' => '运营归属部门ID',
            'xshoppy_api_key' => 'XShoppy API Key',
            'xshoppy_password' => 'XShoppy Password',
            'shopline_app_token' => 'Shopline App Token',
            'nbc_app_token' => 'NBC App Token',
            'shopyy_app_token' => 'Shopyy App Token',
            default => AssistantDomainStore::fieldLabel($field),
        };
    }

    private function normalizeHeader(mixed $header): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', (string) $header));
    }

    /** @return array<string, list<string>> */
    private function templateDropdownOptions(): array
    {
        $quickOptions = AssistantDomainStoreQuickOption::valuesByField();

        return [
            $this->importHeaderLabel('site_builder') => $this->availableOptionLabels('site_builder', AssistantDomainStore::siteBuilderOptions(), $quickOptions),
            $this->importHeaderLabel('domain_check_status') => $this->templateDomainCheckOptions(),
            $this->importHeaderLabel('email_bound') => [__('assistant.domain_stores.common.yes'), __('assistant.domain_stores.common.no')],
            $this->importHeaderLabel('team') => $this->availableOptionLabels('team', [], $quickOptions),
            $this->importHeaderLabel('project') => $this->availableOptionLabels('project', [], $quickOptions),
            $this->importHeaderLabel('erp') => $this->availableOptionLabels('erp', AssistantDomainStore::erpOptions(), $quickOptions),
            $this->importHeaderLabel('credit_card') => $this->availableOptionLabels('credit_card', AssistantDomainStore::creditCardOptions(), $quickOptions),
            $this->importHeaderLabel('wallet') => $this->availableOptionLabels('wallet', AssistantDomainStore::walletOptions(), $quickOptions),
            $this->importHeaderLabel('paypal') => $this->availableOptionLabels('paypal', AssistantDomainStore::paypalOptions(), $quickOptions),
            $this->importHeaderLabel('other_payment') => $this->availableOptionLabels('other_payment', AssistantDomainStore::otherPaymentOptions(), $quickOptions),
            $this->importHeaderLabel('shop_status') => array_values(AssistantDomainStore::shopStatusLabels()),
        ];
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, list<string>> $quickOptions
     * @return list<string>
     */
    private function availableOptionLabels(string $field, array $labels, array $quickOptions): array
    {
        return collect([...array_keys($labels), ...($quickOptions[$field] ?? [])])
            ->map(static fn (string $value): string => $labels[$value] ?? $value)
            ->filter(static fn (string $value): bool => trim($value) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function templateDomainCheckOptions(): array
    {
        $labels = AssistantDomainStore::domainCheckLabels();

        return [
            $labels[AssistantDomainStore::DOMAIN_CHECK_NORMAL],
            $labels[AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED],
            $labels[AssistantDomainStore::DOMAIN_CHECK_ABNORMAL],
        ];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'site_builder' => null,
            'shop_name' => null,
            'domain' => null,
            'domain_expires_at' => null,
            'domain_check_status' => null,
            'email' => null,
            'email_bound' => null,
            'email_purchased' => false,
            'team' => null,
            'project' => null,
            'user_name' => null,
            'erp' => null,
            'credit_card' => null,
            'wallet' => null,
            'paypal' => null,
            'other_payment' => null,
            'shop_login_url' => null,
            'store_prefix' => null,
            'tongtu_prefix' => null,
            'shopify_client_id' => null,
            'shopify_client_secret' => null,
            'xshoppy_api_key' => null,
            'xshoppy_password' => null,
            'shopline_app_token' => null,
            'nbc_app_token' => null,
            'shopyy_app_token' => null,
            'shop_status' => null,
            'plan_expires_at' => null,
            'payment_card' => null,
            'remark' => null,
        ];
    }

    private function hasDefaultValue(string $field): bool
    {
        return $field === 'email_purchased';
    }

    private function importValue(string $field, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (in_array($field, ['domain_expires_at', 'plan_expires_at'], true)
            && is_numeric($value)
            && (float) $value > 0) {
            $value = SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $value = is_string($value) ? trim($value) : $value;

        if ($value === '') {
            return null;
        }

        return match ($field) {
            'team', 'erp' => AssistantDomainStore::normalizeMultiValue($value),
            'site_builder' => $this->mapOptionLabel($value, AssistantDomainStore::siteBuilderOptions()),
            'credit_card' => $this->mapOptionLabel($value, AssistantDomainStore::creditCardOptions()),
            'wallet' => $this->mapOptionLabel($value, AssistantDomainStore::walletOptions()),
            'paypal' => $this->mapOptionLabel($value, AssistantDomainStore::paypalOptions()),
            'other_payment' => $this->mapOptionLabel($value, AssistantDomainStore::otherPaymentOptions()),
            'domain_check_status' => $this->mapDomainCheckStatus($value),
            'shop_status' => $this->mapEnum($value, [
                AssistantDomainStore::shopStatusLabels()[AssistantDomainStore::SHOP_STATUS_IN_USE] => AssistantDomainStore::SHOP_STATUS_IN_USE,
                AssistantDomainStore::shopStatusLabels()[AssistantDomainStore::SHOP_STATUS_ABANDONED] => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                AssistantDomainStore::shopStatusLabels()[AssistantDomainStore::SHOP_STATUS_STOPPED] => AssistantDomainStore::SHOP_STATUS_STOPPED,
                'in_use' => AssistantDomainStore::SHOP_STATUS_IN_USE,
                'abandoned' => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                'stopped' => AssistantDomainStore::SHOP_STATUS_STOPPED,
                __('assistant.domain_stores.import.aliases.enabled') => AssistantDomainStore::SHOP_STATUS_IN_USE,
                __('assistant.domain_stores.import.aliases.closed') => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                __('assistant.domain_stores.import.aliases.idle') => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                __('assistant.domain_stores.import.aliases.banned') => AssistantDomainStore::SHOP_STATUS_STOPPED,
                'enabled' => AssistantDomainStore::SHOP_STATUS_IN_USE,
                'closed' => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                'idle' => AssistantDomainStore::SHOP_STATUS_ABANDONED,
                'banned' => AssistantDomainStore::SHOP_STATUS_STOPPED,
            ]),
            'email_bound', 'email_purchased' => $this->mapBoolean($value, [
                __('assistant.domain_stores.import.aliases.bound'),
                __('assistant.domain_stores.import.aliases.purchased'),
                __('assistant.domain_stores.common.yes'),
                '1',
                'true',
            ]),
            default => $value,
        };
    }

    private function mapEnum(mixed $value, array $map): mixed
    {
        return $map[(string) $value] ?? $value;
    }

    private function mapDomainCheckStatus(mixed $value): mixed
    {
        $value = (string) $value;
        $labels = AssistantDomainStore::domainCheckLabels();

        if (array_key_exists($value, $labels)) {
            return $value;
        }

        $mappedOption = array_search($value, $labels, true);

        if ($mappedOption !== false) {
            return $mappedOption;
        }

        return $this->mapEnum($value, [
            __('assistant.domain_stores.common.yes') => AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
            __('assistant.domain_stores.common.no') => AssistantDomainStore::DOMAIN_CHECK_NORMAL,
            __('assistant.domain_stores.import.aliases.blocked') => AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
            'yes' => AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
            'no' => AssistantDomainStore::DOMAIN_CHECK_NORMAL,
            'blocked' => AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
        ]);
    }

    /** @param array<string, string> $options */
    private function mapOptionLabel(mixed $value, array $options): mixed
    {
        $optionValue = array_search((string) $value, $options, true);

        return $optionValue === false ? $value : $optionValue;
    }

    private function mapBoolean(mixed $value, array $truthy): mixed
    {
        $normalized = strtolower(trim((string) $value));
        $normalizedTruthy = array_map(static fn (string $item): string => strtolower($item), $truthy);

        if (in_array($normalized, $normalizedTruthy, true)) {
            return true;
        }

        if (in_array($normalized, array_map('strtolower', [
            __('assistant.domain_stores.import.aliases.unbound'),
            __('assistant.domain_stores.import.aliases.unpurchased'),
            __('assistant.domain_stores.common.no'),
            '0',
            'false',
            'no',
        ]), true)) {
            return false;
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $field => $value) {
            if (in_array($field, ['domain_check_status', 'email_bound', 'email_purchased', 'shop_status'], true)) {
                continue;
            }

            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param array<string, list<string>> $dropdownOptionsByHeader
     */
    private function spreadsheetResponse(
        array $headers,
        array $rows,
        string $filename,
        array $dropdownOptionsByHeader = [],
    ): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $dropdownOptionsByHeader): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(__('assistant.domain_stores.import.sheet_title'));
            $headerColumns = [];

            foreach ($headers as $column => $header) {
                $columnName = Coordinate::stringFromColumnIndex($column + 1);
                $cell = $columnName.'1';
                $sheet->setCellValueExplicit($cell, $header, DataType::TYPE_STRING);
                $sheet->getColumnDimension($columnName)->setAutoSize(true);
                $headerColumns[$header] = $columnName;

                if ($header === $this->importHeaderLabel('payment_card')) {
                    $sheet->getStyle("{$columnName}:{$columnName}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
            }

            if ($dropdownOptionsByHeader !== []) {
                $this->applyTemplateDropdowns($spreadsheet, $sheet, $headerColumns, $dropdownOptionsByHeader);
                $this->applyTemplateInputValidations($sheet, $headerColumns);
                $spreadsheet->setActiveSheetIndex(0);
            }

            foreach ($rows as $rowNumber => $row) {
                foreach ($row as $column => $value) {
                    $cell = Coordinate::stringFromColumnIndex($column + 1).($rowNumber + 2);
                    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->freezePane('A2');
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('EAF2F8');

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param array<string, string> $headerColumns
     * @param array<string, list<string>> $dropdownOptionsByHeader
     */
    private function applyTemplateDropdowns(
        Spreadsheet $spreadsheet,
        Worksheet $sheet,
        array $headerColumns,
        array $dropdownOptionsByHeader,
    ): void {
        $optionSheet = $spreadsheet->createSheet();
        $optionSheet->setTitle('_import_options');
        $optionSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $requiredHeaders = array_map(
            fn (string $field): string => $this->importHeaderLabel($field),
            self::REQUIRED_IMPORT_FIELDS,
        );
        $optionColumn = 1;

        foreach ($dropdownOptionsByHeader as $header => $options) {
            $columnName = $headerColumns[$header] ?? null;

            if ($columnName === null || $options === []) {
                continue;
            }

            $optionColumnName = Coordinate::stringFromColumnIndex($optionColumn);
            foreach (array_values($options) as $index => $option) {
                $optionSheet->setCellValueExplicit(
                    $optionColumnName.($index + 1),
                    $option,
                    DataType::TYPE_STRING,
                );
            }
            $rangeName = 'DomainStoreImportOption'.$optionColumn;
            $spreadsheet->addNamedRange(new NamedRange(
                $rangeName,
                $optionSheet,
                sprintf('$%s$1:$%s$%d', $optionColumnName, $optionColumnName, count($options)),
            ));

            $allowsMultipleValues = in_array($header, [
                $this->importHeaderLabel('team'),
                $this->importHeaderLabel('erp'),
            ], true);
            $validation = $sheet->getDataValidation($columnName.'2');
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle($allowsMultipleValues
                ? DataValidation::STYLE_INFORMATION
                : DataValidation::STYLE_STOP);
            $validation->setAllowBlank(!in_array($header, $requiredHeaders, true));
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowDropDown(true);
            $validation->setErrorTitle(__('assistant.domain_stores.import.dropdown_error_title'));
            $validation->setError(__($allowsMultipleValues
                ? 'assistant.domain_stores.import.multi_value_dropdown_error'
                : 'assistant.domain_stores.import.dropdown_error'));
            $validation->setPromptTitle(__('assistant.domain_stores.import.dropdown_prompt_title'));
            $validation->setPrompt(__($allowsMultipleValues
                ? 'assistant.domain_stores.import.multi_value_dropdown_prompt'
                : 'assistant.domain_stores.import.dropdown_prompt'));
            $validation->setFormula1($rangeName);
            $validation->setSqref(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW));
            $optionColumn++;
        }
    }

    /** @param array<string, string> $headerColumns */
    private function applyTemplateInputValidations(Worksheet $sheet, array $headerColumns): void
    {
        foreach (['domain_expires_at' => false, 'plan_expires_at' => true] as $field => $allowBlank) {
            $columnName = $headerColumns[$this->importHeaderLabel($field)] ?? null;
            if ($columnName === null) {
                continue;
            }

            $sheet->getStyle(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW))
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');
            $validation = $sheet->getDataValidation($columnName.'2');
            $validation->setType(DataValidation::TYPE_DATE);
            $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank($allowBlank);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle(__('assistant.domain_stores.import.date_error_title'));
            $validation->setError(__('assistant.domain_stores.import.date_error'));
            $validation->setPromptTitle(__('assistant.domain_stores.import.date_prompt_title'));
            $validation->setPrompt(__('assistant.domain_stores.import.date_prompt'));
            $validation->setFormula1('DATE(2000,1,1)');
            $validation->setFormula2('DATE(2100,12,31)');
            $validation->setSqref(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW));
        }

        foreach (['owner_user_id', 'operation_department_id'] as $field) {
            $columnName = $headerColumns[$this->importHeaderLabel($field)] ?? null;
            if ($columnName === null) {
                continue;
            }

            $validation = $sheet->getDataValidation($columnName.'2');
            $validation->setType(DataValidation::TYPE_WHOLE);
            $validation->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setErrorTitle(__('assistant.domain_stores.import.owner_id_error_title'));
            $validation->setError(__('assistant.domain_stores.import.owner_id_error'));
            $validation->setPromptTitle(__('assistant.domain_stores.import.owner_id_prompt_title'));
            $validation->setPrompt(__('assistant.domain_stores.import.owner_id_prompt'));
            $validation->setFormula1('1');
            $validation->setSqref(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW));
        }

        foreach ([
            'payment_card', 'store_prefix', 'tongtu_prefix', 'shopify_client_id',
            'shopify_client_secret', 'xshoppy_api_key', 'xshoppy_password',
            'shopline_app_token', 'nbc_app_token', 'shopyy_app_token',
        ] as $field) {
            $columnName = $headerColumns[$this->importHeaderLabel($field)] ?? null;
            if ($columnName !== null) {
                $sheet->getStyle(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW))
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }
    }

}
