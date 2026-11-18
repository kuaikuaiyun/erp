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

        if (! $schema->hasTable('aikaipin_placement_evaluation_records')
            || ! $schema->hasTable('aikaipin_placement_evaluations')
            || ! $schema->hasColumns('aikaipin_placement_evaluation_records', ['id', 'evaluation_id', 'video_path', 'video_name'])
            || ! $schema->hasColumns('aikaipin_placement_evaluations', ['id', 'product_code', 'created_by', 'created_at'])) {
            return;
        }

        $database = DB::connection($connection);
        $nextNumbers = [];

        if ($schema->hasTable('operation_ai_product_reference_videos')
            && $schema->hasColumns('operation_ai_product_reference_videos', ['file_name', 'uploaded_by', 'source_kind'])) {
            $database->table('operation_ai_product_reference_videos')
                ->where('source_kind', 'operator_upload')
                ->whereNotNull('uploaded_by')
                ->orderBy('id')
                ->get(['file_name', 'uploaded_by'])
                ->each(function (object $video) use (&$nextNumbers): void {
                    $userId = (int) $video->uploaded_by;
                    $nextNumbers[$userId] = max(
                        $nextNumbers[$userId] ?? 0,
                        $this->operatorVideoNumber((string) $video->file_name),
                    );
                });
        }

        $database->table('aikaipin_placement_evaluation_records as records')
            ->join('aikaipin_placement_evaluations as evaluations', 'evaluations.id', '=', 'records.evaluation_id')
            ->where('records.video_name', 'like', '%-min-%')
            ->orderBy('records.id')
            ->get(['records.video_name', 'evaluations.created_by'])
            ->each(function (object $record) use (&$nextNumbers): void {
                $userId = (int) $record->created_by;
                $nextNumbers[$userId] = max(
                    $nextNumbers[$userId] ?? 0,
                    $this->operatorVideoNumber((string) $record->video_name),
                );
            });

        $legacyRecords = $database->table('aikaipin_placement_evaluation_records as records')
            ->join('aikaipin_placement_evaluations as evaluations', 'evaluations.id', '=', 'records.evaluation_id')
            ->where('records.video_name', 'like', '%-placement-%')
            ->orderBy('evaluations.created_at')
            ->orderBy('evaluations.id')
            ->orderBy('records.id')
            ->get([
                'records.id', 'records.evaluation_id', 'records.video_path', 'records.video_name',
                'evaluations.product_code', 'evaluations.created_by', 'evaluations.created_at',
            ]);

        $assignedNames = [];

        $database->transaction(function () use ($database, $legacyRecords, &$nextNumbers, &$assignedNames): void {
            foreach ($legacyRecords as $record) {
                $userId = max(1, (int) $record->created_by);
                $sourceKey = $userId.'|'.((string) $record->video_path !== ''
                    ? (string) $record->video_path
                    : (int) $record->evaluation_id.'|'.(string) $record->video_name);

                if (! isset($assignedNames[$sourceKey])) {
                    $nextNumbers[$userId] = ($nextNumbers[$userId] ?? 0) + 1;
                    $assignedNames[$sourceKey] = $this->normalizedFileName(
                        (string) $record->product_code,
                        $userId,
                        (string) $record->video_name,
                        (string) $record->created_at,
                        $nextNumbers[$userId],
                    );
                }

                $database->table('aikaipin_placement_evaluation_records')
                    ->where('id', (int) $record->id)
                    ->update(['video_name' => $assignedNames[$sourceKey]]);
            }
        });
    }

    public function down(): void
    {
        // Historical random suffixes cannot be reconstructed after normalization.
    }

    private function operatorVideoNumber(string $fileName): int
    {
        return preg_match('/-op\d+-min-\d{6}-(\d+)\.[a-z0-9]{2,5}$/i', $fileName, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    private function normalizedFileName(
        string $productCode,
        int $userId,
        string $legacyFileName,
        string $createdAt,
        int $number,
    ): string {
        $safeProductCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $productCode), '-_')
            ?: 'product';
        $date = preg_match('/-(\d{6})-placement-/i', $legacyFileName, $dateMatches) === 1
            ? $dateMatches[1]
            : date('ymd', strtotime($createdAt) ?: time());
        $extension = strtolower(pathinfo($legacyFileName, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{2,5}$/', $extension) !== 1) {
            $extension = 'mp4';
        }

        return implode('-', [
            $safeProductCode,
            'op'.$userId,
            'min',
            $date,
            str_pad((string) max(1, $number), 3, '0', STR_PAD_LEFT),
        ]).'.'.$extension;
    }
};
