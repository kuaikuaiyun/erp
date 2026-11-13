<?php

namespace App\Portals\OperationTasks\Support;

use App\Models\OperationTaskAsset;
use App\Models\OperationTaskRecap;
use App\Models\OperationTestTask;

final class OperationTaskRules
{
    public static function recapUntouched(OperationTaskRecap $recap): bool
    {
        return ! $recap->trashed()
            && $recap->leader_review_status === 'pending'
            && $recap->department_review_status === 'pending';
    }

    public static function taskUntouched(OperationTestTask $task): bool
    {
        if ($task->trashed()) return false;
        $task->loadMissing('reviews', 'assets.reviews', 'rectifications');
        return $task->reviews->where('target_version', $task->version)->isEmpty()
            && ! $task->assets->contains(fn ($asset) => $asset->reviews->where('target_version', $asset->version)->isNotEmpty())
            && ! $task->rectifications->contains('status', 'required');
    }

    public static function dailyAllowed(OperationTestTask $task, OperationTaskAsset $asset): bool
    {
        if ($task->trashed() || $asset->retired_at || (int) $asset->task_id !== (int) $task->id) return false;
        $task->loadMissing('reviews');
        $asset->loadMissing('reviews');
        $taskReviews = $task->reviews->where('target_version', $task->version);
        $assetReviews = $asset->reviews->where('target_version', $asset->version);
        if ($taskReviews->contains('result', 'rejected') || $assetReviews->contains('result', 'rejected')) return false;
        return $taskReviews->where('result', 'approved')->pluck('channel')
            ->intersect($assetReviews->where('result', 'approved')->pluck('channel'))
            ->intersect(['leader', 'department'])->isNotEmpty();
    }
}
