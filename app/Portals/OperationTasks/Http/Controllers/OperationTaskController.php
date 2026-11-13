<?php

namespace App\Portals\OperationTasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\OperationAssetDailyRecord;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskDailyPlan;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskRecapQuestion;
use App\Models\OperationTaskRectification;
use App\Models\OperationTaskReview;
use App\Models\OperationTaskReviewEvent;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\OperationTaskAssetUploadService;
use App\Services\DepartmentAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class OperationTaskController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';

    private const MEDIA = [
        'Mintegral', 'AppLovin', 'Google', 'Snapchat', 'MediaGo', 'SmartNews',
        'Pinterest', 'BrainX', 'Bigo', 'NewsBreak', 'Facebook', 'TikTok',
        '其他', 'Google老户', 'TikTok加白户',
    ];

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly DepartmentAuthorizationService $departments,
    ) {
    }

    public function recaps(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $user = $context['user'];
        $yesterday = now('Asia/Shanghai')->subDay()->toDateString();
        $yesterdayRecap = OperationTaskRecap::query()
            ->where('user_id', $user->id)->whereDate('data_date', $yesterday)->first();

        $query = OperationTaskRecap::query()->orderByDesc('data_date')->orderByDesc('id');
        if (! $this->canManage($user)) {
            $query->where('user_id', $user->id);
        } elseif (! $user->isAdministrator()) {
            $query->where(function ($visibility) use ($user): void {
                $visibility->whereIn('department_id', $this->visibleDepartmentIds($user))
                    ->orWhere('user_id', $user->id);
            });
        }

        $searchTeamOptions = (clone $query)->reorder()->setEagerLoads([])->select('department_name')->distinct()->pluck('department_name');
        $searchPersonOptions = (clone $query)->reorder()->setEagerLoads([])->select('user_name')->distinct()->pluck('user_name');
        foreach (['team_search' => 'department_name', 'person_search' => 'user_name'] as $parameter => $column) {
            if ($request->filled($parameter)) $query->where($column, 'like', '%'.trim((string) $request->input($parameter)).'%');
        }

        // Only the dedicated edit endpoint may bind an editable record.
        if ($request->has('edit') && ! $request->attributes->has('editing_recap')) {
            $id = (string) $request->input('edit');
            return ctype_digit($id) && (int) $id > 0
                ? redirect()->route('operation-tasks.recaps.edit', ['recap' => $id])
                : redirect()->route('operation-tasks.recaps.index')->with('warning', __('operation_tasks.recap_form.unavailable'));
        }
        $editingRecap = $request->attributes->get('editing_recap');
        if (old('recap_mode') && (old('recap_mode') !== ($editingRecap ? 'edit' : 'new') || ($editingRecap && (int) old('recap_id') !== (int) $editingRecap->id))) {
            $request->session()->forget('_old_input');
        }

        $recapPerPage = in_array((int) $request->input('recap_per_page', 20), [20, 50, 100], true)
            ? (int) $request->input('recap_per_page', 20)
            : 20;
        $recaps = $query->paginate($recapPerPage)->withQueryString();
        $recaps->getCollection()->each(function (OperationTaskRecap $recap) use ($user): void {
            $recap->setAttribute('review_channel', $this->recapReviewChannel($user, $recap));
            $recap->setAttribute('can_leader_review', $this->canLeaderReviewRecap($user, $recap));
            $recap->setAttribute('can_department_review', $this->canDepartmentReviewRecap($user, $recap));
        });

        return view('portals.operation-tasks.recaps', [
            ...$context, 'searchTeamOptions' => $searchTeamOptions, 'searchPersonOptions' => $searchPersonOptions,
            'recaps' => $recaps,
            'recapQuestions' => OperationTaskRecapQuestion::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')->get(),
            'allRecapQuestions' => $user->isAdministrator() ? OperationTaskRecapQuestion::query()->orderBy('sort')->orderBy('id')->get() : collect(),
            'canManageRecapQuestions' => $user->isAdministrator(),
            'mediaOptions' => self::MEDIA,
            'yesterday' => $yesterday,
            'yesterdayRecap' => $yesterdayRecap,
            'editingRecap' => $editingRecap,
            'openRecapModal' => $request->boolean('new') || $editingRecap !== null,
        ]);
    }

    public function editRecap(Request $request, int $recap): Response|View
    {
        $context = $this->authorized($request);
        $record = OperationTaskRecap::query()->where('user_id', $context['user']->id)->find($recap);
        if (! $record || $record->workflow_status !== 'rejected') {
            return redirect()->route('operation-tasks.recaps.index')->with('warning', __('operation_tasks.recap_form.unavailable'));
        }
        $request->attributes->set('editing_recap', $record);
        return $this->recaps($request);
    }

    public function storeRecap(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $user = $context['user'];
        $this->validateTask($request, [
            'recap_mode' => ['required', Rule::in(['new'])],
            'recap_id' => ['prohibited'], 'recap_version' => ['prohibited'],
        ], ['recap_mode.required' => __('operation_tasks.recap_form.refresh'), 'recap_mode.in' => __('operation_tasks.recap_form.refresh')]);
        $businessDate = now('Asia/Shanghai')->subDay()->toDateString();
        if (OperationTaskRecap::query()->where('user_id', $user->id)->whereDate('data_date', $businessDate)->exists()) {
            return back()->withInput()->with('warning', __('operation_tasks.recap_form.exists'));
        }
        $payload = $this->recapSubmissionPayload($request, $user, $context['portal'], $businessDate);
        try {
            $saved = DB::connection((new OperationTaskRecap())->getConnectionName())->transaction(function () use ($payload, $user): OperationTaskRecap {
                $record = OperationTaskRecap::query()->create([...$payload,
                    'version' => 1, 'workflow_status' => 'pending_leader', 'leader_review_status' => 'pending', 'department_review_status' => 'pending', 'submitted_at' => now(),
                ]);
                $this->recordRecapSubmission($record, $user, false);
                return $record;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (OperationTaskRecap::query()->where('user_id', $user->id)->whereDate('data_date', $businessDate)->exists()) {
                return back()->withInput()->with('warning', __('operation_tasks.recap_form.exists'));
            }
            throw $exception;
        }
        return redirect()->route('operation-tasks.recaps.index')->with('success', __('operation_tasks.recap_form.created', ['date' => $saved->data_date->format('Y-m-d')]));
    }

    public function resubmitRecap(Request $request, int $recap): RedirectResponse
    {
        $context = $this->authorized($request);
        $user = $context['user'];
        $record = OperationTaskRecap::query()->where('user_id', $user->id)->find($recap);
        if (! $record || $record->workflow_status !== 'rejected') {
            return redirect()->route('operation-tasks.recaps.index')->with('warning', __('operation_tasks.recap_form.unavailable'));
        }
        $identity = $this->validateTask($request, [
            'recap_mode' => ['required', Rule::in(['edit'])], 'recap_id' => ['required', 'integer', 'min:1'], 'recap_version' => ['required', 'integer', 'min:1'],
        ], ['recap_mode.required' => __('operation_tasks.recap_form.refresh'), 'recap_mode.in' => __('operation_tasks.recap_form.refresh')]);
        if ((int) $identity['recap_id'] !== $recap) {
            return back()->withInput()->with('warning', __('operation_tasks.recap_form.target_mismatch'));
        }
        $payload = $this->recapSubmissionPayload($request, $user, $context['portal'], $record->data_date->format('Y-m-d'));
        $saved = DB::connection($record->getConnectionName())->transaction(function () use ($recap, $user, $identity, $payload): OperationTaskRecap {
            $existing = OperationTaskRecap::query()->where('user_id', $user->id)->lockForUpdate()->find($recap);
            if (! $existing || $existing->workflow_status !== 'rejected' || (int) $existing->version !== (int) $identity['recap_version']) {
                throw ValidationException::withMessages(['recap' => __('operation_tasks.recap_form.stale')]);
            }
            $payload['data_date'] = $existing->data_date->format('Y-m-d');
            $history = $existing->version_history ?? [];
            $history[] = [
                'version' => $existing->version,
                'snapshot' => $existing->only(array_merge(array_keys($payload), ['workflow_status', 'leader_review_status', 'department_review_status', 'last_rejection_channel', 'last_rejection_opinion'])),
                'changed_by' => $this->userName($user),
                'changed_at' => now()->toIso8601String(),
            ];
            $existing->update([...$payload,
                'version' => $existing->version + 1, 'version_history' => $history,
                'workflow_status' => 'pending_leader', 'leader_review_status' => 'pending', 'department_review_status' => 'pending',
                'last_rejection_channel' => null, 'last_rejection_opinion' => null, 'last_rejected_at' => null, 'submitted_at' => now(),
            ]);
            $saved = $existing->fresh();
            $this->recordRecapSubmission($saved, $user, true);
            return $saved;
        });
        return redirect()->route('operation-tasks.recaps.index')->with('success', __('operation_tasks.recap_form.updated', ['date' => $saved->data_date->format('Y-m-d'), 'version' => $saved->version]));
    }

    private function recapSubmissionPayload(Request $request, AdminUser $user, Portal $portal, string $businessDate): array
    {
        $data = $this->validateTask($request, [
            'media' => ['required', 'array', 'min:1'],
            'media.*.name' => ['required', Rule::in(self::MEDIA)],
            'media.*.custom_name' => ['nullable', 'string', 'max:100'],
            'media.*.spend' => ['required', 'numeric', 'min:0'],
            'media.*.orders' => ['required', 'integer', 'min:0'],
            'media.*.revenue' => ['required', 'numeric', 'min:0'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string', 'max:10000'],
        ]);

        $questions = OperationTaskRecapQuestion::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')->get();
        foreach ($questions->where('is_required', true) as $question) {
            $this->rejectWhen(trim((string) ($data['answers'][$question->id] ?? '')) === '', 'answers.'.$question->id, __('operation_tasks.messages.answer_required', ['title' => $question->title]));
        }

        $items = collect($data['media'])->map(function (array $row): array {
            $name = $row['name'] === '其他' ? trim((string) ($row['custom_name'] ?? '')) : $row['name'];
            $this->rejectWhen($name === '', 'custom_media', __('operation_tasks.messages.custom_media_required'));
            return [
                'name' => $name,
                'spend' => (float) $row['spend'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'roi' => (float) $row['spend'] > 0 ? (float) $row['revenue'] / (float) $row['spend'] : null,
            ];
        });
        $this->rejectWhen($items->pluck('name')->map(fn ($value) => mb_strtolower(trim($value)))->duplicates()->isNotEmpty(), 'media', __('operation_tasks.messages.duplicate_media'));

        $answerFor = fn (string $code): string => trim((string) ($data['answers'][$questions->firstWhere('code', $code)?->id] ?? ''));
        $department = $this->departmentForPortal($user, $portal);
        $questionAnswers = $questions->map(fn (OperationTaskRecapQuestion $question): array => [
            'question_id' => $question->id,
            'title' => $question->title,
            'description' => $question->description,
            'is_required' => $question->is_required,
            'answer' => trim((string) ($data['answers'][$question->id] ?? '')),
        ])->values()->all();
        return [
            'data_date' => $businessDate,
            'user_id' => $user->id,
            'user_name' => $this->userName($user),
            'department_id' => $department?->id,
            'department_name' => $department?->name,
            'media_items' => $items->values()->all(),
            'total_revenue_usd' => $items->sum('revenue'),
            'total_orders' => $items->sum('orders'),
            'total_spend_usd' => $items->sum('spend'),
            'finding_answer' => $answerFor('finding') ?: '—',
            'action_answer' => $answerFor('action') ?: '—',
            'advertising_plan' => $answerFor('advertising') ?: '—',
            'support_needed' => $answerFor('support') ?: null,
            'question_answers' => $questionAnswers,
        ];

    }

    private function recordRecapSubmission(OperationTaskRecap $recap, AdminUser $user, bool $resubmitted): void
    {
        OperationTaskReviewEvent::query()->create([
            'review_id' => null, 'target_type' => 'recap', 'target_id' => $recap->id, 'target_version' => $recap->version,
            'channel' => 'operation', 'result' => $resubmitted ? 'resubmitted' : 'submitted', 'opinion' => null, 'rejection_scope' => null,
            'reviewer_id' => $user->id, 'reviewer_name' => $this->userName($user), 'reviewed_at' => now(),
        ]);
        $this->notifyRecapReviewers($recap, __('operation_tasks.recap_review.submitted_message', ['name' => $this->userName($user), 'date' => $recap->data_date->format('Y-m-d')]), $user, ['leader', 'department']);
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

    public function saveRecapQuestions(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        abort_unless($context['user']->isAdministrator(), 403);
        $data = $this->validateTask($request, [
            'questions' => ['nullable', 'array'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.title' => ['required', 'string', 'max:500'],
            'questions.*.description' => ['nullable', 'string', 'max:10000'],
            'questions.*.is_required' => ['nullable', 'boolean'],
        ]);

        $keptIds = [];
        foreach (array_values($data['questions'] ?? []) as $sortIndex => $question) {
            $id = (int) ($question['id'] ?? 0);
            $values = [
                'title' => trim($question['title']),
                'description' => trim((string) ($question['description'] ?? '')) ?: null,
                'is_required' => ! empty($question['is_required']),
                'is_enabled' => true,
                'sort' => ($sortIndex + 1) * 10,
                'updated_at' => now(),
            ];
            if ($id && OperationTaskRecapQuestion::query()->whereKey($id)->exists()) {
                OperationTaskRecapQuestion::query()->whereKey($id)->update($values);
                $keptIds[] = $id;
            } else {
                $keptIds[] = OperationTaskRecapQuestion::query()->insertGetId([...$values, 'created_at' => now()]);
            }
        }
        $delete = OperationTaskRecapQuestion::query();
        if ($keptIds !== []) $delete->whereNotIn('id', $keptIds);
        $delete->delete();

        return back()->with('success', __('operation_tasks.messages.questions_saved'));
    }

    public function tests(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        $todayBeijing = now('Asia/Shanghai')->toDateString();
        $base = OperationTestTask::query()
            ->withCount('assets')
            ->with([
                'reviews',
                'assets.reviews',
                'assets.dailyRecords' => fn ($dailyRecords) => $dailyRecords->whereDate('data_date', $todayBeijing),
                'rectifications' => fn ($query) => $query->latest('id'),
            ])
            ->latest('submitted_at');
        if (! $this->canManage($user)) {
            $base->where('owner_user_id', $user->id);
        } elseif (! $user->isAdministrator()) {
            $base->where(function ($query) use ($user): void {
                $query->whereIn('department_id', $this->visibleDepartmentIds($user))->orWhere('owner_user_id', $user->id);
            });
        }

        $searchTeamOptions = (clone $base)->reorder()->setEagerLoads([])->select('department_name')->distinct()->pluck('department_name');
        $searchPersonOptions = (clone $base)->reorder()->setEagerLoads([])->select('owner_name')->distinct()->pluck('owner_name');
        foreach (['team_search' => 'department_name', 'person_search' => 'owner_name'] as $parameter => $column) {
            if ($request->filled($parameter)) $base->where($column, 'like', '%'.trim((string) $request->input($parameter)).'%');
        }

        $today = now('Asia/Shanghai')->startOfDay();
        $todayEnd = $today->copy()->endOfDay()->utc();
        $todayStart = $today->copy()->utc();
        $todayTasks = (clone $base)->where(function ($query) use ($todayStart, $todayEnd): void {
            $query->whereBetween('submitted_at', [$todayStart, $todayEnd])
                ->orWhereHas('assets', fn ($assets) => $assets->whereBetween('created_at', [$todayStart, $todayEnd]));
        })->get();
        $todayPlan = OperationTaskDailyPlan::query()->where('user_id', $user->id)->whereDate('plan_date', $today)->first();
        $todayProgress = $this->dailyPlanProgress($todayPlan, $todayTasks, $today->toDateString());
        $testPerPage = in_array((int) $request->input('test_per_page', 20), [20, 50, 100], true)
            ? (int) $request->input('test_per_page', 20)
            : 20;
        $rejectedTestCount = ! $this->canManage($user)
            ? OperationTaskRectification::query()
                ->where('status', 'required')
                ->whereHas('task', fn ($query) => $query->where('owner_user_id', $user->id))
                ->distinct('task_id')->count('task_id')
            : 0;
        if ($request->input('status') === 'rejected') {
            $base->whereHas('rectifications', fn ($query) => $query->where('status', 'required'));
        }

        $dateFilters = $this->validateTask($request, [
            'submitted_from' => ['nullable', 'date_format:Y-m-d'],
            'submitted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:submitted_from'],
        ]);
        $submittedFrom = (string) ($dateFilters['submitted_from'] ?? '');
        $submittedTo = (string) ($dateFilters['submitted_to'] ?? '');

        if ($submittedFrom !== '') {
            $base->where('submitted_at', '>=', Carbon::createFromFormat('Y-m-d', $submittedFrom, 'Asia/Shanghai')
                ->startOfDay()->utc());
        }
        if ($submittedTo !== '') {
            $base->where('submitted_at', '<=', Carbon::createFromFormat('Y-m-d', $submittedTo, 'Asia/Shanghai')
                ->endOfDay()->utc());
        }

        return view('portals.operation-tasks.tests', [
            ...$context, 'searchTeamOptions' => $searchTeamOptions, 'searchPersonOptions' => $searchPersonOptions,
            'tasks' => (clone $base)->paginate($testPerPage)->withQueryString(),
            'today' => $today->toDateString(),
            'todaySingle' => $todayTasks->where('page_type', 'single')->count(),
            'todayCategory' => $todayTasks->where('page_type', 'category')->count(),
            'todayAssets' => (int) $todayTasks->sum(fn (OperationTestTask $task) => $task->assets
                ->filter(fn (OperationTaskAsset $asset) => optional($asset->created_at)->timezone('Asia/Shanghai')->isSameDay($today))
                ->count()),
            'todayPassed' => $todayTasks->filter(fn (OperationTestTask $task) => $this->taskDoubleApproved($task))->count(),
            'todayPlan' => $todayPlan,
            'todayProgress' => $todayProgress,
            'rejectedTestCount' => $rejectedTestCount,
        ]);
    }

    public function beginAssetUpload(Request $request, OperationTaskAssetUploadService $uploads): JsonResponse
    {
        $context = $this->authorized($request);
        $data = $this->validateTask($request, [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.OperationTaskAssetUploadService::MAX_SIZE],
        ]);
        return response()->json($uploads->begin((int) $context['user']->id, $data['name'], (int) $data['size']));
    }

    public function assetUploadStatus(Request $request, string $token, OperationTaskAssetUploadService $uploads): JsonResponse
    {
        $context = $this->authorized($request);
        return response()->json($uploads->status((int) $context['user']->id, $token));
    }

    public function uploadAssetPart(Request $request, string $token, int $index, OperationTaskAssetUploadService $uploads): JsonResponse
    {
        $context = $this->authorized($request);
        $this->validateTask($request, ['file' => ['required', 'file', 'max:2048']]);
        return response()->json($uploads->part((int) $context['user']->id, $token, $index, $request->file('file')));
    }

    public function completeAssetUpload(Request $request, string $token, OperationTaskAssetUploadService $uploads): JsonResponse
    {
        $context = $this->authorized($request);
        return response()->json($uploads->complete((int) $context['user']->id, $token));
    }

    public function storeTest(Request $request, OperationTaskAssetUploadService $uploads): RedirectResponse
    {
        $context = $this->authorized($request);
        $user = $context['user'];
        $submission = $this->validateTask($request, ['submission_token' => ['required', 'uuid']], [
            'submission_token.required' => __('operation_tasks.validation.refresh_submission'),
            'submission_token.uuid' => __('operation_tasks.validation.refresh_submission'),
        ]);
        if ($response = $this->completedSubmission($submission['submission_token'], (int) $user->id)) return $response;
        $pageType = (string) $request->input('page_type');
        $minimum = $pageType === 'category' ? 3 : 2;
        $data = $this->validateTask($request, [
            'page_type' => ['required', Rule::in(['single', 'category'])],
            'task_type' => ['required', Rule::in(['new', 'iteration'])],
            'landing_url' => ['required', 'url', 'max:2048'],
            'selection_objective' => ['required', 'string', 'max:10000'],
            'assets' => ['required', 'array', 'min:'.$minimum],
            'assets.*.selling_point' => ['required', 'string', 'max:1000'],
            'assets.*.source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'assets.*.file' => ['prohibited'],
            'assets.*.upload_token' => ['nullable', 'uuid'],
            'assets.*.url' => ['nullable', 'url', 'max:2048'],
        ]);
        foreach ($data['assets'] as $index => $asset) {
            if (empty($asset['upload_token']) && empty($asset['url'])) {
                throw ValidationException::withMessages([
                    "assets.$index.upload_token" => __('operation_tasks.upload.required'),
                ]);
            }
        }
        $department = $this->departmentForPortal($user, $context['portal']);
        $connection = config('admin.database.connection') ?: config('database.default');
        try {
            $task = DB::connection($connection)->transaction(function () use ($data, $submission, $uploads, $user, $department): OperationTestTask {
                $task = OperationTestTask::query()->create([
                    'submission_token' => $submission['submission_token'],
                    'task_no' => 'OT'.now()->format('YmdHis').str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                    'owner_user_id' => $user->id,
                    'owner_name' => $this->userName($user),
                    'department_id' => $department?->id,
                    'department_name' => $department?->name,
                    'page_type' => $data['page_type'],
                    'task_type' => $data['task_type'],
                    'landing_url' => $data['landing_url'],
                    'selection_objective' => trim($data['selection_objective']),
                    'submitted_at' => now(),
                ]);
                foreach ($data['assets'] as $index => $asset) {
                    $file = empty($asset['upload_token']) ? [] : $uploads->consume((int) $user->id, $asset['upload_token'], "assets.$index.upload_token");
                    OperationTaskAsset::query()->create([
                        'task_id' => $task->id,
                        'uploader_user_id' => $user->id,
                        'uploader_name' => $this->userName($user),
                        'selling_point' => trim($asset['selling_point']),
                        'source_type' => $asset['source_type'],
                        'file_path' => $file['file_path'] ?? null,
                        'original_name' => $file['original_name'] ?? null,
                        'external_url' => trim((string) ($asset['url'] ?? '')) ?: null,
                        'mime_type' => $file['mime_type'] ?? null,
                        'ai_confirmed' => $asset['source_type'] === 'ai_created',
                        'version_history' => [['version' => 1, 'event' => 'created', 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
                    ]);
                }
                return $task;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent retry may have committed while this request was saving.
            if ($response = $this->completedSubmission($submission['submission_token'], (int) $user->id)) return $response;
            throw $exception;
        }
        $this->notifyReviewers($task, __('operation_tasks.messages.test_pending'), $user);

        return redirect()->route('operation-tasks.tests.show', $task)->with('success', __('operation_tasks.messages.test_saved'));
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
        $events = OperationTaskReviewEvent::query()
            ->where(function ($query) use ($task): void {
                $query->where('target_type', 'task')->where('target_id', $task->id)
                    ->orWhereIn('target_id', $task->assets->pluck('id'))->where('target_type', 'asset');
            })
            ->orderByDesc('reviewed_at')->orderByDesc('id')->get();
        $archive = OperationTestTask::query()->withCount('assets')->latest('submitted_at');
        if (! $this->canManage($context['user'])) $archive->where('owner_user_id', $context['user']->id);
        elseif (! $context['user']->isAdministrator()) $archive->whereIn('department_id', $this->visibleDepartmentIds($context['user']));

        return view('portals.operation-tasks.test-detail', [
            ...$context,
            'task' => $task,
            'taskArchive' => $archive->limit(100)->get(),
            'mediaOptions' => self::MEDIA,
            'reviewEvents' => $events,
            'canEditTask' => $this->canEditTask($context['user'], $task),
            'canReviewTasks' => $this->canReview($context['user']),
        ]);
    }

    public function assetFile(Request $request, OperationTaskAsset $asset): BinaryFileResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTask($context['user'], $asset->task);
        abort_unless($asset->file_path && Storage::disk('local')->exists($asset->file_path), 404);
        return response()->file(Storage::disk('local')->path($asset->file_path));
    }

    public function storeAssetDaily(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        $this->rejectWhen($asset->retired_at, 'asset', __('operation_tasks.messages.asset_retired_daily'));
        $data = $this->validateTask($request, [
            'media' => ['required', Rule::in(self::MEDIA)],
            'custom_media' => ['nullable', 'string', 'max:100'],
            'data_date' => ['required', 'date', 'before_or_equal:'.now('Asia/Shanghai')->toDateString()],
            'spend_usd' => ['required', 'numeric', 'min:0'],
            'orders' => ['required', 'integer', 'min:0'],
            'roi' => ['nullable', 'numeric', 'min:0'],
            'analysis' => ['required', 'string', 'max:5000'],
            'next_action' => ['required', 'string', 'max:5000'],
        ]);
        $media = $data['media'] === '其他' ? trim((string) ($data['custom_media'] ?? '')) : $data['media'];
        $this->rejectWhen($media === '', 'custom_media', __('operation_tasks.messages.custom_media_required'));
        $operatorName = $this->userName($context['user']);
        $record = OperationAssetDailyRecord::query()->where(['asset_id' => $asset->id, 'media' => $media])->whereDate('data_date', $data['data_date'])->first();
        $payload = [
            'asset_id' => $asset->id, 'media' => $media, 'data_date' => $data['data_date'],
            'spend_usd' => $data['spend_usd'], 'orders' => $data['orders'], 'roi' => $data['roi'] ?? null,
            'analysis' => trim($data['analysis']), 'next_action' => trim($data['next_action']),
            'created_by' => $record?->created_by ?: $context['user']->id,
            'updated_by' => $context['user']->id, 'updated_by_name' => $operatorName,
        ];
        if ($record) {
            $history = $record->version_history ?? [];
            $history[] = [
                'version' => $record->version,
                'snapshot' => $record->only(['media', 'data_date', 'spend_usd', 'orders', 'roi', 'analysis', 'next_action']),
                'changed_by' => $operatorName, 'changed_by_id' => $context['user']->id, 'changed_at' => now()->toIso8601String(),
            ];
            $record->update([...$payload, 'version' => $record->version + 1, 'version_history' => $history]);
        } else {
            OperationAssetDailyRecord::query()->create($payload);
        }
        return back()->with('success', __('operation_tasks.messages.daily_saved'));
    }

    public function appendAsset(Request $request, OperationTestTask $task): RedirectResponse
    {
        $context = $this->authorized($request);
        $this->authorizeTaskOwner($context['user'], $task);
        $data = $this->validateTask($request, [
            'selling_point' => ['required', 'string', 'max:1000'],
            'source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'url' => ['nullable', 'url', 'max:2048'],
        ]);
        $this->rejectWhen(! $request->hasFile('file') && empty($data['url']), 'file', __('operation_tasks.messages.asset_required'));
        $user = $context['user'];
        $file = $request->file('file');
        $asset = OperationTaskAsset::query()->create([
            'task_id' => $task->id, 'uploader_user_id' => $user->id, 'uploader_name' => $this->userName($user),
            'selling_point' => trim($data['selling_point']),
            'source_type' => $data['source_type'],
            'file_path' => $file?->store('operation-tasks/assets/'.now()->format('Y/m'), 'local'),
            'original_name' => $file?->getClientOriginalName(), 'external_url' => trim((string) ($data['url'] ?? '')) ?: null,
            'mime_type' => $file?->getMimeType(), 'ai_confirmed' => $data['source_type'] === 'ai_created',
            'version_history' => [['version' => 1, 'event' => 'created', 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
        ]);
        $this->notifyReviewers($task, __('operation_tasks.messages.asset_pending', ['number' => $task->task_no, 'name' => $asset->original_name ?: __('operation_tasks.messages.link_asset')]), $user);
        return back()->with('success', __('operation_tasks.messages.asset_appended'));
    }

    public function retireAsset(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        $this->rejectWhen($asset->retired_at, 'asset', __('operation_tasks.messages.asset_already_retired'));
        $data = $this->validateTask($request, ['retired_reason' => ['nullable', 'string', 'max:5000']]);
        $asset->update(['retired_at' => now(), 'retired_by' => $context['user']->id, 'retired_reason' => trim((string) ($data['retired_reason'] ?? '')) ?: null]);
        return back()->with('success', __('operation_tasks.messages.asset_retired'));
    }

    public function storeDailyPlan(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        abort_unless($this->canManage($context['user']), 403);
        $data = $this->validateTask($request, [
            'plan_date' => ['required', 'date'], 'department_id' => ['required', 'integer'], 'user_id' => ['required', 'integer'],
            'plan_mode' => ['required', Rule::in(['single', 'category', 'mixed'])],
            'single_target' => ['nullable', 'integer', 'min:0', 'max:30'], 'category_target' => ['nullable', 'integer', 'min:0', 'max:30'],
            'plan_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $teams = $this->visibleSecondaryDepartments($context['user'], $context['portal']);
        $team = $teams->firstWhere('id', (int) $data['department_id']);
        abort_unless($team, 403);
        $member = $team->departmentMembers->firstWhere('id', (int) $data['user_id']);
        $this->rejectWhen(! ($member), 'user_id', __('operation_tasks.messages.wrong_member'));

        [$singleTarget, $categoryTarget] = match ($data['plan_mode']) {
            'single' => [2, 0], 'category' => [0, 1],
            default => [(int) ($data['single_target'] ?? 0), (int) ($data['category_target'] ?? 0)],
        };
        $this->rejectWhen($data['plan_mode'] === 'mixed' && ($singleTarget < 1 || $categoryTarget < 1), 'single_target', __('operation_tasks.messages.mixed_required'));

        $plan = OperationTaskDailyPlan::query()->updateOrCreate(
            ['plan_date' => $data['plan_date'], 'user_id' => $member->id],
            [
                'user_name' => $this->userName($member), 'department_id' => $team->id, 'department_name' => $team->name,
                'plan_mode' => $data['plan_mode'], 'single_target' => $singleTarget, 'category_target' => $categoryTarget,
                'plan_note' => trim((string) ($data['plan_note'] ?? '')) ?: null,
                'confirmed_by' => $context['user']->id, 'confirmed_by_name' => $this->userName($context['user']), 'confirmed_at' => now(),
            ]
        );
        $this->notifyUsers([$member->id], $context['user'], __('operation_tasks.messages.plan_title'), sprintf(__('operation_tasks.messages.plan_confirmed'), $this->userName($context['user']), $plan->plan_date->format('Y-m-d'), $singleTarget, $categoryTarget));
        return back()->with('success', __('operation_tasks.messages.plan_saved'));
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
        $this->validateTask($request, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $submittedFrom = $filters['from'] !== ''
            ? Carbon::parse($filters['from'], 'Asia/Shanghai')->startOfDay()->utc()
            : null;
        $submittedUntil = $filters['to'] !== ''
            ? Carbon::parse($filters['to'], 'Asia/Shanghai')->startOfDay()->addDay()->utc()
            : null;
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
            $task->setAttribute('leader_reviewer_names', trim((string) optional($leaderReview)->reviewer_name) ?: ($leaderNames ?: '未配置'));
            $task->setAttribute('department_reviewer_names', trim((string) optional($departmentReview)->reviewer_name) ?: ($departmentNames ?: '未配置'));
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
            $recap->setAttribute('leader_reviewer_names', $leaderNames ?: '未配置');
            $recap->setAttribute('department_reviewer_names', $departmentNames ?: '未配置');
        });
        $recapFlowEvents = OperationTaskReviewEvent::query()
            ->where('target_type', 'recap')
            ->whereIn('target_id', $recaps->getCollection()->pluck('id'))
            ->orderBy('reviewed_at')->orderBy('id')->get()->groupBy('target_id');
        $recaps->getCollection()->each(fn (OperationTaskRecap $recap) => $recap->setAttribute('review_flow_events', $recapFlowEvents->get($recap->id, collect())));

        return view('portals.operation-tasks.reviews', [...$context, 'searchTeamOptions' => $searchTeamOptions, 'searchPersonOptions' => $searchPersonOptions, 'tasks' => $tasks, 'recaps' => $recaps, 'reviewFilters' => $filters, 'reviewChannels' => $channels, 'reviewTeams' => $teams]);
    }

    public function review(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $this->validateTask($request, [
            'target_type' => ['required', Rule::in(['task', 'asset'])], 'target_id' => ['required', 'integer'],
            'channel' => ['required', Rule::in(['leader', 'department'])], 'result' => ['required', Rule::in(['approved', 'rejected'])],
            'opinion' => ['nullable', 'string', 'max:5000'], 'rejection_scope' => ['nullable', Rule::in(['task', 'asset'])],
        ]);
        if ($data['result'] === 'rejected' && trim((string) ($data['opinion'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'opinion' => '驳回必须填写具体整改意见。',
            ]);
        }
        $target = $data['target_type'] === 'task' ? OperationTestTask::query()->findOrFail($data['target_id']) : OperationTaskAsset::query()->with('task')->findOrFail($data['target_id']);
        $task = $data['target_type'] === 'task' ? $target : $target->task;
        $allowed = $data['channel'] === 'leader' ? $this->canLeaderReviewTask($context['user'], $task) : $this->canDepartmentReviewTask($context['user'], $task);
        abort_unless($allowed, 403);

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($target, $task, $data, $context): void {
            $version = (int) $target->version;
            $review = OperationTaskReview::query()->firstOrNew([
                'target_type' => $data['target_type'], 'target_id' => $target->id, 'target_version' => $version, 'channel' => $data['channel'],
            ]);
            $review->fill([
                'result' => $data['result'], 'opinion' => trim((string) ($data['opinion'] ?? '')) ?: null,
                'rejection_scope' => $data['result'] === 'rejected' ? ($data['rejection_scope'] ?? $data['target_type']) : null,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);
            $review->save();
            OperationTaskReviewEvent::query()->create([
                'review_id' => $review->id, 'target_type' => $data['target_type'], 'target_id' => $target->id, 'target_version' => $version,
                'channel' => $data['channel'], 'result' => $data['result'], 'opinion' => $review->opinion, 'rejection_scope' => $review->rejection_scope,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);

            if ($data['result'] === 'rejected') {
                OperationTaskRectification::query()->updateOrCreate(
                    ['source_review_id' => $review->id],
                    [
                        'task_id' => $task->id, 'asset_id' => $data['target_type'] === 'asset' ? $target->id : null,
                        'target_type' => $data['target_type'], 'target_id' => $target->id, 'target_version' => $version,
                        'review_channel' => $data['channel'], 'rejection_scope' => $review->rejection_scope ?: $data['target_type'],
                        'rejection_opinion' => $review->opinion ?: '审核驳回，待整改。', 'reviewer_id' => $review->reviewer_id,
                        'reviewer_name' => $review->reviewer_name, 'status' => 'required',
                    ]
                );
            } else {
                $rectification = OperationTaskRectification::query()->where('source_review_id', $review->id)->first();
                if ($rectification && in_array($rectification->status, ['required', 'resubmitted'], true)) {
                    $rectification->update(['status' => $rectification->stop_confirmed_at ? 'restore_required' : 'cancelled']);
                }
            }
            $this->resolveResubmittedRectifications($task->fresh(['reviews', 'assets.reviews', 'rectifications']));
            $this->synchronizeTaskRectificationState($task->fresh());
        });

        $resultLabel = $data['result'] === 'approved' ? '通过' : '驳回并要求整改';
        $this->notifyUsers([$task->owner_user_id], $context['user'], __('operation_tasks.messages.task_review_title'), sprintf(__('operation_tasks.messages.task_review_result'), $task->task_no, $data['channel'] === 'leader' ? '组长审核' : '部门审核', $resultLabel));
        if ($data['result'] === 'approved' && $data['channel'] === 'leader') $this->notifyReviewers($task, __('operation_tasks.messages.leader_passed', ['number' => $task->task_no]), $context['user'], ['department']);
        return back()->with('success', __('operation_tasks.messages.review_saved'));
    }

    public function reviewAssets(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $this->validateTask($request, [
            'task_id' => ['required', 'integer'],
            'channel' => ['required', Rule::in(['leader', 'department'])],
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.result' => ['required', Rule::in(['approved', 'rejected'])],
            'assets.*.opinion' => ['nullable', 'string', 'max:5000'],
        ]);

        $task = OperationTestTask::query()->with('assets')->findOrFail((int) $data['task_id']);
        $allowed = $data['channel'] === 'leader'
            ? $this->canLeaderReviewTask($context['user'], $task)
            : $this->canDepartmentReviewTask($context['user'], $task);
        abort_unless($allowed, 403);

        $assets = $task->assets->keyBy('id');
        foreach ($data['assets'] as $assetId => $assetData) {
            $asset = $assets->get((int) $assetId);
            $this->rejectWhen(! ($asset), 'assets', __('operation_tasks.messages.wrong_asset'));
            if ($assetData['result'] === 'rejected' && trim((string) ($assetData['opinion'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "assets.$assetId.opinion" => '驳回素材必须填写具体整改意见。',
                ]);
            }
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($task, $data, $assets, $context): void {
            foreach ($data['assets'] as $assetId => $assetData) {
                /** @var OperationTaskAsset $asset */
                $asset = $assets->get((int) $assetId);
                $review = OperationTaskReview::query()->firstOrNew([
                    'target_type' => 'asset', 'target_id' => $asset->id, 'target_version' => (int) $asset->version, 'channel' => $data['channel'],
                ]);
                $review->fill([
                    'result' => $assetData['result'], 'opinion' => trim((string) ($assetData['opinion'] ?? '')) ?: null,
                    'rejection_scope' => $assetData['result'] === 'rejected' ? 'asset' : null,
                    'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
                ]);
                $review->save();
                OperationTaskReviewEvent::query()->create([
                    'review_id' => $review->id, 'target_type' => 'asset', 'target_id' => $asset->id, 'target_version' => (int) $asset->version,
                    'channel' => $data['channel'], 'result' => $review->result, 'opinion' => $review->opinion, 'rejection_scope' => $review->rejection_scope,
                    'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
                ]);
                if ($review->result === 'rejected') {
                    OperationTaskRectification::query()->updateOrCreate(['source_review_id' => $review->id], [
                        'task_id' => $task->id, 'asset_id' => $asset->id, 'target_type' => 'asset', 'target_id' => $asset->id,
                        'target_version' => (int) $asset->version, 'review_channel' => $data['channel'], 'rejection_scope' => 'asset',
                        'rejection_opinion' => $review->opinion ?: '素材审核驳回，待整改。', 'reviewer_id' => $review->reviewer_id,
                        'reviewer_name' => $review->reviewer_name, 'status' => 'required',
                    ]);
                }
            }
            $this->resolveResubmittedRectifications($task->fresh(['reviews', 'assets.reviews', 'rectifications']));
            $this->synchronizeTaskRectificationState($task->fresh());
        });

        $this->notifyUsers([$task->owner_user_id], $context['user'], __('operation_tasks.messages.asset_review_title'), sprintf(__('operation_tasks.messages.asset_review_result'), $task->task_no, $data['channel'] === 'leader' ? '组长' : '部门'));
        return back()->with('success', __('operation_tasks.messages.assets_review_saved'));
    }

    public function reviewBatch(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $this->validateTask($request, [
            'task_id' => ['required', 'integer'],
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

        $resultLabel = $data['task_result'] === 'approved' ? '通过' : '驳回并要求整改';
        $channelLabel = $data['channel'] === 'leader' ? '组长审核' : '部门审核';
        $this->notifyUsers([$task->owner_user_id], $context['user'], __('operation_tasks.messages.task_review_title'), sprintf(__('operation_tasks.messages.task_review_result'), $task->task_no, $channelLabel, $resultLabel));
        if ($data['task_result'] === 'approved' && $data['channel'] === 'leader') {
            $this->notifyReviewers($task, __('operation_tasks.messages.leader_passed', ['number' => $task->task_no]), $context['user'], ['department']);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => __('operation_tasks.messages.batch_review_saved')]);
        }
        return back()->with('success', __('operation_tasks.messages.batch_review_saved'));
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
                'rejection_opinion' => $review->opinion ?: '审核驳回，待整改。',
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

    public function resubmitRectification(Request $request, OperationTaskRectification $rectification, OperationTaskAssetUploadService $uploads): RedirectResponse
    {
        $context = $this->authorized($request);
        $rectification->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $rectification->task);
        if ($rectification->status !== 'required') return back()->withInput()->with('warning', __('operation_tasks.upload.edit_processed'));
        $minimum = $request->input('page_type') === 'category' ? 3 : 2;
        $data = $this->validateTask($request, [
            'task_version' => ['required', 'integer', 'min:1'],
            'page_type' => ['required', Rule::in(['single', 'category'])],
            'task_type' => ['required', Rule::in(['new', 'iteration'])],
            'landing_url' => ['required', 'url', 'max:2048'],
            'selection_objective' => ['required', 'string', 'max:10000'],
            'assets' => ['required', 'array', 'min:'.$minimum],
            'assets.*.id' => ['nullable', 'integer'],
            'assets.*.selling_point' => ['required', 'string', 'max:1000'],
            'assets.*.source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'assets.*.file' => ['prohibited'],
            'assets.*.upload_token' => ['nullable', 'uuid'],
            'assets.*.url' => ['nullable', 'url', 'max:2048'],
        ], [
            'task_version.required' => __('operation_tasks.upload.edit_refresh'),
            'task_version.integer' => __('operation_tasks.upload.edit_refresh'),
            'task_version.min' => __('operation_tasks.upload.edit_refresh'),
        ]);
        $user = $context['user'];
        $outcome = DB::connection($rectification->getConnectionName())->transaction(function () use ($rectification, $data, $uploads, $user): array {
            // Serialize re-submissions before consuming any upload or advancing versions.
            $task = OperationTestTask::query()->lockForUpdate()->findOrFail($rectification->task_id);
            $current = OperationTaskRectification::query()->lockForUpdate()->findOrFail($rectification->id);
            if ($current->status !== 'required') return ['status' => 'edit_processed'];
            if ((int) $task->version !== (int) $data['task_version']) return ['status' => 'edit_stale'];
            $task->load('assets');
            $activeAssets = $task->assets->whereNull('retired_at')->keyBy('id');
            $submittedIds = collect($data['assets'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
            if ($submittedIds->duplicates()->isNotEmpty() || $submittedIds->sort()->values()->all() !== $activeAssets->keys()->map(fn ($id) => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['assets' => __('operation_tasks.messages.retain_assets')]);
            }
            foreach ($data['assets'] as $index => $assetData) {
                $existingAsset = ! empty($assetData['id']) ? $activeAssets->get((int) $assetData['id']) : null;
                if (! ($existingAsset && $existingAsset->file_path) && empty($assetData['upload_token']) && blank($assetData['url'] ?? null)) {
                    throw ValidationException::withMessages(["assets.$index.upload_token" => __('operation_tasks.messages.retained_asset_required')]);
                }
            }
            $requiredRectifications = OperationTaskRectification::query()->where('task_id', $task->id)->where('status', 'required')->lockForUpdate()->get();
            $resetLeaderReview = $requiredRectifications->contains(fn (OperationTaskRectification $item) => $item->review_channel === 'leader');
            $previousTaskVersion = (int) $task->version;
            $history = $task->version_history ?? [];
            $history[] = [
                'version' => $previousTaskVersion,
                'reason' => 'rectification_resubmitted',
                'snapshot' => $task->only(['page_type', 'task_type', 'landing_url', 'selection_objective']),
                'changed_by' => $this->userName($user),
                'changed_at' => now()->toIso8601String(),
            ];
            $nextVersion = (int) $task->version + 1;
            $task->update([
                'page_type' => $data['page_type'], 'task_type' => $data['task_type'],
                'landing_url' => trim($data['landing_url']), 'selection_objective' => trim($data['selection_objective']),
                'version' => $nextVersion, 'version_history' => $history, 'rectification_status' => 'resubmitted',
                'submitted_at' => now(),
            ]);

            foreach ($data['assets'] as $index => $assetData) {
                $file = empty($assetData['upload_token']) ? [] : $uploads->consume((int) $user->id, $assetData['upload_token'], "assets.$index.upload_token");
                if (! empty($assetData['id'])) {
                    /** @var OperationTaskAsset $asset */
                    $asset = $activeAssets->get((int) $assetData['id']);
                    $previousAssetVersion = (int) $asset->version;
                    $assetHistory = $asset->version_history ?? [];
                    $assetHistory[] = [
                        'version' => $previousAssetVersion, 'event' => 'rectification_resubmitted',
                        'snapshot' => $asset->only(['selling_point', 'source_type', 'file_path', 'original_name', 'external_url', 'mime_type']),
                        'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String(),
                    ];
                    $assetValues = [
                        'selling_point' => trim($assetData['selling_point']), 'source_type' => $assetData['source_type'],
                        'external_url' => trim((string) ($assetData['url'] ?? '')) ?: null,
                        'ai_confirmed' => $assetData['source_type'] === 'ai_created',
                        'version' => $previousAssetVersion + 1, 'version_history' => $assetHistory,
                    ];
                    if ($file) {
                        $assetValues['file_path'] = $file['file_path'];
                        $assetValues['original_name'] = $file['original_name'];
                        $assetValues['mime_type'] = $file['mime_type'];
                    }
                    $asset->update($assetValues);

                    if (! $resetLeaderReview) {
                        $leaderReview = OperationTaskReview::query()->where([
                            'target_type' => 'asset', 'target_id' => $asset->id,
                            'target_version' => $previousAssetVersion, 'channel' => 'leader', 'result' => 'approved',
                        ])->first();
                        if ($leaderReview) {
                            OperationTaskReview::query()->create([
                                'target_type' => 'asset', 'target_id' => $asset->id, 'target_version' => $previousAssetVersion + 1,
                                'channel' => 'leader', 'result' => 'approved', 'opinion' => $leaderReview->opinion,
                                'reviewer_id' => $leaderReview->reviewer_id, 'reviewer_name' => $leaderReview->reviewer_name,
                                'reviewed_at' => $leaderReview->reviewed_at,
                            ]);
                        }
                    }
                } else {
                    OperationTaskAsset::query()->create([
                        'task_id' => $task->id, 'uploader_user_id' => $user->id, 'uploader_name' => $this->userName($user),
                        'selling_point' => trim($assetData['selling_point']), 'source_type' => $assetData['source_type'],
                        'file_path' => $file['file_path'] ?? null,
                        'original_name' => $file['original_name'] ?? null, 'mime_type' => $file['mime_type'] ?? null,
                        'external_url' => trim((string) ($assetData['url'] ?? '')) ?: null,
                        'ai_confirmed' => $assetData['source_type'] === 'ai_created',
                        'version_history' => [['version' => 1, 'event' => 'added_during_rectification', 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
                    ]);
                }
            }

            if (! $resetLeaderReview) {
                $leaderReview = OperationTaskReview::query()->where([
                    'target_type' => 'task', 'target_id' => $task->id,
                    'target_version' => $previousTaskVersion, 'channel' => 'leader', 'result' => 'approved',
                ])->first();
                if ($leaderReview) {
                    OperationTaskReview::query()->create([
                        'target_type' => 'task', 'target_id' => $task->id, 'target_version' => $nextVersion,
                        'channel' => 'leader', 'result' => 'approved', 'opinion' => $leaderReview->opinion,
                        'reviewer_id' => $leaderReview->reviewer_id, 'reviewer_name' => $leaderReview->reviewer_name,
                        'reviewed_at' => $leaderReview->reviewed_at,
                    ]);
                }
            }

            foreach ($requiredRectifications as $requiredRectification) {
                $requiredRectification->update([
                    'status' => 'resubmitted', 'rectification_content' => __('operation_tasks.upload.rectification_content'),
                    'resubmitted_at' => now(), 'resubmitted_by' => $user->id, 'resubmitted_by_name' => $this->userName($user),
                    'resubmitted_task_version' => $nextVersion,
                    'resubmitted_asset_id' => $requiredRectification->asset_id,
                ]);
            }
            OperationTaskReviewEvent::query()->create([
                'review_id' => null, 'target_type' => 'task', 'target_id' => $task->id, 'target_version' => $nextVersion,
                'channel' => 'operation', 'result' => 'resubmitted', 'opinion' => null, 'rejection_scope' => null,
                'reviewer_id' => $user->id, 'reviewer_name' => $this->userName($user), 'reviewed_at' => now(),
            ]);
            return ['status' => 'saved', 'reset_leader' => $resetLeaderReview];
        });
        if ($outcome['status'] !== 'saved') return back()->withInput()->with('warning', __('operation_tasks.upload.'.$outcome['status']));
        $resetLeaderReview = $outcome['reset_leader'];
        $reviewChannels = $resetLeaderReview ? ['leader'] : ['department'];
        $this->notifyReviewers($rectification->task, __('operation_tasks.messages.resubmitted', ['number' => $rectification->task->task_no]), $user, $reviewChannels);
        return back()->with('success', $resetLeaderReview ? __('operation_tasks.messages.resubmit_leader') : __('operation_tasks.messages.resubmit_department'));
    }

    public function restoreRectification(Request $request, OperationTaskRectification $rectification): RedirectResponse
    {
        $context = $this->authorized($request);
        $rectification->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $rectification->task);
        $this->rejectWhen(! (in_array($rectification->status, ['resubmitted', 'completed', 'restore_required'], true)), 'restore_confirmed', __('operation_tasks.messages.restore_unavailable'));
        $data = $this->validateTask($request, ['restore_confirmed' => ['accepted'], 'restored_media' => ['nullable', 'string', 'max:100'], 'restored_ad_identifier' => ['nullable', 'string', 'max:191'], 'restored_notes' => ['nullable', 'string', 'max:5000']]);
        $rectification->update([
            'status' => 'restored', 'restored_at' => now(), 'restored_by' => $context['user']->id, 'restored_by_name' => $this->userName($context['user']),
            'restored_media' => trim((string) ($data['restored_media'] ?? '')) ?: null, 'restored_ad_identifier' => trim((string) ($data['restored_ad_identifier'] ?? '')) ?: null,
            'restored_notes' => trim((string) ($data['restored_notes'] ?? '')) ?: null,
        ]);
        $this->synchronizeTaskRectificationState($rectification->task->fresh());
        return back()->with('success', __('operation_tasks.messages.restore_saved'));
    }

    public function dashboard(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->canManage($context['user']), 403);
        $today = now('Asia/Shanghai')->startOfDay();
        $from = $request->date('from')?->format('Y-m-d') ?? $today->copy()->subDays(6)->toDateString();
        $to = $request->date('to')?->format('Y-m-d') ?? $today->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];

        $allTeams = $this->visibleSecondaryDepartments($context['user'], $context['portal']);
        $selectedTeamId = (int) $request->input('department', 0);
        if ($selectedTeamId && ! $allTeams->contains('id', $selectedTeamId)) $selectedTeamId = 0;
        $teams = $selectedTeamId ? $allTeams->where('id', $selectedTeamId)->values() : $allTeams;
        $filters = ['department' => $selectedTeamId ?: '', 'owner' => trim((string) $request->input('person_search', $request->input('owner', '')))];
        if ($request->filled('team_search')) $teams = $teams->filter(fn ($team) => str_contains(mb_strtolower($team->name), mb_strtolower(trim((string) $request->input('team_search')))));
        $teamIds = $teams->modelKeys();
        $memberIds = $teams->flatMap(fn (Department $team) => $team->departmentMembers->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $rangeStart = Carbon::parse($from, 'Asia/Shanghai')->startOfDay()->utc();
        $rangeEnd = Carbon::parse($to, 'Asia/Shanghai')->endOfDay()->utc();
        $recaps = OperationTaskRecap::query()->whereBetween('data_date', [$from, $to])
            ->where(function ($query) use ($teamIds, $memberIds): void {
                $query->whereIn('department_id', $teamIds)->orWhereIn('user_id', $memberIds);
            })->get();
        $tasks = OperationTestTask::query()->with(['assets' => fn ($query) => $query->whereBetween('created_at', [$rangeStart, $rangeEnd])])
            ->where(function ($query) use ($teamIds, $memberIds): void {
                $query->whereIn('department_id', $teamIds)->orWhereIn('owner_user_id', $memberIds);
            })
            ->where(function ($query) use ($rangeStart, $rangeEnd): void {
                $query->whereBetween('submitted_at', [$rangeStart, $rangeEnd])
                    ->orWhereHas('assets', fn ($assets) => $assets->whereBetween('created_at', [$rangeStart, $rangeEnd]));
            })->get();
        $plans = OperationTaskDailyPlan::query()->whereBetween('plan_date', [$from, $to])
            ->where(function ($query) use ($teamIds, $memberIds): void {
                $query->whereIn('department_id', $teamIds)->orWhereIn('user_id', $memberIds);
            })->get()
            ->keyBy(fn (OperationTaskDailyPlan $plan) => $plan->user_id.'|'.$plan->plan_date->format('Y-m-d'));
        $days = collect();
        for ($day = Carbon::parse($from, 'Asia/Shanghai'); $day->lte(Carbon::parse($to, 'Asia/Shanghai')); $day->addDay()) $days->push($day->toDateString());

        if ($filters['owner'] !== '') {
            $selectedUsers = AdminUser::query()->whereIn('id', $memberIds)->get()->filter(fn ($member) => str_contains(mb_strtolower($this->userName($member)), mb_strtolower($filters['owner'])))->pluck('id');
            $recaps = $recaps->whereIn('user_id', $selectedUsers);
            $tasks = $tasks->whereIn('owner_user_id', $selectedUsers);
        }
        $teamRows = $teams->map(function (Department $team) use ($recaps, $tasks, $plans, $days, $filters) {
            $members = collect($team->departmentMembers->all())->filter(fn (AdminUser $member) => $filters['owner'] === '' || str_contains(mb_strtolower($this->userName($member)), mb_strtolower($filters['owner'])));
            $rows = $members->map(function (AdminUser $member) use ($recaps, $tasks, $plans, $days) {
                $memberRecaps = $recaps->where('user_id', $member->id);
                $memberTasks = $tasks->where('owner_user_id', $member->id);
                $progresses = $days->map(function (string $date) use ($member, $memberTasks, $plans) {
                    $plan = $plans->get($member->id.'|'.$date);
                    return $this->dailyPlanProgress($plan, $memberTasks, $date);
                });
                $recapByDate = $memberRecaps->keyBy(fn (OperationTaskRecap $recap) => $recap->data_date->format('Y-m-d'));
                $recapRecords = $memberRecaps->sortByDesc('data_date')->map(fn (OperationTaskRecap $recap) => $this->recapPayload($recap))->values()->all();
                $recapDayRecords = $days->sortDesc()->map(function (string $date) use ($recapByDate): array {
                    $recap = $recapByDate->get($date);
                    return $recap ? $this->recapPayload($recap) : ['date' => $date, 'missing' => true];
                })->values()->all();
                $taskRecords = $memberTasks->sortByDesc('submitted_at')->map(fn (OperationTestTask $task) => [
                    'id' => $task->id, 'task_no' => $task->task_no, 'objective' => $task->selection_objective,
                    'page_type' => $task->page_type, 'submitted_at' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->submitted_at),
                    'asset_count' => $task->assets->count(), 'url' => route('operation-tasks.tests.show', $task),
                ])->values()->all();
                return (object) [
                    'id' => (int) $member->id, 'name' => $this->userName($member), 'position' => $this->departmentPositionLabel($member->pivot?->position),
                    'recap_days' => $memberRecaps->map(fn (OperationTaskRecap $recap) => $recap->data_date->format('Y-m-d'))->unique()->count(), 'expected_days' => $days->count(),
                    'revenue' => (float) $memberRecaps->sum('total_revenue_usd'), 'spend' => (float) $memberRecaps->sum('total_spend_usd'), 'orders' => (int) $memberRecaps->sum('total_orders'),
                    'single_count' => $memberTasks->where('page_type', 'single')->count(), 'single_assets' => (int) $memberTasks->where('page_type', 'single')->sum(fn ($task) => $task->assets->count()),
                    'category_count' => $memberTasks->where('page_type', 'category')->count(), 'category_assets' => (int) $memberTasks->where('page_type', 'category')->sum(fn ($task) => $task->assets->count()),
                    'planned_days' => $progresses->where('has_plan', true)->count(), 'completed_days' => $progresses->where('complete', true)->count(),
                    'missing_plan_days' => $progresses->where('has_plan', false)->count(), 'daily_progress' => $progresses->values()->all(),
                    'task_records' => $taskRecords, 'recap_records' => $recapRecords, 'recap_day_records' => $recapDayRecords,
                ];
            })->values();
            return (object) ['id' => (int) $team->id, 'name' => $team->name, 'parent_name' => $team->parent?->name, 'members' => $rows, 'member_count' => $rows->count()];
        })->values();
        $operators = $teamRows->flatMap(fn ($team) => $team->members)->pluck('name')->unique()->sort()->values();
        $visibleMemberCount = $teamRows->sum(fn ($team) => $team->member_count);
        return view('portals.operation-tasks.dashboard', [
            ...$context, 'from' => $from, 'to' => $to, 'recaps' => $recaps, 'tasks' => $tasks, 'teamRows' => $teamRows,
            'searchTeamOptions' => $allTeams->pluck('name'), 'searchPersonOptions' => $allTeams->flatMap(fn ($team) => $team->departmentMembers)->map(fn ($member) => $this->userName($member))->unique(), 'operators' => $operators, 'teams' => $allTeams, 'visibleMemberCount' => $visibleMemberCount,
            'dashboardFilters' => $filters, 'canManagePlans' => true,
        ]);
    }

    private function rejectWhen(mixed $condition, string $field, string $message): void
    {
        if ($condition) throw ValidationException::withMessages([$field => $message]);
    }

    private function completedSubmission(string $token, int $userId): ?RedirectResponse
    {
        $task = OperationTestTask::query()->where('submission_token', $token)->first();
        if (! $task) return null;
        if ((int) $task->owner_user_id !== $userId) {
            throw ValidationException::withMessages(['submission_token' => __('operation_tasks.validation.refresh_submission')]);
        }
        return redirect()->route('operation-tasks.tests.show', $task)
            ->with('warning', __('operation_tasks.messages.already_submitted', ['number' => $task->task_no]));
    }

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

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        app()->setLocale('zh-CN');
        return $context;
    }

    private function authorized(Request $request): array
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        return $context;
    }

    private function userName(AdminUser $user): string
    {
        return (string) ($user->name ?: $user->username ?: ('用户#'.$user->id));
    }

    private function department(AdminUser $user): ?Department
    {
        return $user->departments()->wherePivot('is_primary', true)->first() ?: $user->departments()->first();
    }

    private function departmentForPortal(AdminUser $user, Portal $portal): ?Department
    {
        $scopeIds = $this->portalDepartmentScopeIds($portal);

        return $user->departments()
            ->whereIn('admin_roles.id', $scopeIds)
            ->whereNotNull('admin_roles.parent_id')
            ->orderByPivot('is_primary', 'desc')
            ->first()
            ?: $user->departments()->whereIn('admin_roles.id', $scopeIds)->orderByPivot('is_primary', 'desc')->first()
            ?: $this->department($user);
    }

    private function canManage(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->departments->managedDepartmentIds($user)->isNotEmpty();
    }

    private function canReview(AdminUser $user): bool
    {
        return $this->reviewChannels($user) !== [];
    }

    private function visibleDepartmentIds(AdminUser $user): array
    {
        return $user->isAdministrator() ? [] : $this->departments->managedDepartmentIds($user)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function visibleSecondaryDepartments(AdminUser $user, Portal $portal): Collection
    {
        $query = Department::business()->whereNotNull('parent_id')->with(['parent:id,name', 'departmentMembers:id,name,username'])->orderBy('sort')->orderBy('name');
        $query->whereIn('id', $this->portalDepartmentScopeIds($portal));
        if (! $user->isAdministrator()) $query->whereIn('id', $this->visibleDepartmentIds($user));
        return $query->get();
    }

    private function portalDepartmentScopeIds(Portal $portal): array
    {
        $grants = $portal->departmentGrants()->get(['department_id', 'includes_descendants']);
        if ($grants->isEmpty()) return [];
        $departments = Department::business()->get(['id', 'parent_id']);
        $children = $departments->groupBy(fn (Department $department) => (int) ($department->parent_id ?: 0));
        $ids = collect();
        $append = function (int $parentId) use (&$append, $children, $ids): void {
            foreach ($children->get($parentId, collect()) as $child) { $ids->push((int) $child->id); $append((int) $child->id); }
        };
        foreach ($grants as $grant) { $ids->push((int) $grant->department_id); if ($grant->includes_descendants) $append((int) $grant->department_id); }
        return $ids->unique()->values()->all();
    }

    private function departmentPositionLabel(?string $position): string
    {
        return match ($position) {
            Department::POSITION_MANAGER => '二级部门主管', Department::POSITION_DEPUTY => '二级部门副主管', default => '成员',
        };
    }

    private function leaderDepartmentIds(AdminUser $user): array
    {
        return $user->departments()->whereNotNull('parent_id')->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function rootManagerDepartmentIds(AdminUser $user): array
    {
        return $user->departments()->whereNull('parent_id')->wherePivot('position', Department::POSITION_MANAGER)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function reviewChannels(AdminUser $user): array
    {
        if ($this->leaderDepartmentIds($user) !== []) return ['leader' => '组长审核'];
        if ($user->isAdministrator() || $this->rootManagerDepartmentIds($user) !== []) return ['department' => '部门审核'];
        return [];
    }

    private function canViewTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || (int) $task->owner_user_id === (int) $user->id || in_array((int) $task->department_id, $this->visibleDepartmentIds($user), true);
    }

    private function recapReviewChannel(AdminUser $user, OperationTaskRecap $recap): ?string
    {
        if ($user->isAdministrator()) return 'department';
        if ($this->canLeaderReviewRecap($user, $recap)) return 'leader';
        return $this->canDepartmentReviewRecap($user, $recap) ? 'department' : null;
    }

    private function canLeaderReviewRecap(AdminUser $user, OperationTaskRecap $recap): bool
    {
        return ! $user->isAdministrator() && in_array((int) $recap->department_id, $this->leaderDepartmentIds($user), true);
    }

    private function canDepartmentReviewRecap(AdminUser $user, OperationTaskRecap $recap): bool
    {
        return $user->isAdministrator() || in_array((int) $recap->department_id, $this->departmentReviewScopeIds($user), true);
    }

    private function canEditTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || (int) $task->owner_user_id === (int) $user->id;
    }

    private function canLeaderReviewTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || in_array((int) $task->department_id, $this->leaderDepartmentIds($user), true);
    }

    private function canDepartmentReviewTask(AdminUser $user, OperationTestTask $task): bool
    {
        return $user->isAdministrator() || in_array((int) $task->department_id, $this->departmentReviewScopeIds($user), true);
    }

    private function departmentReviewScopeIds(AdminUser $user): array
    {
        $rootIds = $this->rootManagerDepartmentIds($user);
        if ($rootIds === []) return [];
        $departments = Department::business()->get(['id', 'parent_id'])->keyBy('id');
        return $departments->filter(function (Department $department) use ($departments, $rootIds): bool {
            $current = $department; $visited = [];
            while ($current && ! in_array((int) $current->id, $visited, true)) {
                if (in_array((int) $current->id, $rootIds, true)) return true;
                $visited[] = (int) $current->id;
                $current = $current->parent_id ? $departments->get((int) $current->parent_id) : null;
            }
            return false;
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function authorizeTask(AdminUser $user, OperationTestTask $task): void
    {
        abort_unless($this->canViewTask($user, $task), 403);
    }

    private function authorizeTaskOwner(AdminUser $user, OperationTestTask $task): void
    {
        abort_unless($this->canEditTask($user, $task), 403);
    }

    private function taskDoubleApproved(OperationTestTask $task): bool
    {
        $reviews = $task->reviews->where('target_version', $task->version);
        return $reviews->where('result', 'approved')->pluck('channel')->unique()->intersect(['leader', 'department'])->count() === 2;
    }

    private function dailyPlanProgress(?OperationTaskDailyPlan $plan, Collection $memberTasks, string $date): array
    {
        if (! $plan) return ['date' => $date, 'has_plan' => false, 'complete' => false, 'label' => '未设置计划', 'single_target' => 0, 'category_target' => 0, 'single_done' => 0, 'category_done' => 0];
        $dayTasks = $memberTasks->filter(function (OperationTestTask $task) use ($date): bool {
            $submitted = optional($task->submitted_at)->timezone('Asia/Shanghai')->toDateString() === $date;
            $hasNewAsset = $task->assets->contains(fn (OperationTaskAsset $asset) => optional($asset->created_at)->timezone('Asia/Shanghai')->toDateString() === $date);
            return $submitted || $hasNewAsset;
        });
        $qualified = function (string $pageType, int $minimum) use ($dayTasks, $date): int {
            return $dayTasks->where('page_type', $pageType)->filter(fn (OperationTestTask $task) => $task->assets->filter(fn (OperationTaskAsset $asset) => optional($asset->created_at)->timezone('Asia/Shanghai')->toDateString() === $date)->count() >= $minimum)->count();
        };
        $singleDone = $qualified('single', 2); $categoryDone = $qualified('category', 3);
        $complete = $singleDone >= (int) $plan->single_target && $categoryDone >= (int) $plan->category_target;
        return ['date' => $date, 'has_plan' => true, 'complete' => $complete, 'label' => $plan->plan_mode === 'single' ? '单品' : ($plan->plan_mode === 'category' ? '类目' : '混合'), 'single_target' => (int) $plan->single_target, 'category_target' => (int) $plan->category_target, 'single_done' => $singleDone, 'category_done' => $categoryDone, 'note' => $plan->plan_note];
    }

    private function recapPayload(OperationTaskRecap $recap): array
    {
        $answers = collect($recap->question_answers ?? [])->map(fn (array $answer) => ['title' => (string) ($answer['title'] ?? '复盘问题'), 'answer' => (string) ($answer['answer'] ?? '—')])->values()->all();
        if ($answers === []) $answers = collect([
            ['title' => '重要发现与判断', 'answer' => $recap->finding_answer], ['title' => '今日重点推进', 'answer' => $recap->action_answer],
            ['title' => '广告计划', 'answer' => $recap->advertising_plan], ['title' => '未完成事项与支持', 'answer' => $recap->support_needed],
        ])->filter(fn (array $item) => filled($item['answer']))->values()->all();
        return [
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

    private function notifyReviewers(OperationTestTask $task, string $content, AdminUser $sender, ?array $channels = null): void
    {
        $this->notifyUsers(array_values(array_diff($this->reviewerUserIds($task, $channels), [(int) $sender->id])), $sender, __('operation_tasks.messages.pending_title'), $content);
    }

    private function recapReviewerUserIds(OperationTaskRecap $recap, ?array $channels = null): array
    {
        $channels ??= ['leader', 'department'];
        $ids = collect();
        if (! $recap->department_id) return [];
        if (in_array('leader', $channels, true)) {
            $team = Department::query()->with('departmentMembers')->find($recap->department_id);
            if ($team) $ids = $ids->merge($team->departmentMembers
                ->filter(fn (AdminUser $member) => in_array($member->pivot?->position, [Department::POSITION_MANAGER, Department::POSITION_DEPUTY], true))
                ->pluck('id'));
        }
        if (in_array('department', $channels, true)) {
            $team = Department::query()->with(['parent.departmentMembers'])->find($recap->department_id);
            if ($team?->parent) $ids = $ids->merge($team->parent->departmentMembers
                ->filter(fn (AdminUser $member) => $member->pivot?->position === Department::POSITION_MANAGER)
                ->pluck('id'));
        }
        return $ids->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function notifyRecapReviewers(OperationTaskRecap $recap, string $content, AdminUser $sender, ?array $channels = null): void
    {
        $this->notifyUsers(array_values(array_diff($this->recapReviewerUserIds($recap, $channels), [(int) $sender->id])), $sender, __('operation_tasks.recap_review.pending_title'), $content);
    }

    private function notifyUsers(array $recipientIds, AdminUser $sender, string $title, string $content): void
    {
        $recipientIds = collect($recipientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($recipientIds->isEmpty()) return;
        $message = InternalMessage::query()->create([
            'title' => $title, 'content' => $content, 'sender_user_id' => $sender->id, 'sender_name' => $this->userName($sender),
            'sender_portal_code' => self::PORTAL_CODE, 'sender_portal_name' => '服装运营系统', 'published_at' => now(),
        ]);
        $now = now();
        InternalMessageRecipient::query()->insert($recipientIds->map(fn (int $id) => ['internal_message_id' => $message->id, 'recipient_user_id' => $id, 'created_at' => $now, 'updated_at' => $now])->all());
    }
}
