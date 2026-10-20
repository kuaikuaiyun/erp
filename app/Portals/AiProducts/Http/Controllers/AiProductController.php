<?php

namespace App\Portals\AiProducts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiProduct;
use App\Models\AiProductSubmission;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AiProducts\AiProductAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AiProductController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AiProductAccessService $access,
    ) {
    }

    /** 运营门户：AI 产品需求提报列表 */
    public function operationIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, AiProductAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $submissions = $this->access->applyOperationVisibility(AiProductSubmission::query(), $context['user'])
            ->when($filters['keyword'] !== '', function (Builder $query) use ($filters): Builder {
                return $query->where(function (Builder $inner) use ($filters): void {
                    $inner->where('report_no', 'like', '%'.$filters['keyword'].'%')
                        ->orWhere('placement_link', 'like', '%'.$filters['keyword'].'%');
                });
            })
            ->when($filters['review_status'] !== '', fn (Builder $query): Builder => $query->where('review_status', $filters['review_status']))
            ->latest('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('portals.ai-products.operation.index', [
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'submissions' => $submissions,
            'filters' => $filters,
            'statusLabels' => AiProductSubmission::statusLabels(),
        ]);
    }

    /** 运营门户：提交 AI 产品需求提报 */
    public function operationStore(Request $request): Response
    {
        $context = $this->portalContext($request, AiProductAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $data = Validator::make($request->all(), $this->storeRules(), [], [
            'placement_link' => __('ai_products.fields.placement_link'),
        ])->validate();

        $actor = $this->access->actorSnapshot($context['user']);
        $connection = config('admin.database.connection') ?: config('database.default');

        $submission = DB::connection($connection)->transaction(function () use ($data, $actor): AiProductSubmission {
            $submission = new AiProductSubmission([
                'report_no' => 'AI-TMP-'.uniqid(),
                'ai_product_id' => null,
                'ai_product_name' => null,
                'placement_link' => trim((string) $data['placement_link']),
                'operation_user_id' => $actor['user_id'],
                'operation_user_name' => $actor['user_name'],
                'operation_department_id' => $actor['department_id'],
                'operation_display_name' => $actor['display_name'],
                'review_status' => AiProductSubmission::REVIEW_PENDING,
            ]);
            $submission->save();

            $submission->report_no = 'AI-'.now('Asia/Shanghai')->format('Ymd').'-'.str_pad((string) $submission->getKey(), 6, '0', STR_PAD_LEFT);
            $submission->save();

            return $submission;
        });

        return redirect()
            ->route('ai-products.operation.index')
            ->with('success', __('ai_products.operation.submit_success', ['report_no' => $submission->report_no]));
    }

    /** 入录审核门户：AI 产品投放链接审核列表 */
    public function reviewIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, AiProductAccessService::REVIEW_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReview($context['user']), 403);

        $filters = $this->filters($request);
        $submissions = AiProductSubmission::query()
            ->when($filters['keyword'] !== '', function (Builder $query) use ($filters): Builder {
                return $query->where(function (Builder $inner) use ($filters): void {
                    $inner->where('report_no', 'like', '%'.$filters['keyword'].'%')
                        ->orWhere('placement_link', 'like', '%'.$filters['keyword'].'%')
                        ->orWhere('operation_display_name', 'like', '%'.$filters['keyword'].'%');
                });
            })
            ->when($filters['review_status'] !== '', fn (Builder $query): Builder => $query->where('review_status', $filters['review_status']))
            ->latest('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('portals.ai-products.review.index', [
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'submissions' => $submissions,
            'filters' => $filters,
            'statusLabels' => AiProductSubmission::statusLabels(),
        ]);
    }

    /** 入录审核门户：审核通过 */
    public function reviewApprove(Request $request, AiProductSubmission $submission): Response
    {
        $context = $this->portalContext($request, AiProductAccessService::REVIEW_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReview($context['user']), 403);

        if ((string) $submission->review_status !== AiProductSubmission::REVIEW_PENDING) {
            return redirect()
                ->route('ai-products.review.index')
                ->with('error', __('ai_products.review.already_reviewed'));
        }

        $remark = trim((string) $request->input('review_remark', ''));
        $reviewer = $this->access->actorSnapshot($context['user']);

        $submission->forceFill([
            'review_status' => AiProductSubmission::REVIEW_APPROVED,
            'review_remark' => $remark !== '' ? $remark : null,
            'reviewer_id' => $reviewer['user_id'],
            'reviewer_name' => $reviewer['display_name'],
            'reviewed_at' => now(),
        ])->save();

        return redirect()
            ->route('ai-products.review.index')
            ->with('success', __('ai_products.review.approve_success', ['report_no' => $submission->report_no]));
    }

    /** 入录审核门户：审核不通过（备注必填） */
    public function reviewReject(Request $request, AiProductSubmission $submission): Response
    {
        $context = $this->portalContext($request, AiProductAccessService::REVIEW_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReview($context['user']), 403);

        if ((string) $submission->review_status !== AiProductSubmission::REVIEW_PENDING) {
            return redirect()
                ->route('ai-products.review.index')
                ->with('error', __('ai_products.review.already_reviewed'));
        }

        $data = Validator::make($request->all(), [
            'review_remark' => ['required', 'string', 'max:2000'],
        ], [], [
            'review_remark' => __('ai_products.fields.review_remark'),
        ])->validate();

        $reviewer = $this->access->actorSnapshot($context['user']);

        $submission->forceFill([
            'review_status' => AiProductSubmission::REVIEW_REJECTED,
            'review_remark' => trim((string) $data['review_remark']),
            'reviewer_id' => $reviewer['user_id'],
            'reviewer_name' => $reviewer['display_name'],
            'reviewed_at' => now(),
        ])->save();

        return redirect()
            ->route('ai-products.review.index')
            ->with('success', __('ai_products.review.reject_success', ['report_no' => $submission->report_no]));
    }

    /** @return array<string, mixed> */
    private function storeRules(): array
    {
        return [
            'placement_link' => ['required', 'string', 'max:2048', 'url'],
        ];
    }

    private function portalContext(Request $request, string $portalCode): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->access->assertPortal($context['portal'], $portalCode);

        return $context;
    }

    /** @return array{keyword:string,review_status:string,per_page:int} */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        return [
            'keyword' => trim((string) $request->input('keyword', '')),
            'review_status' => in_array($request->input('review_status'), array_keys(AiProductSubmission::statusLabels()), true)
                ? (string) $request->input('review_status')
                : '',
            'per_page' => $perPage,
        ];
    }
}
