<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Portals\Logistics\StreamedXlsxWriter;

class DisciplineExportService
{
    public function __construct(private DisciplineService $service, private StreamedXlsxWriter $writer) {}

    public function response(AdminUser $user, array $filters)
    {
        $days = $this->service->days($filters['month']);
        $columns = [['label' => __('discipline.member'), 'width' => 22]];
        foreach ($days as $day) {
            $columns[] = ['label' => $day['day']."\n".$day['weekday'], 'width' => 24, 'headerFill' => $day['weekend'] ? 'EAF1F8' : 'D6E4F0'];
        }
        $rows = function () use ($user, $filters, $days) {
            $query = $this->service->membersQuery($user, $filters);
            // Offset batches keep memory bounded while preserving the union's stable member ordering.
            for ($offset = 0; ; $offset += 100) {
                $members = (clone $query)->offset($offset)->limit(100)->get();
                if ($members->isEmpty()) {
                    break;
                }
                foreach ($members as $member) {
                    $cells = [['value' => $member->name, 'type' => 'text', 'wrap' => true]];
                    $notes = $this->service->monthRecords($user, $filters)->where('member_id', $member->id)->orderBy('id')->get()->groupBy(fn ($r) => $r->record_date->format('Y-m-d'));
                    foreach ($days as $day) {
                        $text = $notes->get($day['date'], collect())->pluck('remarks')->implode("\n");
                        // Excel's per-cell limit: full records remain available in the detail sheet below.
                        if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > 32000) {
                            $text = mb_substr($text, 0, 15000)."\n".__('discipline.full_details');
                        }
                        $cells[] = ['value' => $text, 'type' => 'text', 'wrap' => true];
                    }
                    yield ['height' => 60, 'cells' => $cells];
                }
            }
        };
        $detailColumns = collect(['member', 'department', 'date', 'remarks', 'created_at', 'updated_at'])->map(fn ($key) => ['label' => __('discipline.'.$key), 'width' => $key === 'remarks' ? 70 : 24])->all();
        $details = function () use ($user, $filters) {
            $members = $this->service->membersQuery($user, $filters)->reorder()->select('members.id');
            foreach ($this->service->monthRecords($user, $filters)->whereIn('member_id', $members)->orderBy('id')->lazyById(300) as $record) {
                yield ['cells' => collect([
                    $record->member_name, $record->department_name, $record->record_date->format('Y-m-d'), $record->remarks,
                    $record->created_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'), $record->updated_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ])->map(fn ($value) => ['value' => $value, 'type' => 'text', 'wrap' => true])->all()];
            }
        };

        return $this->writer->response(__('discipline.title').'_'.$filters['month'].'.xlsx', __('discipline.calendar'), $columns, $rows,
            additionalSheets: [[__('discipline.details'), $detailColumns, $details]]);
    }
}
