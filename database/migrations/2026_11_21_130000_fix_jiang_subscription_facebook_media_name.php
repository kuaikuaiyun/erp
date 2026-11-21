<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('operation_reports')) {
            return;
        }

        $roleTable = config('admin.database.roles_table', 'admin_roles');
        $departmentIds = $schema->hasTable($roleTable)
            ? DB::connection($connection)->table($roleTable)
                ->where('name', '订阅蒋总运营部')->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        DB::connection($connection)->table('operation_reports')
            ->where(function ($query) use ($departmentIds): void {
                $query->where('operation_department_name', '订阅蒋总运营部');
                if ($departmentIds !== []) {
                    $query->orWhereIn('operation_department_id', $departmentIds);
                }
            })
            ->whereNotNull('advertising_rows')
            ->orderBy('id')
            ->chunkById(200, function ($reports) use ($connection): void {
                foreach ($reports as $report) {
                    $rows = json_decode((string) $report->advertising_rows, true);
                    if (! is_array($rows)) {
                        continue;
                    }

                    $changed = false;
                    foreach ($rows as &$row) {
                        if (is_array($row)
                            && strcasecmp(trim((string) ($row['media_source'] ?? '')), 'Fcebook') === 0) {
                            $row['media_source'] = 'Facebook';
                            $changed = true;
                        }
                    }
                    unset($row);

                    if ($changed) {
                        DB::connection($connection)->table('operation_reports')
                            ->where('id', $report->id)
                            ->update(['advertising_rows' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // This is a targeted data correction. Reversing it could change valid Facebook rows.
    }
};
