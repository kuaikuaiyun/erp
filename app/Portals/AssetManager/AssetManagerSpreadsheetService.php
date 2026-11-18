<?php

namespace App\Portals\AssetManager;

use App\Models\Department;
use App\Portals\Logistics\StreamedXlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

final class AssetManagerSpreadsheetService
{
    public function __construct(private AssetManagerService $service) {}

    public function headers(string $kind): array
    {
        return ['_id' => __('asset_manager.record_id')] + collect(AssetManagerCatalog::fields($kind))->mapWithKeys(fn ($t, $k) => [$k => AssetManagerCatalog::labels($kind)[$k]])->all() + ['created_at' => AssetManagerCatalog::labels($kind)['created_at']] + ($kind === 'computers' ? ['owner_department_id' => __('asset_manager.fields.owner_department_id')] : []);
    }

    public function download(string $kind, iterable $records, bool $template = false)
    {
        $headers = $this->headers($kind);
        $owners = $kind === 'computers' ? Department::business()->with('parent')->get()->mapWithKeys(fn ($d) => [$d->id => ($d->parent ? $d->parent->name.' / ' : '').$d->name])->all() : [];
        $columns = array_map(fn ($label) => ['label' => $label, 'width' => 24], array_values($headers));

        return app(StreamedXlsxWriter::class)->response($kind.($template ? '-template' : '').'.xlsx', __('asset_manager.titles.'.$kind), $columns, function () use ($kind, $records, $template, $owners) {
            if ($template) {
                return;
            }foreach ($records as $r) {
                $values = [(string) $r->id];
                foreach (AssetManagerCatalog::fields($kind) as $key => $type) {
                    $values[] = $kind === 'ipfoxy' ? ($key === 'expires_at' ? ($r->expires_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '') : (string) ($r->$key ?? '')) : $this->service->display($r, $key, $type);
                }$values[] = $r->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '';
                if ($kind === 'computers') {
                    $values[] = $owners[$r->owner_department_id] ?? __('asset_manager.computer_unassigned');
                }
                yield ['cells' => array_map(fn ($value) => ['value' => (string) $value, 'type' => 'string'], $values)];
            }
        });
    }

    public function preview(string $kind, $file, ?Builder $existingQuery = null, ?array $userOptions = null): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        abort_unless(in_array(get_class($reader), ['PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx', 'PhpOffice\\PhpSpreadsheet\\Reader\\Csv']), 422, __('asset_manager.failed'));
        $reader->setReadDataOnly(false);
        $info = $reader->listWorksheetInfo($file->getRealPath());
        abort_if(($info[0]['totalRows'] ?? 0) > 5001, 422, __('asset_manager.import_limit'));
        $book = $reader->load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $headers = $this->headers($kind);
        $first = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, false, false)[0];
        $map = [];
        foreach ($first as $i => $label) {
            $key = array_search(trim((string) $label), $headers, true);
            if ($key !== false) {
                $map[$key] = $i + 1;
            }
        }
        abort_if(array_diff(array_keys(AssetManagerCatalog::fields($kind)), array_keys($map)), 422, __('asset_manager.required_headers'));
        $rows = [];
        $errors = [];
        $skipped = 0;
        $seen = [];
        $users = $userOptions ?? $this->service->users();
        $departments = $this->service->departments();
        $class = AssetManagerCatalog::model($kind);
        for ($n = 2; $n <= $sheet->getHighestDataRow(); $n++) {
            $row = [];
            foreach ($map as $key => $col) {
                $cell = $sheet->getCell([$col, $n]);
                $value = $cell->getValue();
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $errors[] = ['row' => $n, 'message' => __('asset_manager.invalid')];

                    continue 2;
                }if (is_numeric($value) && Date::isDateTime($cell)) {
                    $value = Date::excelToDateTimeObject($value)->format($key === 'created_at' ? 'Y-m-d H:i:s' : 'Y-m-d');
                }$row[$key] = trim((string) $value);
            }if (! array_filter($row, fn ($v) => $v !== '')) {
                continue;
            }
            if (! empty($row['_id'])) {
                if (($existingQuery ? clone $existingQuery : $class::query())->whereKey($row['_id'])->exists()) {
                    $skipped++;

                    continue;
                }
            }
            try {
                foreach (['user_id' => $users, 'department_id' => $departments] as $key => $options) {
                    if (! empty($row[$key])) {
                        $matches = array_keys($options, $row[$key], true);
                        if (count($matches) !== 1) {
                            throw new \RuntimeException(__('asset_manager.'.($key === 'user_id' ? 'unknown_user' : 'unknown_department')));
                        }$row[$key] = $matches[0];
                    }
                }
                $created = ! empty($row['created_at']) ? $this->service->date($row['created_at'])->utc()->format('Y-m-d H:i:s') : null;
                $data = $this->service->validate($kind, $row);
                if ($created) {
                    $data['created_at'] = $created;
                }$hash = hash('sha256', json_encode($data));
                if (isset($seen[$hash])) {
                    throw new \RuntimeException(__('asset_manager.import_duplicate'));
                }$seen[$hash] = true;
                $rows[] = $data;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $n, 'message' => $e->getMessage()];
            }
        }
        $book->disconnectWorksheets();

        return ['rows' => $rows, 'errors' => $errors, 'skipped' => $skipped];
    }
}
