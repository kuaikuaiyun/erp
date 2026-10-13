<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreQuickOption;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantDomainStoreSpreadsheetService
{
    private const TEMPLATE_DROPDOWN_LAST_ROW = 2001;

    private const CUSTOM_VALUE_FIELDS = [
        'site_builder',
        'credit_card',
        'wallet',
        'paypal',
        'other_payment',
    ];

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
        'shopify_client_id',
        'shopify_client_secret',
    ];

    private const REQUIRED_IMPORT_FIELDS = [
        'shop_name',
        'domain',
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
        return array_map(fn (string $field): string => $this->importHeaderLabel($field), self::EXPORT_FIELDS);
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

        return $importRows;
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

        $missingFields = array_values(array_diff(self::IMPORT_FIELDS, array_keys($seenFields)));
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
        }

        return $headers;
    }

    private function importHeaderLabel(string $field): string
    {
        $label = match ($field) {
            'user_name' => __('assistant.domain_stores.fields.user_name'),
            default => AssistantDomainStore::fieldLabel($field),
        };

        return in_array($field, self::REQUIRED_IMPORT_FIELDS, true)
            ? __('assistant.domain_stores.import.required_header', ['header' => $label])
            : $label;
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
            $this->importHeaderLabel('site_builder') => $this->activeOptionLabels('site_builder', AssistantDomainStore::siteBuilderOptions(), $quickOptions),
            $this->importHeaderLabel('domain_check_status') => $this->templateDomainCheckOptions(),
            $this->importHeaderLabel('email_bound') => [__('assistant.domain_stores.common.yes'), __('assistant.domain_stores.common.no')],
            $this->importHeaderLabel('credit_card') => $this->activeOptionLabels('credit_card', AssistantDomainStore::creditCardOptions(), $quickOptions),
            $this->importHeaderLabel('wallet') => $this->activeOptionLabels('wallet', AssistantDomainStore::walletOptions(), $quickOptions),
            $this->importHeaderLabel('paypal') => $this->activeOptionLabels('paypal', AssistantDomainStore::paypalOptions(), $quickOptions),
            $this->importHeaderLabel('other_payment') => $this->activeOptionLabels('other_payment', AssistantDomainStore::otherPaymentOptions(), $quickOptions),
            $this->importHeaderLabel('shop_status') => array_values(AssistantDomainStore::shopStatusLabels()),
        ];
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, list<string>> $quickOptions
     * @return list<string>
     */
    private function activeOptionLabels(string $field, array $labels, array $quickOptions): array
    {
        return array_map(
            static fn (string $value): string => $labels[$value] ?? $value,
            $quickOptions[$field] ?? [],
        );
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
            'domain_check_status' => AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED,
            'email' => null,
            'email_bound' => false,
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
            'shopify_client_id' => null,
            'shopify_client_secret' => null,
            'shop_status' => AssistantDomainStore::SHOP_STATUS_IN_USE,
            'plan_expires_at' => null,
            'payment_card' => null,
            'remark' => null,
        ];
    }

    private function hasDefaultValue(string $field): bool
    {
        return in_array($field, [
            'domain_check_status', 'email_bound', 'email_purchased', 'shop_status',
        ], true);
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

            $this->applyTemplateDropdowns($sheet, $headerColumns, $dropdownOptionsByHeader);

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
        Worksheet $sheet,
        array $headerColumns,
        array $dropdownOptionsByHeader,
    ): void {
        foreach ($dropdownOptionsByHeader as $header => $options) {
            $columnName = $headerColumns[$header] ?? null;

            if ($columnName === null || $options === []) {
                continue;
            }

            $validation = $sheet->getDataValidation($columnName.'2');
            $allowsCustomValue = in_array($this->fieldForImportHeader($header), self::CUSTOM_VALUE_FIELDS, true);

            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle($allowsCustomValue ? DataValidation::STYLE_INFORMATION : DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowDropDown(true);
            $validation->setErrorTitle(__('assistant.domain_stores.import.dropdown_error_title'));
            $validation->setError($allowsCustomValue
                ? __('assistant.domain_stores.import.dropdown_custom_error')
                : __('assistant.domain_stores.import.dropdown_error'));
            $validation->setPromptTitle(__('assistant.domain_stores.import.dropdown_prompt_title'));
            $validation->setPrompt(__('assistant.domain_stores.import.dropdown_prompt'));
            $validation->setFormula1('"'.implode(',', $options).'"');
            $validation->setSqref(sprintf('%s2:%s%d', $columnName, $columnName, self::TEMPLATE_DROPDOWN_LAST_ROW));
        }
    }

    private function fieldForImportHeader(string $header): ?string
    {
        return $this->importHeadersByLabel()[$header] ?? null;
    }
}
