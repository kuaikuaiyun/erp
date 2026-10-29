<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Operation\OperationReportMetricsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AbnormalSkuController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly OperationReportMetricsService $metrics,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === 'caigou', 404);
        app()->setLocale('zh-CN');

        $validated = $request->validate([
            'report_date' => ['nullable', 'date_format:Y-m-d'],
            'missing_page' => ['nullable', 'integer', 'min:1'],
            'original_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $date = (string) ($validated['report_date'] ?? now('Asia/Shanghai')->format('Y-m-d'));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $missing = $this->paginate(
            $this->metrics->missingGuideSkuSummary(),
            (int) ($validated['missing_page'] ?? 1),
            $perPage,
        );
        $original = $this->paginate(
            $this->metrics->missingOriginalSkuSummary($date),
            (int) ($validated['original_page'] ?? 1),
            $perPage,
        );

        return view('portals.caigou.abnormal-skus.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'reportDate' => $date,
            'perPage' => $perPage,
            'missing' => $missing,
            'original' => $original,
        ]);
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function paginate(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
            'to' => min($total, $page * $perPage),
        ];
    }
}
