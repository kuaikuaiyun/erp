<?php

namespace App\Portals\ReviewCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskDailyPlan;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskRectification;
use App\Models\OperationTaskReview;
use App\Models\OperationTaskReviewEvent;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\Support\OperationTaskRules;
use App\Services\DepartmentAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Independent copy of the fashion review workflow; only department-channel access is exposed. */
class OperationTaskReviewController extends Controller
{
    private const PORTAL_CODE = 'review-center';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly DepartmentAuthorizationService $departments,
    ) {
    }

    public function reviews(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->canReview($context['user']), 403);
        $teams = $this->visibleSecondaryDepartments($context['user'], $context['portal']);
        $selectedTeamId = (int) $request->input('department', 0);
        if ($selectedTeamId && ! $teams->contains('id', $selectedTeamId)) $selectedTeamId = 0;
        $taskPerPage = in_array((int) $request->input('task_per_page', 20), [20, 50, 100], true) ? (int) $request->input('task_per_page', 20) : 20;
        $recapPerPage = in_array((int) $request->input('recap_per_page', 20), [20, 50, 100], true) ? (int) $request->input('recap_per_page', 20) : 20;
        $filters = ['from' => (string) $request->input('from', ''), 'to' => (string) $request->input('to', ''), 'department' => $selectedTeamId ?: '', 'status' => (string) $request->input('status', '')];
        [, , $submittedFrom, $submittedUntil] = $this->submissionRange($request);
        $channels = $this->reviewChannels($context['user']);
        $query = OperationTestTask::query()->with([
            'assets.reviews',
            'reviews',
            'rectifications',
        ])->latest('submitted_at');
        if (! $context['user']->isAdministrator()) $query->whereIn('department_id', $this->visibleDepartmentIds($context['user']));
        if ($selectedTeamId) $query->where('department_id', $selectedTeamId);
        if ($submittedFrom) $query->where('submitted_at', '>=', $submittedFrom);
        if ($submittedUntil) $query->where('submitted_at', '<', $submittedUntil);
        $searchTeamOptions = (clone $query)->reorder()->setEagerLoads([])->select('department_name')->distinct()->pluck('department_name');
        $searchPersonOptions = (clone $query)->reorder()->setEagerLoads([])->select('owner_name')->distinct()->pluck('owner_name');
        foreach (['team_search' => 'department_name', 'person_search' => 'owner_name'] as $parameter => $column) {
            if ($request->filled($parameter)) $query->where($column, 'like', '%'.trim((string) $request->input($parameter)).'%');
        }

        if ($filters['status'] === 'pending') {
            $query->where(function ($taskQuery) use ($channels): void {
                foreach (array_keys($channels) as $channel) {
                    $taskQuery->orWhereDoesntHave('reviews', fn ($reviews) => $reviews->where('target_type', 'task')->whereColumn('target_version', 'operation_test_tasks.version')->where('channel', $channel));
                }
            });
        } elseif ($filters['status'] === 'rectification') {
            $query->whereHas('rectifications', fn ($rectifications) => $rectifications->whereIn('status', ['required', 'restore_required']));
        } elseif ($filters['status'] === 'approved') {
            foreach (array_keys($channels) as $channel) {
                $query->whereHas('reviews', fn ($reviews) => $reviews->where('target_type', 'task')->whereColumn('target_version', 'operation_test_tasks.version')->where('channel', $channel)->where('result', 'approved'));
            }
        }
        $tasks = $query->paginate($taskPerPage, ['*'], 'task_page')->withQueryString();
        $taskReviewerTeams = Department::query()
            ->with(['departmentMembers:id,name,username', 'parent.departmentMembers:id,name,username'])
            ->whereIn('id', $tasks->getCollection()->pluck('department_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $tasks->getCollection()->each(function (OperationTestTask $task) use ($context, $taskReviewerTeams): void {
            $task->setAttribute('can_leader_review', $this->canLeaderReviewTask($context['user'], $task));
            $task->setAttribute('can_department_review', $this->canDepartmentReviewTask($context['user'], $task));
            $team = $taskReviewerTeams->get((int) $task->department_id);
            $leaderNames = $team?->departmentMembers
                ?->filter(fn (AdminUser $member) => in_array($member->pivot?->position, [Department::POSITION_MANAGER, Department::POSITION_DEPUTY], true))
                ->map(fn (AdminUser $member) => $this->userName($member))->unique()->values()->implode('、');
            $departmentNames = $team?->parent?->departmentMembers
                ?->filter(fn (AdminUser $member) => $member->pivot?->position === Department::POSITION_MANAGER)
                ->map(fn (AdminUser $member) => $this->userName($member))->unique()->values()->implode('、');
            $allTaskReviews = $task->reviews->where('target_type', 'task');
            $taskReviews = $allTaskReviews
                ->where('target_version', $task->version)
                ->keyBy('channel');
            $leaderReview = $taskReviews->get('leader');
            $departmentReview = $taskReviews->get('department');
            $task->setAttribute('leader_reviewer_names', trim((string) optional($leaderReview)->reviewer_name) ?: ($leaderNames ?: __('review_center.copy.text_001')));
            $task->setAttribute('department_reviewer_names', trim((string) optional($departmentReview)->reviewer_name) ?: ($departmentNames ?: __('review_center.copy.text_001')));
            $leaderStatus = optional($leaderReview)->result ?: 'pending';
            $departmentStatus = optional($departmentReview)->result ?: 'pending';
            $lastRejectedReview = $allTaskReviews->filter(fn ($review) => $review->result === 'rejected')
                ->sortByDesc(fn ($review) => $review->reviewed_at ?: $review->updated_at)
                ->first();
            $lastRejectedRectification = $task->rectifications
                ->filter(fn (OperationTaskRectification $rectification) => trim((string) $rectification->rejection_opinion) !== '')
                ->sortByDesc('id')
                ->first();
            $requiresChanges = $task->rectifications->contains(
                fn (OperationTaskRectification $rectification) => $rectification->status === 'required'
            );
            $requiresRestore = $task->rectifications->contains(
                fn (OperationTaskRectification $rectification) => $rectification->status === 'restore_required'
            );
            $workflowStatus = match (true) {
                $requiresChanges, $leaderStatus === 'rejected', $departmentStatus === 'rejected' => 'rectification',
                $requiresRestore => 'restore_required',
                $departmentStatus === 'approved' => 'approved',
                $leaderStatus === 'approved' => 'pending_department',
                default => 'pending_leader',
            };
            $lastRejectionOpinion = trim((string) (
                optional($lastRejectedRectification)->rejection_opinion
                ?: optional($lastRejectedReview)->opinion
            )) ?: null;
            $lastRejectionReviewer = trim((string) (
                optional($lastRejectedRectification)->reviewer_name
                ?: optional($lastRejectedReview)->reviewer_name
            )) ?: null;
            $lastRejectionChannel = (string) (
                optional($lastRejectedRectification)->review_channel
                ?: optional($lastRejectedReview)->channel
                ?: ''
            );
            $lastRejectionAt = optional($lastRejectedRectification)->created_at
                ?: optional($lastRejectedRectification)->updated_at
                ?: optional($lastRejectedReview)->reviewed_at
                ?: optional($lastRejectedReview)->updated_at;
            $task->setAttribute('leader_review_status', $leaderStatus);
            $task->setAttribute('department_review_status', $departmentStatus);
            $task->setAttribute('workflow_status', $workflowStatus);
            $task->setAttribute('last_rejection_opinion', $lastRejectionOpinion);
            $task->setAttribute('last_rejection_reviewer', $lastRejectionReviewer);
            $task->setAttribute('last_rejection_channel', $lastRejectionChannel);
            $task->setAttribute('last_rejection_at', $lastRejectionAt
                ? \App\Portals\OperationTasks\Support\OperationTaskTime::format($lastRejectionAt, 'm-d H:i', '')
                : null);
        });
        $taskFlowEvents = OperationTaskReviewEvent::query()
            ->where('target_type', 'task')
            ->whereIn('target_id', $tasks->getCollection()->pluck('id'))
            ->orderBy('reviewed_at')->orderBy('id')->get()->groupBy('target_id');
        $tasks->getCollection()->each(fn (OperationTestTask $task) => $task->setAttribute('review_flow_events', $taskFlowEvents->get($task->id, collect())));

        $recapQuery = OperationTaskRecap::query()->orderByDesc('submitted_at')->orderByDesc('id');
        $recapStatus = match ($filters['status']) {
            'approved' => 'approved',
            'rectification' => null,
            default => 'pending',
        };
        if ($context['user']->isAdministrator()) {
            if ($recapStatus) $recapQuery->where('department_review_status', $recapStatus);
        } else {
            $leaderIds = $this->leaderDepartmentIds($context['user']);
            $departmentIds = array_values(array_diff($this->departmentReviewScopeIds($context['user']), $leaderIds));
            $recapQuery->where(function ($visibility) use ($leaderIds, $departmentIds, $recapStatus): void {
                $visibility->whereRaw('1 = 0');
                if ($leaderIds !== []) $visibility->orWhere(function ($query) use ($leaderIds, $recapStatus): void {
                    $query->whereIn('department_id', $leaderIds);
                    if ($recapStatus) $query->where('leader_review_status', $recapStatus);
                });
                if ($departmentIds !== []) $visibility->orWhere(function ($query) use ($departmentIds, $recapStatus): void {
                    $query->whereIn('department_id', $departmentIds);
                    if ($recapStatus) $query->where('department_review_status', $recapStatus);
                });
            });
        }
        if ($filters['status'] === 'rectification') $recapQuery->where('workflow_status', 'rejected');
        if ($selectedTeamId) $recapQuery->where('department_id', $selectedTeamId);
        if ($submittedFrom) $recapQuery->where('submitted_at', '>=', $submittedFrom);
        if ($submittedUntil) $recapQuery->where('submitted_at', '<', $submittedUntil);
        if ($request->filled('team_search')) $recapQuery->where('department_name', 'like', '%'.trim((string) $request->input('team_search')).'%');
        if ($request->filled('person_search')) $recapQuery->where('user_name', 'like', '%'.trim((string) $request->input('person_search')).'%');
        $recaps = $recapQuery->paginate($recapPerPage, ['*'], 'recap_page')->withQueryString();
        $recapReviewerTeams = Department::query()
            ->with(['departmentMembers:id,name,username', 'parent.departmentMembers:id,name,username'])
            ->whereIn('id', $recaps->getCollection()->pluck('department_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $recaps->each(function (OperationTaskRecap $recap) use ($context, $recapReviewerTeams): void {
            $recap->setAttribute('review_channel', $this->recapReviewChannel($context['user'], $recap));
            $recap->setAttribute('can_leader_review', $this->canLeaderReviewRecap($context['user'], $recap));
            $recap->setAttribute('can_department_review', $this->canDepartmentReviewRecap($context['user'], $recap));
            $team = $recapReviewerTeams->get((int) $recap->department_id);
            $leaderNames = $team?->departmentMembers
                ?->filter(fn (AdminUser $member) => in_array($member->pivot?->position, [Department::POSITION_MANAGER, Department::POSITION_DEPUTY], true))
                ->map(fn (AdminUser $member) => $this->userName($member))->unique()->values()->implode('、');
            $departmentNames = $team?->parent?->departmentMembers
                ?->filter(fn (AdminUser $member) => $member->pivot?->position === Department::POSITION_MANAGER)
                ->map(fn (AdminUser $member) => $this->userName($member))->unique()->values()->implode('、');
            $recap->setAttribute('leader_reviewer_names', $leaderNames ?: __('review_center.copy.text_001'));
            $recap->setAttribute('department_reviewer_names', $departmentNames ?: __('review_center.copy.text_001'));
        });
        $recapFlowEvents = OperationTaskReviewEvent::query()
            ->where('target_type', 'recap')
            ->whereIn('target_id', $recaps->getCollection()->pluck('id'))
            ->orderBy('reviewed_at')->orderBy('id')->get()->groupBy('target_id');
        $recaps->getCollection()->each(fn (OperationTaskRecap $recap) => $recap->setAttribute('review_flow_events', $recapFlowEvents->get($recap->id, collect())));

        return view('portals.review-center.operation-tasks.reviews', [...$context, 'searchTeamOptions' => $searchTeamOptions, 'searchPersonOptions' => $searchPersonOptions, 'tasks' => $tasks, 'recaps' => $recaps, 'reviewFilters' => $filters, 'reviewChannels' => $channels, 'reviewTeams' => $teams]);
    }

    public function reviewRecap(Request $request, OperationTaskRecap $recap): RedirectResponse
    {
        $context = $this->authorized($request);
        $request->merge(['recap_id' => $recap->id]);
        $data = $this->validateTask($request, [
            'channel' => ['required', Rule::in(['leader', 'department'])],
            'version' => ['required', 'integer', 'min:1'],
            'result' => ['required', Rule::in(['approved', 'rejected'])],
            'opinion' => ['nullable', 'string', 'max:5000'],
        ], [
            'version.required' => __('operation_tasks.recap_review.refresh_page'),
            'version.integer' => __('operation_tasks.recap_review.refresh_page'),
            'version.min' => __('operation_tasks.recap_review.refresh_page'),
        ]);
        if ($data['result'] === 'rejected' && trim((string) ($data['opinion'] ?? '')) === '') {
            return back()->withInput()->withErrors(['opinion' => __('operation_tasks.recap_review.opinion_required')]);
        }

        $outcome = DB::connection($recap->getConnectionName())->transaction(function () use ($recap, $data, $context): string {
            $locked = OperationTaskRecap::query()->lockForUpdate()->find($recap->id);
            if (! $locked || (int) $locked->version !== (int) $data['version']) return 'stale';
            abort_unless($this->recapReviewChannel($context['user'], $locked) === $data['channel'], 403);
            $statusColumn = $data['channel'].'_review_status';
            if ($locked->{$statusColumn} !== 'pending') return 'processed';

            $review = OperationTaskReview::query()->firstOrNew([
                'target_type' => 'recap', 'target_id' => $locked->id, 'target_version' => $locked->version, 'channel' => $data['channel'],
            ]);
            if ($review->exists) return 'processed';
            $review->fill([
                'result' => $data['result'], 'opinion' => trim((string) ($data['opinion'] ?? '')) ?: null,
                'rejection_scope' => $data['result'] === 'rejected' ? 'recap' : null,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);
            $review->save();
            OperationTaskReviewEvent::query()->create([
                'review_id' => $review->id, 'target_type' => 'recap', 'target_id' => $locked->id, 'target_version' => $locked->version,
                'channel' => $data['channel'], 'result' => $data['result'], 'opinion' => $review->opinion, 'rejection_scope' => $review->rejection_scope,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);
            $locked->{$statusColumn} = $data['result'];
            if ($data['result'] === 'rejected') {
                $locked->last_rejection_channel = $data['channel'];
                $locked->last_rejection_opinion = $review->opinion;
                $locked->last_rejected_at = now();
            }
            $locked->workflow_status = match (true) {
                $locked->leader_review_status === 'rejected' || $locked->department_review_status === 'rejected' => 'rejected',
                $locked->leader_review_status === 'approved' && $locked->department_review_status === 'approved' => 'approved',
                $locked->leader_review_status === 'pending' => 'pending_leader',
                default => 'pending_department',
            };
            $locked->save();
            if ($data['result'] === 'rejected') {
                $this->notifyUsers([$locked->user_id], $context['user'], __('operation_tasks.recap_review.rejected_title'), __('operation_tasks.recap_review.rejected_message', [
                    'date' => $locked->data_date->format('Y-m-d'), 'name' => $locked->user_name,
                    'channel' => __('operation_tasks.recap_review.'.$data['channel']), 'opinion' => $review->opinion,
                ]));
            } elseif ($locked->workflow_status === 'approved') {
                $this->notifyUsers([$locked->user_id], $context['user'], __('operation_tasks.recap_review.approved_title'), __('operation_tasks.recap_review.approved_message', [
                    'date' => $locked->data_date->format('Y-m-d'), 'name' => $locked->user_name,
                ]));
            }
            return 'saved';
        });
        if ($outcome !== 'saved') {
            return back()->withInput()->with('warning', __('operation_tasks.recap_review.'.$outcome));
        }
        return back()->with('success', __('operation_tasks.recap_review.saved'));
    }

    public function reviewBatch(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $this->validateTask($request, [
            'task_id' => ['required', 'integer'],
            'task_version' => ['required', 'integer', 'min:1'],
            'asset_versions' => ['required', 'array', 'min:1'],
            'asset_versions.*' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(['leader', 'department'])],
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.result' => ['required', Rule::in(['approved', 'rejected'])],
            'assets.*.opinion' => ['nullable', 'string', 'max:5000'],
            'task_result' => ['required', Rule::in(['approved', 'rejected'])],
            'task_opinion' => ['nullable', 'string', 'max:5000'],
            'rejection_scopes' => ['nullable', 'array'],
            'rejection_scopes.*' => ['required', Rule::in(['task', 'asset'])],
        ]);

        $task = OperationTestTask::query()->with('assets')->findOrFail((int) $data['task_id']);
        $allowed = $data['channel'] === 'leader'
            ? $this->canLeaderReviewTask($context['user'], $task)
            : $this->canDepartmentReviewTask($context['user'], $task);
        abort_unless($allowed, 403);

        $assets = $task->assets->keyBy('id');
        foreach ($data['assets'] as $assetId => $assetData) {
            if (! $assets->has((int) $assetId)) {
                throw ValidationException::withMessages(["assets.$assetId.result" => __('operation_tasks.messages.wrong_asset')]);
            }
            if ($assetData['result'] === 'rejected' && trim((string) ($assetData['opinion'] ?? '')) === '') {
                throw ValidationException::withMessages(["assets.$assetId.opinion" => __('operation_tasks.messages.asset_opinion_required')]);
            }
        }
        if ($data['task_result'] === 'rejected' && trim((string) ($data['task_opinion'] ?? '')) === '') {
            throw ValidationException::withMessages(['task_opinion' => __('operation_tasks.messages.task_opinion_required')]);
        }
        $rejectionScopes = array_values(array_unique($data['rejection_scopes'] ?? []));
        if ($data['task_result'] === 'rejected' && $rejectionScopes === []) {
            throw ValidationException::withMessages(['rejection_scopes' => __('operation_tasks.messages.scope_required')]);
        }
        if ($data['task_result'] === 'rejected' && in_array('asset', $rejectionScopes, true)
            && collect($data['assets'])->where('result', 'rejected')->isEmpty()) {
            throw ValidationException::withMessages(['rejection_scopes' => __('operation_tasks.messages.reject_asset_required')]);
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($task, $data, $assets, $context, $rejectionScopes): void {
            $lockedTask = OperationTestTask::query()->lockForUpdate()->find($task->id);
            $this->rejectWhen(! $lockedTask || (int) $lockedTask->version !== (int) $task->version, 'task_id', __('operation_tasks.management.changed'));
            $this->rejectWhen((int) $lockedTask->version !== (int) $data['task_version'], 'task_id', __('operation_tasks.management.changed'));
            $currentAssets = OperationTaskAsset::query()->where('task_id', $task->id)->lockForUpdate()->get()->keyBy('id');
            $currentVersions = $currentAssets->map(fn ($asset) => (int) $asset->version)->sortKeys()->all();
            $sentVersions = collect($data['asset_versions'])->map(fn ($version) => (int) $version)->sortKeys()->all();
            $this->rejectWhen($currentVersions !== $sentVersions, 'assets', __('operation_tasks.management.changed'));

            foreach ($data['assets'] as $assetId => $assetData) {
                $this->storeTaskReview(
                    $task,
                    $assets->get((int) $assetId),
                    'asset',
                    $data['channel'],
                    $assetData['result'],
                    $assetData['opinion'] ?? null,
                    'asset',
                    $context['user']
                );
            }
            $this->storeTaskReview(
                $task,
                $task,
                'task',
                $data['channel'],
                $data['task_result'],
                $data['task_opinion'] ?? null,
                $data['task_result'] === 'rejected' ? implode(',', $rejectionScopes) : 'task',
                $context['user']
            );
            $freshTask = $task->fresh(['reviews', 'assets.reviews', 'rectifications']);
            $this->resolveResubmittedRectifications($freshTask);
            $this->synchronizeTaskRectificationState($freshTask->fresh());
        });

        $resultLabel = $data['task_result'] === 'approved' ? __('review_center.copy.text_002') : __('review_center.copy.text_003');
        $channelLabel = $data['channel'] === 'leader' ? __('review_center.copy.text_004') : __('review_center.copy.text_005');
        $this->notifyUsers([$task->owner_user_id], $context['user'], __('operation_tasks.messages.task_review_title'), sprintf(__('operation_tasks.messages.task_review_result'), $task->task_no, $channelLabel, $resultLabel));
        if ($data['task_result'] === 'approved' && $data['channel'] === 'leader') {
            $this->notifyReviewers($task, __('operation_tasks.messages.leader_passed', ['number' => $task->task_no]), $context['user'], ['department']);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => __('operation_tasks.messages.batch_review_saved')]);
        }
        return back()->with('success', __('operation_tasks.messages.batch_review_saved'));
    }

    public function showTest(Request $request, OperationTestTask $task): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $this->authorizeTask($context['user'], $task);
        $task->load([
            'assets.dailyRecords', 'assets.reviews', 'reviews',
            'rectifications.asset', 'rectifications.sourceReview', 'rectifications.resubmittedAsset',
        ]);
        $task->assets->each(fn ($asset) => $asset->setAttribute('daily_allowed', OperationTaskRules::dailyAllowed($task, $asset)));
        $events = OperationTaskReviewEvent::query()
            ->where(function ($query) use ($task): void {
                $query->where('target_type', 'task')->where('target_id', $task->id)
                    ->orWhereIn('target_id', $task->assets->pluck('id'))->where('target_type', 'asset');
            })
            ->orderByDesc('reviewed_at')->orderByDesc('id')->get();
        $archive = OperationTestTask::query()->withCount('assets')->latest('submitted_at');
        if (! $this->canManage($context['user'])) $archive->where('owner_user_id', $context['user']->id);
        elseif (! $context['user']->isAdministrator()) $archive->whereIn('department_id', $this->visibleDepartmentIds($context['user']));

        return view('portals.review-center.operation-tasks.test-detail', [
            ...$context,
            'task' => $task,
            'taskArchive' => $archive->limit(100)->get(),
            'mediaOptions' => [],
            'reviewEvents' => $events,
            'canEditTask' => $this->canEditTask($context['user'], $task),
            'canReviewTasks' => $this->canReview($context['user']),
        ]);
    }

    public function assetFile(Request $request, OperationTaskAsset $asset, \App\Portals\OperationTasks\OperationTaskVideoService $videos): Response
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTask($context['user'], $asset->task);
        abort_unless($asset->file_path && Storage::disk('local')->exists($asset->file_path), 404);
        return $videos->fileResponse($asset->file_path);
    }

    private function authorized(Request $request): array
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        return $context;
    }

    private function authorizeTask(AdminUser $user, OperationTestTask $task): void
    {
        abort_unless($this->canViewTask($user, $task), 403);
    }

    private function canViewTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || in_array((int) $task->department_id, $this->visibleDepartmentIds($user), true);
    }

    private function visibleDepartmentIds(AdminUser $user): array
    {
        return app(\App\Portals\ReviewCenter\ReviewCenterAuthorizationService::class)->departmentIds($user);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        $menuCode = $request->routeIs('review-center.operation-tasks.dashboard') ? 'review_center_operation_task_dashboard' : 'review_center_operation_tasks';
        $menu = $context['portal']->menus()->where('code', $menuCode)->where('is_enabled', true)->first();
        abort_unless($menu && app(\App\Portal\Services\PortalMenuAuthorizationService::class)->canAccess($context['user'], $menu), 403);
        abort_unless($this->canReview($context['user']), 403);
        app()->setLocale('zh-CN');
        return $context;
    }

    private function canReview(AdminUser $user): bool
    {
        return $this->reviewChannels($user) !== [];
    }

    private function reviewChannels(AdminUser $user): array
    {
        return $this->visibleDepartmentIds($user) !== [] || $user->isAdministrator() ? ['department' => __('operation_tasks.recap_review.department')] : [];
    }

    private function canManage(AdminUser $user): bool { return true; }

    private function canEditTask(AdminUser $user, OperationTestTask $task): bool { return false; }

    private function validateTask(Request $request, array $rules, array $messages = []): array
    {
        $messages += [
            'assets.*.upload_token.uuid' => __('operation_tasks.upload.invalid_token'),
            'assets.*.file.prohibited' => __('operation_tasks.validation.direct_file'),
            'size.min' => __('operation_tasks.upload.too_large'),
            'size.max' => __('operation_tasks.upload.too_large'),
        ];
        if (isset($rules['assets']) && in_array($request->input('page_type'), ['single', 'category'], true)) {
            $messages['assets.min'] ??= __('operation_tasks.validation.asset_min', [
                'type' => __('operation_tasks.validation.'.($request->input('page_type') === 'category' ? 'category' : 'single')),
            ]);
        }
        return $request->validate($rules, $messages, __('operation_tasks.validation.attributes'));
    }

    private function canLeaderReviewTask(AdminUser $user, OperationTestTask $task): bool { return false; }

    private function canDepartmentReviewTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || in_array((int) $task->department_id, $this->departmentReviewScopeIds($user), true);
    }

    private function rejectWhen(mixed $condition, string $field, string $message): void
    {
        if ($condition) throw ValidationException::withMessages([$field => $message]);
    }

    private function storeTaskReview(
        OperationTestTask $task,
        OperationTestTask|OperationTaskAsset $target,
        string $targetType,
        string $channel,
        string $result,
        ?string $opinion,
        string $rejectionScope,
        AdminUser $reviewer
    ): void {
        $rejectionScopes = array_values(array_filter(explode(',', $rejectionScope)));
        $version = (int) $target->version;
        $review = OperationTaskReview::query()->firstOrNew([
            'target_type' => $targetType,
            'target_id' => $target->id,
            'target_version' => $version,
            'channel' => $channel,
        ]);
        $review->fill([
            'result' => $result,
            'opinion' => trim((string) $opinion) ?: null,
            'rejection_scope' => $result === 'rejected' ? $rejectionScope : null,
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $this->userName($reviewer),
            'reviewed_at' => now(),
        ]);
        $review->save();
        OperationTaskReviewEvent::query()->create([
            'review_id' => $review->id,
            'target_type' => $targetType,
            'target_id' => $target->id,
            'target_version' => $version,
            'channel' => $channel,
            'result' => $result,
            'opinion' => $review->opinion,
            'rejection_scope' => $review->rejection_scope,
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $this->userName($reviewer),
            'reviewed_at' => now(),
        ]);
        $shouldCreateRectification = $result === 'rejected'
            && ($targetType === 'asset' || in_array('task', $rejectionScopes, true));
        if ($shouldCreateRectification) {
            OperationTaskRectification::query()->updateOrCreate(['source_review_id' => $review->id], [
                'task_id' => $task->id,
                'asset_id' => $targetType === 'asset' ? $target->id : null,
                'target_type' => $targetType,
                'target_id' => $target->id,
                'target_version' => $version,
                'review_channel' => $channel,
                'rejection_scope' => $targetType === 'asset' ? 'asset' : 'task',
                'rejection_opinion' => $review->opinion ?: __('review_center.copy.text_006'),
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $this->userName($reviewer),
                'status' => 'required',
            ]);
        } else {
            $rectification = OperationTaskRectification::query()->where('source_review_id', $review->id)->first();
            if ($rectification && in_array($rectification->status, ['required', 'resubmitted'], true)) {
                $rectification->update(['status' => $rectification->stop_confirmed_at ? 'restore_required' : 'cancelled']);
            }
        }
    }

    private function resolveResubmittedRectifications(OperationTestTask $task): void
    {
        $task->loadMissing(['reviews', 'assets.reviews', 'rectifications']);
        foreach ($task->rectifications->where('status', 'resubmitted') as $rectification) {
            $approved = false;
            if ($rectification->target_type === 'task' && (int) $rectification->resubmitted_task_version === (int) $task->version) {
                $approved = $this->taskDoubleApproved($task);
            } elseif ($rectification->target_type === 'asset' && $rectification->resubmitted_asset_id) {
                $asset = $task->assets->firstWhere('id', $rectification->resubmitted_asset_id);
                if ($asset) {
                    $channels = $asset->reviews->where('target_version', $asset->version)->where('result', 'approved')->pluck('channel')->unique();
                    $approved = $channels->intersect(['leader', 'department'])->count() === 2;
                }
            }
            if ($approved) $rectification->update(['status' => $rectification->stop_confirmed_at ? 'completed' : 'cancelled']);
        }
    }

    private function synchronizeTaskRectificationState(OperationTestTask $task): void
    {
        $rectifications = OperationTaskRectification::query()->where('task_id', $task->id)->get();
        $open = $rectifications->whereIn('status', ['required', 'resubmitted', 'restore_required']);
        $needsRestore = $rectifications->contains(fn (OperationTaskRectification $item) => $item->stop_confirmed_at && ! $item->restored_at && $item->status !== 'cancelled');
        $task->update([
            'rectification_status' => $open->isNotEmpty() ? ($open->contains('status', 'required') ? 'required' : 'resubmitted') : ($rectifications->isEmpty() ? 'none' : 'completed'),
            'delivery_status' => $open->contains('status', 'required') ? 'manual_stop_required' : ($needsRestore ? 'manual_stop_confirmed' : ($task->delivery_status === 'manual_stop_required' ? 'not_started' : $task->delivery_status)),
        ]);
    }

    private function notifyUsers(array $recipientIds, AdminUser $sender, string $title, string $content): void
    {
        $recipientIds = collect($recipientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($recipientIds->isEmpty()) return;
        $message = InternalMessage::query()->create([
            'title' => $title, 'content' => $content, 'sender_user_id' => $sender->id, 'sender_name' => $this->userName($sender),
            'sender_portal_code' => self::PORTAL_CODE, 'sender_portal_name' => __('review_center.title'), 'published_at' => now(),
        ]);
        $now = now();
        InternalMessageRecipient::query()->insert($recipientIds->map(fn (int $id) => ['internal_message_id' => $message->id, 'recipient_user_id' => $id, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    private function notifyReviewers(OperationTestTask $task, string $content, AdminUser $sender, ?array $channels = null): void
    {
        $this->notifyUsers(array_values(array_diff($this->reviewerUserIds($task, $channels), [(int) $sender->id])), $sender, __('operation_tasks.messages.pending_title'), $content);
    }

    private function reviewerUserIds(OperationTestTask $task, ?array $channels = null): array
    {
        $channels ??= ['leader', 'department'];
        $ids = collect();
        if (in_array('leader', $channels, true) && $task->department_id) {
            $team = Department::query()->with('departmentMembers')->find($task->department_id);
            if ($team) $ids = $ids->merge($team->departmentMembers->filter(fn (AdminUser $member) => in_array($member->pivot?->position, [Department::POSITION_MANAGER, Department::POSITION_DEPUTY], true))->pluck('id'));
        }
        if (in_array('department', $channels, true) && $task->department_id) {
            $team = Department::query()->with(['parent.departmentMembers'])->find($task->department_id);
            if ($team?->parent) $ids = $ids->merge($team->parent->departmentMembers->filter(fn (AdminUser $member) => $member->pivot?->position === Department::POSITION_MANAGER)->pluck('id'));
        }
        return $ids->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function userName(AdminUser $user): string
    {
        return (string) ($user->name ?: $user->username ?: (__('review_center.copy.text_007').$user->id));
    }

    private function taskDoubleApproved(OperationTestTask $task): bool
    {
        $reviews = $task->reviews->where('target_version', $task->version);
        return $reviews->where('result', 'approved')->pluck('channel')->unique()->intersect(['leader', 'department'])->count() === 2;
    }

    private function departmentReviewScopeIds(AdminUser $user): array { return $this->visibleDepartmentIds($user); }

    private function recapReviewChannel(AdminUser $user, OperationTaskRecap $recap): ?string
    {
        if ($user->isAdministrator()) return 'department';
        if ($this->canLeaderReviewRecap($user, $recap)) return 'leader';
        return $this->canDepartmentReviewRecap($user, $recap) ? 'department' : null;
    }

    private function canLeaderReviewRecap(AdminUser $user, OperationTaskRecap $recap): bool { return false; }

    private function canDepartmentReviewRecap(AdminUser $user, OperationTaskRecap $recap): bool
    {
        return $user->isAdministrator() || in_array((int) $recap->department_id, $this->departmentReviewScopeIds($user), true);
    }

    private function visibleSecondaryDepartments(AdminUser $user, Portal $portal): Collection
    {
        return Department::business()->whereNotNull('parent_id')->whereIn('id', $this->visibleDepartmentIds($user))->with(['parent:id,name', 'departmentMembers:id,name,username'])->orderBy('sort')->orderBy('name')->get();
    }

    public function dashboard(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->canManage($context['user']), 403);
        [$from, $to, $rangeStart, $rangeUntil] = $this->submissionRange($request, 'from', 'to', true);
        $withinRange = static fn ($date): bool => $date !== null
            && (! $rangeStart || $date->gte($rangeStart)) && (! $rangeUntil || $date->lt($rangeUntil));
        $applyRange = static function ($query, string $column) use ($rangeStart, $rangeUntil): void {
            if ($rangeStart) $query->where($column, '>=', $rangeStart);
            if ($rangeUntil) $query->where($column, '<', $rangeUntil);
        };
        $allTeams = $this->visibleSecondaryDepartments($context['user'], $context['portal']);
        $selectedTeamId = (int) $request->input('department', 0);
        if ($selectedTeamId && ! $allTeams->contains('id', $selectedTeamId)) $selectedTeamId = 0;
        $teams = $selectedTeamId ? $allTeams->where('id', $selectedTeamId)->values() : $allTeams;
        $filters = ['department' => $selectedTeamId ?: '', 'owner' => trim((string) $request->input('person_search', $request->input('owner', '')))];
        if ($request->filled('team_search')) $teams = $teams->filter(fn ($team) => str_contains(mb_strtolower($team->name), mb_strtolower(trim((string) $request->input('team_search')))));
        $teamIds = $teams->modelKeys();
        $memberIds = $teams->flatMap(fn (Department $team) => $team->departmentMembers->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $recapQuery = OperationTaskRecap::query()->whereIn('department_id', $this->visibleDepartmentIds($context['user']))->where(function ($query) use ($teamIds, $memberIds): void {
            $query->whereIn('department_id', $teamIds)->orWhereIn('user_id', $memberIds);
        });
        $applyRange($recapQuery, 'submitted_at');
        $recaps = $recapQuery->get();
        // Include historical activity for its own column, never as a new product.
        $tasks = OperationTestTask::query()->whereIn('department_id', $this->visibleDepartmentIds($context['user']))->with(['assets' => fn ($query) => $applyRange($query, 'created_at')])
            ->where(function ($query) use ($teamIds, $memberIds): void {
                $query->whereIn('department_id', $teamIds)->orWhereIn('owner_user_id', $memberIds);
            })->where(function ($query) use ($applyRange, $rangeStart, $rangeUntil): void {
                if (! $rangeStart && ! $rangeUntil) return;
                $query->where(fn ($created) => $applyRange($created, 'created_at'))
                    ->orWhere(fn ($submitted) => $applyRange($submitted, 'submitted_at'))
                    ->orWhereHas('assets', fn ($assets) => $applyRange($assets, 'created_at'));
            })->get();
        if ($filters['owner'] !== '') {
            $selectedUsers = AdminUser::query()->whereIn('id', $memberIds)->get()
                ->filter(fn ($member) => str_contains(mb_strtolower($this->userName($member)), mb_strtolower($filters['owner'])))->pluck('id');
            $recaps = $recaps->whereIn('user_id', $selectedUsers);
            $tasks = $tasks->whereIn('owner_user_id', $selectedUsers);
        }
        // Blank bounds mean all dates. Derive calendar bounds from real activity rather than year 2000.
        $activityDates = $recaps->pluck('submitted_at')->merge($tasks->pluck('created_at'))
            ->merge($tasks->flatMap(fn ($task) => $task->assets->pluck('created_at')))
            ->filter()->map(fn ($date) => $date->copy()->timezone('Asia/Shanghai')->toDateString());
        $calendarFrom = $from ?: ($activityDates->min() ?: now('Asia/Shanghai')->toDateString());
        $calendarTo = $to ?: max($calendarFrom, $activityDates->max() ?: now('Asia/Shanghai')->toDateString());
        $days = collect();
        for ($day = Carbon::parse($calendarFrom, 'Asia/Shanghai'); $day->lte(Carbon::parse($calendarTo, 'Asia/Shanghai')); $day->addDay()) $days->push($day->toDateString());
        $plans = OperationTaskDailyPlan::query()->whereIn('department_id', $this->visibleDepartmentIds($context['user']))->whereBetween('plan_date', [$calendarFrom, $calendarTo])
            ->where(function ($query) use ($teamIds, $memberIds): void {
                $query->whereIn('department_id', $teamIds)->orWhereIn('user_id', $memberIds);
            })->get()->keyBy(fn ($plan) => $plan->user_id.'|'.$plan->plan_date->format('Y-m-d'));
        $teamRows = $teams->map(function (Department $team) use ($recaps, $tasks, $plans, $days, $filters, $withinRange) {
            $members = collect($team->departmentMembers->all())->filter(fn (AdminUser $member) => $filters['owner'] === '' || str_contains(mb_strtolower($this->userName($member)), mb_strtolower($filters['owner'])));
            $rows = $members->map(function (AdminUser $member) use ($recaps, $tasks, $plans, $days, $withinRange) {
                $memberRecaps = $recaps->where('user_id', $member->id);
                $memberTasks = $tasks->where('owner_user_id', $member->id);
                $newTasks = $memberTasks->filter(fn ($task) => $withinRange($task->created_at));
                $historical = static fn ($asset, $task): bool => $asset->created_at->copy()->timezone('Asia/Shanghai')->toDateString()
                    > $task->created_at->copy()->timezone('Asia/Shanghai')->toDateString();
                $initialCount = (int) $memberTasks->sum(fn ($task) => $task->assets->reject(fn ($asset) => $historical($asset, $task))->count());
                $supplementCount = (int) $memberTasks->sum(fn ($task) => $task->assets->filter(fn ($asset) => $historical($asset, $task))->count());
                $progresses = $days->map(fn (string $date) => $this->dailyPlanProgress($plans->get($member->id.'|'.$date), $memberTasks, $date));
                $recapRecords = $memberRecaps->sortByDesc('submitted_at')->map(fn ($recap) => $this->recapPayload($recap))->values()->all();
                $bySubmissionDay = $memberRecaps->groupBy(fn ($recap) => $recap->submitted_at?->copy()->timezone('Asia/Shanghai')->toDateString());
                $recapDayRecords = $days->sortDesc()->flatMap(function (string $date) use ($bySubmissionDay) {
                    $records = $bySubmissionDay->get($date, collect());
                    return $records->isEmpty() ? [['date' => $date, 'missing' => true]]
                        : $records->sortByDesc('submitted_at')->map(fn ($recap) => $this->recapPayload($recap))->values()->all();
                })->values()->all();
                $taskRecords = $memberTasks->sortByDesc('created_at')->map(fn ($task) => [
                    'id' => $task->id, 'task_no' => $task->task_no, 'objective' => $task->selection_objective,
                    'page_type' => $task->page_type, 'submitted_at' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->submitted_at),
                    'new_task' => $withinRange($task->created_at),
                    'supplement_count' => $task->assets->filter(fn ($asset) => $historical($asset, $task))->count(),
                    'asset_count' => $task->assets->count(), 'url' => route('review-center.operation-tasks.tests.show', $task),
                ])->values()->all();
                return (object) [
                    'id' => (int) $member->id, 'name' => $this->userName($member), 'position' => $this->departmentPositionLabel($member->pivot?->position),
                    'recap_days' => $bySubmissionDay->keys()->filter()->count(), 'expected_days' => $days->count(),
                    'revenue' => (float) $memberRecaps->sum('total_revenue_usd'), 'spend' => (float) $memberRecaps->sum('total_spend_usd'), 'orders' => (int) $memberRecaps->sum('total_orders'),
                    'single_count' => $newTasks->where('page_type', 'single')->count(),
                    'single_assets' => (int) $memberTasks->where('page_type', 'single')->sum(fn ($task) => $task->assets->count()),
                    'category_count' => $newTasks->where('page_type', 'category')->count(),
                    'category_assets' => (int) $memberTasks->where('page_type', 'category')->sum(fn ($task) => $task->assets->count()),
                    'initial_assets' => $initialCount, 'supplement_assets' => $supplementCount,
                    'planned_days' => $progresses->where('has_plan', true)->count(), 'completed_days' => $progresses->where('complete', true)->count(),
                    'missing_plan_days' => $progresses->where('has_plan', false)->count(), 'daily_progress' => $progresses->values()->all(),
                    'task_records' => $taskRecords, 'recap_records' => $recapRecords, 'recap_day_records' => $recapDayRecords,
                ];
            })->values();
            return (object) ['id' => (int) $team->id, 'name' => $team->name, 'parent_name' => $team->parent?->name, 'members' => $rows, 'member_count' => $rows->count()];
        })->values();
        return view('portals.review-center.operation-tasks.dashboard', [
            ...$context, 'from' => $from, 'to' => $to, 'recaps' => $recaps, 'tasks' => $tasks, 'teamRows' => $teamRows,
            'searchTeamOptions' => $allTeams->pluck('name'),
            'searchPersonOptions' => $allTeams->flatMap(fn ($team) => $team->departmentMembers)->map(fn ($member) => $this->userName($member))->unique(),
            'operators' => $teamRows->flatMap(fn ($team) => $team->members)->pluck('name')->unique()->sort()->values(),
            'teams' => $allTeams, 'visibleMemberCount' => $teamRows->sum(fn ($team) => $team->member_count),
            'dashboardFilters' => $filters, 'canManagePlans' => true,
        ]);
    }

    private function departmentPositionLabel(?string $position): string
    {
        return match ($position) {
            Department::POSITION_MANAGER => __('review_center.dashboard_copy.text_001'), Department::POSITION_DEPUTY => __('review_center.dashboard_copy.text_002'), default => __('review_center.dashboard_copy.text_003'),
        };
    }

    private function dailyPlanProgress(?OperationTaskDailyPlan $plan, Collection $memberTasks, string $date): array
    {
        if (! $plan) return ['date' => $date, 'has_plan' => false, 'complete' => false, 'label' => __('review_center.dashboard_copy.text_004'), 'single_target' => 0, 'category_target' => 0, 'single_done' => 0, 'category_done' => 0];
        $dayTasks = $memberTasks->filter(function (OperationTestTask $task) use ($date): bool {
            $submitted = optional($task->created_at)->timezone('Asia/Shanghai')->toDateString() === $date;
            return $submitted;
        });
        $qualified = function (string $pageType, int $minimum) use ($dayTasks, $date): int {
            return $dayTasks->where('page_type', $pageType)->filter(fn (OperationTestTask $task) => $task->assets->filter(fn (OperationTaskAsset $asset) => optional($asset->created_at)->timezone('Asia/Shanghai')->toDateString() === $date)->count() >= $minimum)->count();
        };
        $singleDone = $qualified('single', 1); $categoryDone = $qualified('category', 1);
        $complete = $singleDone >= (int) $plan->single_target && $categoryDone >= (int) $plan->category_target;
        return ['date' => $date, 'has_plan' => true, 'complete' => $complete, 'label' => $plan->plan_mode === 'single' ? __('review_center.dashboard_copy.text_005') : ($plan->plan_mode === 'category' ? __('review_center.dashboard_copy.text_006') : __('review_center.dashboard_copy.text_007')), 'single_target' => (int) $plan->single_target, 'category_target' => (int) $plan->category_target, 'single_done' => $singleDone, 'category_done' => $categoryDone, 'note' => $plan->plan_note];
    }

    private function recapPayload(OperationTaskRecap $recap): array
    {
        $answers = collect($recap->question_answers ?? [])->map(fn (array $answer) => ['title' => (string) ($answer['title'] ?? __('review_center.dashboard_copy.text_008')), 'answer' => (string) ($answer['answer'] ?? '—')])->values()->all();
        if ($answers === []) $answers = collect([
            ['title' => __('review_center.dashboard_copy.text_009'), 'answer' => $recap->finding_answer], ['title' => __('review_center.dashboard_copy.text_010'), 'answer' => $recap->action_answer],
            ['title' => __('review_center.dashboard_copy.text_011'), 'answer' => $recap->advertising_plan], ['title' => __('review_center.dashboard_copy.text_012'), 'answer' => $recap->support_needed],
        ])->filter(fn (array $item) => filled($item['answer']))->values()->all();
        return [
            'submitted_at' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($recap->submitted_at),
            'date' => $recap->data_date->format('Y-m-d'), 'version' => (int) $recap->version, 'updated_at' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($recap->updated_at),
            'workflow_status' => (string) ($recap->workflow_status ?? 'approved'),
            'leader_review_status' => (string) ($recap->leader_review_status ?? 'approved'),
            'department_review_status' => (string) ($recap->department_review_status ?? 'approved'),
            'rejection_opinion' => $recap->last_rejection_opinion,
            'revenue' => (float) $recap->total_revenue_usd, 'spend' => (float) $recap->total_spend_usd, 'orders' => (int) $recap->total_orders,
            'media' => collect($recap->media_items ?? [])->map(fn (array $item) => ['name' => (string) ($item['name'] ?? '—'), 'spend' => (float) ($item['spend'] ?? 0), 'orders' => (int) ($item['orders'] ?? 0), 'revenue' => (float) ($item['revenue'] ?? 0), 'roi' => isset($item['roi']) ? (float) $item['roi'] : null])->values()->all(),
            'answers' => $answers,
        ];
    }

    private function submissionRange(Request $request, string $fromName = 'from', string $toName = 'to', bool $defaultWeek = false): array
    {
        $data = $this->validateTask($request, [
            $fromName => ['nullable', 'date_format:Y-m-d'], $toName => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = (string) ($data[$fromName] ?? '');
        $to = (string) ($data[$toName] ?? '');
        if ($defaultWeek && ! $request->has($fromName) && ! $request->has($toName) && ! $request->boolean('all_time')) {
            $from = now('Asia/Shanghai')->subDays(6)->toDateString();
            $to = now('Asia/Shanghai')->toDateString();
        }
        $this->rejectWhen($from !== '' && $to !== '' && $from > $to, $toName, __('operation_tasks.dates.invalid'));
        return [$from, $to,
            $from !== '' ? Carbon::parse($from, 'Asia/Shanghai')->startOfDay()->utc() : null,
            $to !== '' ? Carbon::parse($to, 'Asia/Shanghai')->startOfDay()->addDay()->utc() : null,
        ];
    }

    private function leaderDepartmentIds(AdminUser $user): array { return []; }
}
