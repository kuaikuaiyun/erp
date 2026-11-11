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
        if (! $schema->hasTable('fashion_advertising_reports')) return;

        $db = DB::connection($connection);
        $rows = $db->table('fashion_advertising_reports')
            ->where('submitter_id', 102)
            ->whereBetween('report_date', ['1900-11-01', '1900-11-07'])
            ->orderBy('report_date')
            ->get(['id', 'report_date', 'submitter_id']);

        foreach ($rows as $row) {
            $targetDate = '2026-'.substr((string) $row->report_date, 5);
            $conflict = $db->table('fashion_advertising_reports')
                ->where('submitter_id', $row->submitter_id)
                ->whereDate('report_date', $targetDate)
                ->where('id', '!=', $row->id)
                ->exists();
            if (! $conflict) {
                $db->table('fashion_advertising_reports')->where('id', $row->id)->update([
                    'report_date' => $targetDate,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data corrections are intentionally not rolled back.
    }
};
