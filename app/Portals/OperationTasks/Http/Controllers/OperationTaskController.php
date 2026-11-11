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
use App\Models\OperationTaskDeliveryRound;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskRecapQuestion;
use App\Models\OperationTaskRectification;
use App\Models\OperationTaskReview;
use App\Models\OperationTaskReviewEvent;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
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

        $editingRecap = $yesterdayRecap && ($yesterdayRecap->workflow_status ?? 'approved') === 'rejected' ? $yesterdayRecap : null;
        if ($request->filled('edit')) {
            $candidate = OperationTaskRecap::query()->find((int) $request->input('edit'));
            if ($candidate && (int) $candidate->user_id === (int) $user->id && ($candidate->workflow_status ?? 'approved') === 'rejected') $editingRecap = $candidate;
        }

        $recaps = $query->paginate(20)->withQueryString();
        $recaps->getCollection()->each(function (OperationTaskRecap $recap) use ($user): void {
            $recap->setAttribute('can_leader_review', $this->canLeaderReviewRecap($user, $recap));
            $recap->setAttribute('can_department_review', $this->canDepartmentReviewRecap($user, $recap));
        });

        return view('portals.operation-tasks.recaps', [
            ...$context,
            'recaps' => $recaps,
            'recapQuestions' => OperationTaskRecapQuestion::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')->get(),
            'allRecapQuestions' => $user->isAdministrator() ? OperationTaskRecapQuestion::query()->orderBy('sort')->orderBy('id')->get() : collect(),
            'canManageRecapQuestions' => $user->isAdministrator(),
            'mediaOptions' => self::MEDIA,
            'yesterday' => $yesterday,
            'yesterdayRecap' => $yesterdayRecap,
            'editingRecap' => $editingRecap,
            'openRecapModal' => $request->boolean('new') || ($request->filled('edit') && $editingRecap),
        ]);
    }

    public function storeRecap(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $user = $context['user'];
        $data = $request->validate([
            'data_date' => ['required', 'date', 'before_or_equal:'.now('Asia/Shanghai')->toDateString()],
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
            abort_if(trim((string) ($data['answers'][$question->id] ?? '')) === '', 422, '请填写：'.$question->title);
        }

        $items = collect($data['media'])->map(function (array $row): array {
            $name = $row['name'] === '其他' ? trim((string) ($row['custom_name'] ?? '')) : $row['name'];
            abort_if($name === '', 422, '选择“其他”时必须填写媒体名称。');
            return [
                'name' => $name,
                'spend' => (float) $row['spend'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'roi' => (float) $row['spend'] > 0 ? (float) $row['revenue'] / (float) $row['spend'] : null,
            ];
        });
        abort_if($items->pluck('name')->map(fn ($value) => mb_strtolower(trim($value)))->duplicates()->isNotEmpty(), 422, '同一份复盘中不能重复添加相同媒体。');

        $answerFor = fn (string $code): string => trim((string) ($data['answers'][$questions->firstWhere('code', $code)?->id] ?? ''));
        $department = $this->departmentForPortal($user, $context['portal']);
        $questionAnswers = $questions->map(fn (OperationTaskRecapQuestion $question): array => [
            'question_id' => $question->id,
            'title' => $question->title,
            'description' => $question->description,
            'is_required' => $question->is_required,
            'answer' => trim((string) ($data['answers'][$question->id] ?? '')),
        ])->values()->all();
        $payload = [
            'data_date' => $data['data_date'],
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

        $existing = OperationTaskRecap::query()->where('user_id', $user->id)->whereDate('data_date', $data['data_date'])->first();
        if ($existing && ($existing->workflow_status ?? 'approved') !== 'rejected') {
            return back()->with('warning', '该复盘已提交审核，只有被驳回后才可以修改并重新提交。');
        }

        $recap = null;
        if ($existing) {
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
            $recap = $existing->fresh();
        } else {
            $recap = OperationTaskRecap::query()->create([...$payload,
                'version' => 1, 'workflow_status' => 'pending_leader', 'leader_review_status' => 'pending', 'department_review_status' => 'pending',
                'submitted_at' => now(),
            ]);
        }

        OperationTaskReviewEvent::query()->create([
            'review_id' => null,
            'target_type' => 'recap',
            'target_id' => $recap->id,
            'target_version' => $recap->version,
            'channel' => 'operation',
            'result' => $existing ? 'resubmitted' : 'submitted',
            'opinion' => null,
            'rejection_scope' => null,
            'reviewer_id' => $user->id,
            'reviewer_name' => $this->userName($user),
            'reviewed_at' => now(),
        ]);

        $this->notifyRecapReviewers($recap, sprintf('%s 已提交 %s 的每日复盘，待二级主管审核。', $this->userName($user), $recap->data_date->format('Y-m-d')), $user, ['leader']);

        return back()->with('success', $existing ? '复盘已更新，旧版本已保留。' : '昨日复盘已保存。');
    }

    public function reviewRecap(Request $request, OperationTaskRecap $recap): RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['leader', 'department'])],
            'result' => ['required', Rule::in(['approved', 'rejected'])],
            'opinion' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['result'] === 'rejected' && trim((string) ($data['opinion'] ?? '')) === '') {
            return back()->withInput()->withErrors(['opinion' => '驳回必须填写具体修改意见。']);
        }
        if ($data['result'] === 'rejected' && trim((string) ($data['opinion'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'opinion' => '驳回必须填写具体修改意见。',
            ]);
        }

        $expectedStatus = $data['channel'] === 'leader' ? 'pending_leader' : 'pending_department';
        abort_unless(($recap->workflow_status ?? 'approved') === $expectedStatus, 422, '该复盘当前不在此审核环节。');
        $allowed = $data['channel'] === 'leader'
            ? $this->canLeaderReviewRecap($context['user'], $recap)
            : $this->canDepartmentReviewRecap($context['user'], $recap);
        abort_unless($allowed, 403);

        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($recap, $data, $context): void {
            $review = OperationTaskReview::query()->firstOrNew([
                'target_type' => 'recap', 'target_id' => $recap->id, 'target_version' => $recap->version, 'channel' => $data['channel'],
            ]);
            $review->fill([
                'result' => $data['result'], 'opinion' => trim((string) ($data['opinion'] ?? '')) ?: null,
                'rejection_scope' => $data['result'] === 'rejected' ? 'recap' : null,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);
            $review->save();
            OperationTaskReviewEvent::query()->create([
                'review_id' => $review->id, 'target_type' => 'recap', 'target_id' => $recap->id, 'target_version' => $recap->version,
                'channel' => $data['channel'], 'result' => $data['result'], 'opinion' => $review->opinion, 'rejection_scope' => $review->rejection_scope,
                'reviewer_id' => $context['user']->id, 'reviewer_name' => $this->userName($context['user']), 'reviewed_at' => now(),
            ]);

            if ($data['result'] === 'rejected') {
                $recap->update([
                    'workflow_status' => 'rejected', $data['channel'].'_review_status' => 'rejected',
                    'last_rejection_channel' => $data['channel'], 'last_rejection_opinion' => $review->opinion, 'last_rejected_at' => now(),
                ]);
            } elseif ($data['channel'] === 'leader') {
                $recap->update(['leader_review_status' => 'approved', 'workflow_status' => 'pending_department']);
            } else {
                $recap->update(['department_review_status' => 'approved', 'workflow_status' => 'approved']);
            }
        });

        $recap = $recap->fresh();
        if ($data['result'] === 'rejected') {
            $this->notifyUsers([$recap->user_id], $context['user'], '每日复盘被驳回', sprintf('%s 的 %s 每日复盘被%s驳回：%s', $recap->data_date->format('Y-m-d'), $recap->user_name, $data['channel'] === 'leader' ? '二级主管' : '一级主管', trim((string) $data['opinion'])),);
        } elseif ($data['channel'] === 'leader') {
            $this->notifyRecapReviewers($recap, sprintf('%s 的 %s 每日复盘已通过二级主管审核，待一级主管审核。', $recap->user_name, $recap->data_date->format('Y-m-d')), $context['user'], ['department']);
        } else {
            $this->notifyUsers([$recap->user_id], $context['user'], '每日复盘审核通过', sprintf('%s 的 %s 每日复盘已完成全部审核。', $recap->data_date->format('Y-m-d'), $recap->user_name));
        }

        return back()->with('success', '复盘审核结果已保存。');
    }

    public function saveRecapQuestions(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        abort_unless($context['user']->isAdministrator(), 403);
        $data = $request->validate([
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

        return back()->with('success', '复盘问题模板已保存；历史复盘仍保留提交当时的问题与答案。');
    }

    public function tests(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        $base = OperationTestTask::query()
            ->withCount('assets')
            ->with(['reviews', 'assets.reviews', 'assets.dailyRecords', 'assets.deliveryRounds'])
            ->latest('submitted_at');
        if (! $this->canManage($user)) {
            $base->where('owner_user_id', $user->id);
        } elseif (! $user->isAdministrator()) {
            $base->where(function ($query) use ($user): void {
                $query->whereIn('department_id', $this->visibleDepartmentIds($user))->orWhere('owner_user_id', $user->id);
            });
        }

        $today = now('Asia/Shanghai')->startOfDay();
        $todayEnd = $today->copy()->endOfDay();
        $todayTasks = (clone $base)->where(function ($query) use ($today, $todayEnd): void {
            $query->whereBetween('submitted_at', [$today, $todayEnd])
                ->orWhereHas('assets', fn ($assets) => $assets->whereBetween('created_at', [$today, $todayEnd]));
        })->get();
        $todayPlan = OperationTaskDailyPlan::query()->where('user_id', $user->id)->whereDate('plan_date', $today)->first();
        $todayProgress = $this->dailyPlanProgress($todayPlan, $todayTasks, $today->toDateString());

        return view('portals.operation-tasks.tests', [
            ...$context,
            'tasks' => (clone $base)->paginate(18)->withQueryString(),
            'today' => $today->toDateString(),
            'todaySingle' => $todayTasks->where('page_type', 'single')->count(),
            'todayCategory' => $todayTasks->where('page_type', 'category')->count(),
            'todayAssets' => (int) $todayTasks->sum(fn (OperationTestTask $task) => $task->assets
                ->filter(fn (OperationTaskAsset $asset) => optional($asset->created_at)->timezone('Asia/Shanghai')->isSameDay($today))
                ->count()),
            'todayPassed' => $todayTasks->filter(fn (OperationTestTask $task) => $this->taskDoubleApproved($task))->count(),
            'todayPlan' => $todayPlan,
            'todayProgress' => $todayProgress,
        ]);
    }

    public function storeTest(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $user = $context['user'];
        $pageType = (string) $request->input('page_type');
        $minimum = $pageType === 'category' ? 3 : 2;
        $data = $request->validate([
            'page_type' => ['required', Rule::in(['single', 'category'])],
            'task_type' => ['required', Rule::in(['new', 'iteration'])],
            'landing_url' => ['required', 'url', 'max:2048'],
            'selection_objective' => ['required', 'string', 'max:10000'],
            'assets' => ['required', 'array', 'min:'.$minimum],
            'assets.*.selling_point' => ['required', 'string', 'max:1000'],
            'assets.*.source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'assets.*.file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'assets.*.url' => ['nullable', 'url', 'max:2048'],
        ]);
        foreach ($data['assets'] as $index => $asset) {
            if (! $request->hasFile("assets.$index.file") && empty($asset['url'])) {
                throw ValidationException::withMessages([
                    "assets.$index.file" => '每条素材必须上传文件或填写素材链接。',
                ]);
            }
        }
        $existing = OperationTestTask::query()->where('owner_user_id', $user->id)->where('landing_url', $data['landing_url'])->first();
        if ($existing) {
            return redirect()->route('operation-tasks.tests.show', $existing)
                ->with('warning', '该落地页已有任务档案，请在原任务内追加素材或创建素材新版本。');
        }

        $department = $this->departmentForPortal($user, $context['portal']);
        $connection = config('admin.database.connection') ?: config('database.default');
        $task = DB::connection($connection)->transaction(function () use ($data, $request, $user, $department): OperationTestTask {
            $task = OperationTestTask::query()->create([
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
                $file = $request->file("assets.$index.file");
                OperationTaskAsset::query()->create([
                    'task_id' => $task->id,
                    'uploader_user_id' => $user->id,
                    'uploader_name' => $this->userName($user),
                    'selling_point' => trim($asset['selling_point']),
                    'source_type' => $asset['source_type'],
                    'file_path' => $file?->store('operation-tasks/assets/'.now()->format('Y/m'), 'local'),
                    'original_name' => $file?->getClientOriginalName(),
                    'external_url' => trim((string) ($asset['url'] ?? '')) ?: null,
                    'mime_type' => $file?->getMimeType(),
                    'ai_confirmed' => $asset['source_type'] === 'ai_created',
                    'version_history' => [['version' => 1, 'event' => 'created', 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
                ]);
            }
            return $task;
        });
        $this->notifyReviewers($task, '有新的测品任务待审核，请在审核中心处理。', $user);

        return redirect()->route('operation-tasks.tests.show', $task)->with('success', '测品任务已提交；待审核期间仍可人工登记投放与每日数据。');
    }

    public function showTest(Request $request, OperationTestTask $task): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $this->authorizeTask($context['user'], $task);
        $task->load([
            'assets.dailyRecords', 'assets.reviews', 'assets.deliveryRounds', 'reviews',
            'rectifications.asset', 'rectifications.sourceReview', 'rectifications.resubmittedAsset', 'deliveryRounds',
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
        abort_if($asset->retired_at, 422, '已退役素材不能继续填写数据。');
        $data = $request->validate([
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
        abort_if($media === '', 422, '选择“其他”时必须填写媒体名称。');
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
        return back()->with('success', '素材每日数据已保存，并保留修改人与历史版本。');
    }

    public function appendAsset(Request $request, OperationTestTask $task): RedirectResponse
    {
        $context = $this->authorized($request);
        $this->authorizeTaskOwner($context['user'], $task);
        $data = $request->validate([
            'selling_point' => ['required', 'string', 'max:1000'],
            'source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'url' => ['nullable', 'url', 'max:2048'],
        ]);
        abort_if(! $request->hasFile('file') && empty($data['url']), 422, '请上传素材文件或填写可访问的素材链接。');
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
        $this->notifyReviewers($task, '任务 '.$task->task_no.' 新增了待审核素材：'.($asset->original_name ?: '链接素材').'。', $user);
        return back()->with('success', '已追加一条新素材；该素材将独立进入审核与每日数据跟进。');
    }

    public function replaceAsset(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        $data = $request->validate([
            'selling_point' => ['required', 'string', 'max:1000'],
            'source_type' => ['required', Rule::in(['ai_created', 'peer_copied'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'url' => ['nullable', 'url', 'max:2048'],
        ]);
        abort_if(! $request->hasFile('file') && empty($data['url']), 422, '请上传新版本文件或填写新版本链接。');
        $user = $context['user'];
        $file = $request->file('file');
        $newAsset = DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($asset, $data, $file, $user): OperationTaskAsset {
            $history = $asset->version_history ?? [];
            $history[] = ['version' => $asset->version, 'event' => 'superseded', 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()];
            $asset->update(['version_history' => $history, 'retired_at' => now(), 'retired_by' => $user->id, 'retired_reason' => '已创建素材新版本']);
            return OperationTaskAsset::query()->create([
                'task_id' => $asset->task_id, 'uploader_user_id' => $user->id, 'uploader_name' => $this->userName($user),
                'selling_point' => trim($data['selling_point']),
                'source_type' => $data['source_type'],
                'file_path' => $file?->store('operation-tasks/assets/'.now()->format('Y/m'), 'local'),
                'original_name' => $file?->getClientOriginalName(), 'external_url' => trim((string) ($data['url'] ?? '')) ?: null,
                'mime_type' => $file?->getMimeType(), 'ai_confirmed' => $data['source_type'] === 'ai_created',
                'parent_asset_id' => $asset->id, 'version' => $asset->version + 1,
                'version_history' => [['version' => $asset->version + 1, 'event' => 'created_from_previous_version', 'previous_asset_id' => $asset->id, 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
            ]);
        });
        $this->notifyReviewers($asset->task, '任务 '.$asset->task->task_no.' 的素材已提交新版本，待重新审核。', $user);
        return back()->with('success', '素材新版本已创建；旧版本已退役保留，新版本需独立审核。');
    }

    public function retireAsset(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        abort_if($asset->retired_at, 422, '该素材已退役，无需重复操作。');
        $data = $request->validate(['retired_reason' => ['nullable', 'string', 'max:5000']]);
        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($asset, $data, $context): void {
            $asset->update(['retired_at' => now(), 'retired_by' => $context['user']->id, 'retired_reason' => trim((string) ($data['retired_reason'] ?? '')) ?: null]);
            OperationTaskDeliveryRound::query()->where('asset_id', $asset->id)->whereNull('stopped_at')->update([
                'stopped_at' => now(), 'stopped_by' => $context['user']->id, 'stopped_by_name' => $this->userName($context['user']), 'updated_at' => now(),
            ]);
        });
        return back()->with('success', '素材已退役；历史文件、审核、投放轮次和每日数据均已保留。');
    }

    public function markAssetLive(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        abort_if($asset->retired_at, 422, '已退役素材不能登记上线。');
        $data = $request->validate(['first_live_at' => ['required', 'date', 'before_or_equal:now']]);
        $already = (bool) $asset->first_live_at;
        $asset->update(['first_live_at' => $asset->first_live_at ?: $data['first_live_at']]);
        return back()->with('success', $already ? '首次上线时间已存在，未覆盖原始记录。' : '已登记素材首次上线时间。');
    }

    public function storeDeliveryRound(Request $request, OperationTaskAsset $asset): RedirectResponse
    {
        $context = $this->authorized($request);
        $asset->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $asset->task);
        abort_if($asset->retired_at, 422, '已退役素材不能新增投放轮次。');
        $data = $request->validate([
            'media' => ['required', Rule::in(self::MEDIA)], 'custom_media' => ['nullable', 'string', 'max:100'],
            'ad_identifier' => ['nullable', 'string', 'max:191'], 'delivery_purpose' => ['nullable', 'string', 'max:5000'],
            'launched_at' => ['required', 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $media = $data['media'] === '其他' ? trim((string) ($data['custom_media'] ?? '')) : $data['media'];
        abort_if($media === '', 422, '选择“其他”时必须填写媒体名称。');
        OperationTaskDeliveryRound::query()->create([
            'task_id' => $asset->task_id, 'asset_id' => $asset->id, 'media' => $media,
            'ad_identifier' => trim((string) ($data['ad_identifier'] ?? '')) ?: null,
            'delivery_purpose' => trim((string) ($data['delivery_purpose'] ?? '')) ?: null,
            'launched_at' => $data['launched_at'], 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_by' => $context['user']->id, 'created_by_name' => $this->userName($context['user']),
        ]);
        if (! $asset->first_live_at) $asset->update(['first_live_at' => $data['launched_at']]);
        return back()->with('success', '投放轮次已登记；系统不会调用任何广告平台接口。');
    }

    public function stopDeliveryRound(Request $request, OperationTaskDeliveryRound $round): RedirectResponse
    {
        $context = $this->authorized($request);
        $round->loadMissing(['task', 'asset']);
        $this->authorizeTaskOwner($context['user'], $round->task);
        abort_if($round->stopped_at, 422, '该投放轮次已停止。');
        $data = $request->validate(['stopped_at' => ['required', 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $round->update([
            'stopped_at' => $data['stopped_at'], 'stopped_by' => $context['user']->id, 'stopped_by_name' => $this->userName($context['user']),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: $round->notes,
        ]);
        return back()->with('success', '投放轮次已停止并保留历史。');
    }

    public function storeDailyPlan(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        abort_unless($this->canManage($context['user']), 403);
        $data = $request->validate([
            'plan_date' => ['required', 'date'], 'department_id' => ['required', 'integer'], 'user_id' => ['required', 'integer'],
            'plan_mode' => ['required', Rule::in(['single', 'category', 'mixed'])],
            'single_target' => ['nullable', 'integer', 'min:0', 'max:30'], 'category_target' => ['nullable', 'integer', 'min:0', 'max:30'],
            'plan_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $teams = $this->visibleSecondaryDepartments($context['user'], $context['portal']);
        $team = $teams->firstWhere('id', (int) $data['department_id']);
        abort_unless($team, 403);
        $member = $team->departmentMembers->firstWhere('id', (int) $data['user_id']);
        abort_unless($member, 422, '所选成员不属于当前二级部门。');

        [$singleTarget, $categoryTarget] = match ($data['plan_mode']) {
            'single' => [2, 0], 'category' => [0, 1],
            default => [(int) ($data['single_target'] ?? 0), (int) ($data['category_target'] ?? 0)],
        };
        abort_if($data['plan_mode'] === 'mixed' && ($singleTarget < 1 || $categoryTarget < 1), 422, '混合计划必须同时填写单品与类目目标。');

        $plan = OperationTaskDailyPlan::query()->updateOrCreate(
            ['plan_date' => $data['plan_date'], 'user_id' => $member->id],
            [
                'user_name' => $this->userName($member), 'department_id' => $team->id, 'department_name' => $team->name,
                'plan_mode' => $data['plan_mode'], 'single_target' => $singleTarget, 'category_target' => $categoryTarget,
                'plan_note' => trim((string) ($data['plan_note'] ?? '')) ?: null,
                'confirmed_by' => $context['user']->id, 'confirmed_by_name' => $this->userName($context['user']), 'confirmed_at' => now(),
            ]
        );
        $this->notifyUsers([$member->id], $context['user'], '测品日计划已确认', sprintf('%s 已确认你 %s 的计划：单品 %d 个，类目 %d 个。', $this->userName($context['user']), $plan->plan_date->format('Y-m-d'), $singleTarget, $categoryTarget));
        return back()->with('success', '成员每日测品计划已确认并保存。');
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
        $channels = $this->reviewChannels($context['user']);
        $query = OperationTestTask::query()->with(['assets.reviews', 'reviews', 'rectifications'])->latest('submitted_at');
        if (! $context['user']->isAdministrator()) $query->whereIn('department_id', $this->visibleDepartmentIds($context['user']));
        if ($selectedTeamId) $query->where('department_id', $selectedTeamId);
        if ($filters['from'] !== '') $query->whereDate('submitted_at', '>=', $filters['from']);
        if ($filters['to'] !== '') $query->whereDate('submitted_at', '<=', $filters['to']);
        if ($filters['status'] === 'pending') {
            $query->where(function ($taskQuery) use ($channels): void {
                foreach (array_keys($channels) as $channel) {
                    $taskQuery->orWhereDoesntHave('reviews', fn ($reviews) => $reviews->where('target_type', 'task')->whereColumn('target_version', 'operation_test_tasks.version')->where('channel', $channel));
                }
            });
        } elseif ($filters['status'] === 'rectification') {
            $query->whereHas('rectifications', fn ($rectifications) => $rectifications->whereIn('status', ['required', 'resubmitted', 'restore_required']));
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
            $taskReviews = $task->reviews
                ->where('target_type', 'task')
                ->where('target_version', $task->version)
                ->keyBy('channel');
            $task->setAttribute('leader_reviewer_names', $leaderNames ?: '未配置');
            $task->setAttribute('department_reviewer_names', $departmentNames ?: '未配置');
            $task->setAttribute('leader_review_status', optional($taskReviews->get('leader'))->result ?: 'pending');
            $task->setAttribute('department_review_status', optional($taskReviews->get('department'))->result ?: 'pending');
        });
        $taskFlowEvents = OperationTaskReviewEvent::query()
            ->where('target_type', 'task')
            ->whereIn('target_id', $tasks->getCollection()->pluck('id'))
            ->orderBy('reviewed_at')->orderBy('id')->get()->groupBy('target_id');
        $tasks->getCollection()->each(fn (OperationTestTask $task) => $task->setAttribute('review_flow_events', $taskFlowEvents->get($task->id, collect())));

        $recapQuery = OperationTaskRecap::query()
            ->whereIn('workflow_status', ['pending_leader', 'pending_department'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
        if (! $context['user']->isAdministrator()) {
            $leaderIds = $this->leaderDepartmentIds($context['user']);
            $departmentIds = $this->departmentReviewScopeIds($context['user']);
            $recapQuery->where(function ($visibility) use ($leaderIds, $departmentIds): void {
                if ($leaderIds !== []) $visibility->orWhere(function ($query) use ($leaderIds): void {
                    $query->where('workflow_status', 'pending_leader')->whereIn('department_id', $leaderIds);
                });
                if ($departmentIds !== []) $visibility->orWhere(function ($query) use ($departmentIds): void {
                    $query->where('workflow_status', 'pending_department')->whereIn('department_id', $departmentIds);
                });
            });
        }
        if ($selectedTeamId) $recapQuery->where('department_id', $selectedTeamId);
        if ($filters['from'] !== '') $recapQuery->whereDate('data_date', '>=', $filters['from']);
        if ($filters['to'] !== '') $recapQuery->whereDate('data_date', '<=', $filters['to']);
        $recaps = $recapQuery->paginate($recapPerPage, ['*'], 'recap_page')->withQueryString();
        $recapReviewerTeams = Department::query()
            ->with(['departmentMembers:id,name,username', 'parent.departmentMembers:id,name,username'])
            ->whereIn('id', $recaps->getCollection()->pluck('department_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $recaps->each(function (OperationTaskRecap $recap) use ($context, $recapReviewerTeams): void {
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

        return view('portals.operation-tasks.reviews', [...$context, 'tasks' => $tasks, 'recaps' => $recaps, 'reviewFilters' => $filters, 'reviewChannels' => $channels, 'reviewTeams' => $teams]);
    }

    public function review(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $request->validate([
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
        $this->notifyUsers([$task->owner_user_id], $context['user'], '测品任务审核结果', sprintf('任务 %s 已完成%s：%s。', $task->task_no, $data['channel'] === 'leader' ? '组长审核' : '部门审核', $resultLabel));
        if ($data['result'] === 'approved' && $data['channel'] === 'leader') $this->notifyReviewers($task, '任务 '.$task->task_no.' 已通过组长审核，仍待部门审核。', $context['user'], ['department']);
        return back()->with('success', '审核结果已保存，并保留本次操作历史。');
    }

    public function reviewAssets(Request $request): RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $request->validate([
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
            abort_unless($asset, 422, '提交的素材不属于当前任务。');
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

        $this->notifyUsers([$task->owner_user_id], $context['user'], '测品素材审核结果', sprintf('任务 %s 的素材已完成%s批量审核。', $task->task_no, $data['channel'] === 'leader' ? '组长' : '部门'));
        return back()->with('success', '全部素材审核结果已一次性保存。');
    }

    public function reviewBatch(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->authorized($request);
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'channel' => ['required', Rule::in(['leader', 'department'])],
            'assets' => ['required', 'array', 'min:1'],
            'assets.*.result' => ['required', Rule::in(['approved', 'rejected'])],
            'assets.*.opinion' => ['nullable', 'string', 'max:5000'],
            'task_result' => ['required', Rule::in(['approved', 'rejected'])],
            'task_rejection_scope' => ['nullable', Rule::in(['task', 'asset'])],
            'task_opinion' => ['nullable', 'string', 'max:5000'],
        ]);

        $task = OperationTestTask::query()->with('assets')->findOrFail((int) $data['task_id']);
        $allowed = $data['channel'] === 'leader'
            ? $this->canLeaderReviewTask($context['user'], $task)
            : $this->canDepartmentReviewTask($context['user'], $task);
        abort_unless($allowed, 403);

        $assets = $task->assets->keyBy('id');
        foreach ($data['assets'] as $assetId => $assetData) {
            if (! $assets->has((int) $assetId)) {
                throw ValidationException::withMessages(["assets.$assetId.result" => '提交的素材不属于当前任务。']);
            }
            if ($assetData['result'] === 'rejected' && trim((string) ($assetData['opinion'] ?? '')) === '') {
                throw ValidationException::withMessages(["assets.$assetId.opinion" => '驳回素材时必须填写具体整改意见。']);
            }
        }
        if ($data['task_result'] === 'rejected' && trim((string) ($data['task_opinion'] ?? '')) === '') {
            throw ValidationException::withMessages(['task_opinion' => '驳回任务时必须填写具体整改意见。']);
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($task, $data, $assets, $context): void {
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
                $data['task_rejection_scope'] ?? 'task',
                $context['user']
            );
            $freshTask = $task->fresh(['reviews', 'assets.reviews', 'rectifications']);
            $this->resolveResubmittedRectifications($freshTask);
            $this->synchronizeTaskRectificationState($freshTask->fresh());
        });

        $resultLabel = $data['task_result'] === 'approved' ? '通过' : '驳回并要求整改';
        $channelLabel = $data['channel'] === 'leader' ? '组长审核' : '部门审核';
        $this->notifyUsers([$task->owner_user_id], $context['user'], '测品任务审核结果', sprintf('任务 %s 已完成%s：%s。', $task->task_no, $channelLabel, $resultLabel));
        if ($data['task_result'] === 'approved' && $data['channel'] === 'leader') {
            $this->notifyReviewers($task, '任务 '.$task->task_no.' 已通过组长审核，仍待部门审核。', $context['user'], ['department']);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => '素材和任务审核结果已统一保存。']);
        }
        return back()->with('success', '素材和任务审核结果已统一保存。');
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
        if ($result === 'rejected') {
            OperationTaskRectification::query()->updateOrCreate(['source_review_id' => $review->id], [
                'task_id' => $task->id,
                'asset_id' => $targetType === 'asset' ? $target->id : null,
                'target_type' => $targetType,
                'target_id' => $target->id,
                'target_version' => $version,
                'review_channel' => $channel,
                'rejection_scope' => $rejectionScope,
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

    public function resubmitRectification(Request $request, OperationTaskRectification $rectification): RedirectResponse
    {
        $context = $this->authorized($request);
        $rectification->loadMissing(['task', 'asset']);
        $this->authorizeTaskOwner($context['user'], $rectification->task);
        abort_unless($rectification->status === 'required', 422, '该整改事项当前不可再次提交。');
        $data = $request->validate([
            'stop_confirmed' => ['accepted'], 'stop_media' => ['nullable', 'string', 'max:100'], 'stop_ad_identifier' => ['nullable', 'string', 'max:191'],
            'stop_notes' => ['nullable', 'string', 'max:5000'], 'rectification_content' => ['required', 'string', 'max:10000'],
            'selling_point' => ['nullable', 'string', 'max:1000'], 'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'url' => ['nullable', 'url', 'max:2048'], 'ai_confirmed' => ['nullable', 'accepted'],
            'source_type' => ['nullable', Rule::in(['ai_created', 'peer_copied'])],
        ]);
        abort_if(($request->hasFile('file') || filled($data['url'] ?? null)) && empty($data['source_type']) && ! $request->boolean('ai_confirmed'), 422, '上传或替换素材时，请选择素材标签。');
        $user = $context['user'];
        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($rectification, $data, $request, $user): void {
            $task = $rectification->task->fresh();
            $history = $task->version_history ?? [];
            $history[] = ['version' => $task->version, 'reason' => 'rectification_resubmitted', 'rectification_id' => $rectification->id, 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()];
            $nextVersion = (int) $task->version + 1;
            $task->update(['version' => $nextVersion, 'version_history' => $history, 'rectification_status' => 'resubmitted', 'delivery_status' => 'manual_stop_confirmed']);
            $file = $request->file('file');
            $newAssetId = null;
            if ($rectification->asset_id) {
                $source = OperationTaskAsset::query()->findOrFail($rectification->asset_id);
                $sourceHistory = $source->version_history ?? [];
                $sourceHistory[] = ['version' => $source->version, 'event' => 'rectification_replaced', 'rectification_id' => $rectification->id, 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()];
                $source->update(['version_history' => $sourceHistory, 'retired_at' => now(), 'retired_by' => $user->id, 'retired_reason' => '整改后提交新版本']);
                $asset = OperationTaskAsset::query()->create([
                    'task_id' => $task->id, 'uploader_user_id' => $user->id, 'uploader_name' => $this->userName($user),
                    'selling_point' => trim((string) ($data['selling_point'] ?? '')) ?: $source->selling_point,
                    'source_type' => $data['source_type'] ?? ($request->boolean('ai_confirmed') ? 'ai_created' : $source->source_type),
                    'file_path' => $file ? $file->store('operation-tasks/assets/'.now()->format('Y/m'), 'local') : $source->file_path,
                    'original_name' => $file ? $file->getClientOriginalName() : $source->original_name,
                    'external_url' => trim((string) ($data['url'] ?? '')) ?: $source->external_url, 'mime_type' => $file ? $file->getMimeType() : $source->mime_type,
                    'ai_confirmed' => ($data['source_type'] ?? ($request->boolean('ai_confirmed') ? 'ai_created' : $source->source_type)) === 'ai_created',
                    'parent_asset_id' => $source->id, 'version' => $source->version + 1,
                    'version_history' => [['version' => $source->version + 1, 'event' => 'rectification_resubmitted', 'rectification_id' => $rectification->id, 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
                ]);
                $newAssetId = $asset->id;
            } elseif ($file || filled($data['url'] ?? null)) {
                $asset = OperationTaskAsset::query()->create([
                    'task_id' => $task->id, 'uploader_user_id' => $user->id, 'uploader_name' => $this->userName($user),
                    'selling_point' => trim((string) ($data['selling_point'] ?? '')) ?: '任务整改补充素材',
                    'source_type' => $data['source_type'] ?? 'ai_created',
                    'file_path' => $file ? $file->store('operation-tasks/assets/'.now()->format('Y/m'), 'local') : null,
                    'original_name' => $file ? $file->getClientOriginalName() : null, 'external_url' => trim((string) ($data['url'] ?? '')) ?: null,
                    'mime_type' => $file ? $file->getMimeType() : null, 'ai_confirmed' => ($data['source_type'] ?? 'ai_created') === 'ai_created',
                    'version_history' => [['version' => 1, 'event' => 'rectification_supplement', 'rectification_id' => $rectification->id, 'changed_by' => $this->userName($user), 'changed_at' => now()->toIso8601String()]],
                ]);
                $newAssetId = $asset->id;
            }
            $rectification->update([
                'status' => 'resubmitted', 'stop_confirmed_at' => now(), 'stop_confirmed_by' => $user->id, 'stop_confirmed_by_name' => $this->userName($user),
                'stop_media' => trim((string) ($data['stop_media'] ?? '')) ?: null, 'stop_ad_identifier' => trim((string) ($data['stop_ad_identifier'] ?? '')) ?: null,
                'stop_notes' => trim((string) ($data['stop_notes'] ?? '')) ?: null, 'rectification_content' => trim($data['rectification_content']),
                'resubmitted_at' => now(), 'resubmitted_by' => $user->id, 'resubmitted_by_name' => $this->userName($user),
                'resubmitted_task_version' => $nextVersion, 'resubmitted_asset_id' => $newAssetId,
            ]);
        });
        $this->notifyReviewers($rectification->task, '任务 '.$rectification->task->task_no.' 已提交整改新版本，请复审。', $user);
        return back()->with('success', '整改已提交为新版本；历史审核结论已保留，新版本需重新审核。');
    }

    public function restoreRectification(Request $request, OperationTaskRectification $rectification): RedirectResponse
    {
        $context = $this->authorized($request);
        $rectification->loadMissing('task');
        $this->authorizeTaskOwner($context['user'], $rectification->task);
        abort_unless(in_array($rectification->status, ['resubmitted', 'completed', 'restore_required'], true), 422, '当前整改事项不需要登记恢复投放。');
        $data = $request->validate(['restore_confirmed' => ['accepted'], 'restored_media' => ['nullable', 'string', 'max:100'], 'restored_ad_identifier' => ['nullable', 'string', 'max:191'], 'restored_notes' => ['nullable', 'string', 'max:5000']]);
        $rectification->update([
            'status' => 'restored', 'restored_at' => now(), 'restored_by' => $context['user']->id, 'restored_by_name' => $this->userName($context['user']),
            'restored_media' => trim((string) ($data['restored_media'] ?? '')) ?: null, 'restored_ad_identifier' => trim((string) ($data['restored_ad_identifier'] ?? '')) ?: null,
            'restored_notes' => trim((string) ($data['restored_notes'] ?? '')) ?: null,
        ]);
        $this->synchronizeTaskRectificationState($rectification->task->fresh());
        return back()->with('success', '已记录人工恢复投放确认；系统未调用任何广告平台接口。');
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
        $filters = ['department' => $selectedTeamId ?: '', 'owner' => trim((string) $request->input('owner', ''))];
        $teamIds = $teams->modelKeys();
        $memberIds = $teams->flatMap(fn (Department $team) => $team->departmentMembers->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $rangeStart = $from.' 00:00:00';
        $rangeEnd = $to.' 23:59:59';
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
            'operators' => $operators, 'teams' => $allTeams, 'visibleMemberCount' => $visibleMemberCount,
            'dashboardFilters' => $filters, 'canManagePlans' => true,
        ]);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
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

    private function canLeaderReviewRecap(AdminUser $user, OperationTaskRecap $recap): bool
    {
        return $user->isAdministrator() || in_array((int) $recap->department_id, $this->leaderDepartmentIds($user), true);
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
        $this->notifyUsers(array_values(array_diff($this->reviewerUserIds($task, $channels), [(int) $sender->id])), $sender, '运营任务待办提醒', $content);
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
        $this->notifyUsers(array_values(array_diff($this->recapReviewerUserIds($recap, $channels), [(int) $sender->id])), $sender, '每日复盘待办提醒', $content);
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
