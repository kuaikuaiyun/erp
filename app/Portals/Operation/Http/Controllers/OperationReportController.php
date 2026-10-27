<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);

        $user = $context['user'];
        $canViewAllStores = $user->isAdministrator()
            || strcasecmp((string) $user->username, 'kky_boss') === 0;

        $validated = $request->validate([
            'filtered' => ['nullable', 'in:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'media_source' => ['nullable', Rule::in(array_values([...LogisticsOrder::MEDIA_SOURCES, LogisticsOrder::MEDIA_SOURCE_OTHER]))],
        ]);
        $today = now('Asia/Shanghai')->format('Y-m-d');
        $isFiltered = ($validated['filtered'] ?? '') === '1';
        $dateFrom = $isFiltered ? (string) ($validated['date_from'] ?? $today) : $today;
        $dateTo = $isFiltered ? (string) ($validated['date_to'] ?? $today) : $today;
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $dateFrom.' 00:00:00', 'Asia/Shanghai')->utc();
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $dateTo.' 23:59:59', 'Asia/Shanghai')->utc();
        $mediaSource = $isFiltered ? (string) ($validated['media_source'] ?? '') : '';

        $rows = LogisticsOrder::query()
            ->whereBetween('order_created_at', [$start, $end])
            ->when(! $canViewAllStores, function ($query) use ($user): void {
                $query->whereHas('store', function ($storeQuery) use ($user): void {
                    $storeQuery->where('owner_user_id', (int) $user->getKey());
                });
            })
            ->when($mediaSource !== '', fn ($query) => $query->where('media_source', $mediaSource))
            ->selectRaw("DATE(CONVERT_TZ(order_created_at, '+00:00', '+08:00')) AS order_date")
            ->selectRaw("COALESCE(NULLIF(TRIM(operator_name), ''), '未分配') AS operator_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(source_shop_domain), ''), NULLIF(TRIM(store_name), ''), '未知店铺') AS shop_domain")
            ->selectRaw("COALESCE(NULLIF(TRIM(media_source), ''), '其他') AS media_source")
            ->selectRaw("SUM(CASE WHEN subscription_type = '订阅首次购买' THEN 1 ELSE 0 END) AS first_subscription_orders")
            ->selectRaw("SUM(CASE WHEN subscription_type = '订阅首次购买' THEN COALESCE(paid_amount_usd, 0) ELSE 0 END) AS first_subscription_revenue")
            ->selectRaw("SUM(CASE WHEN subscription_type = '无订阅' THEN 1 ELSE 0 END) AS first_normal_orders")
            ->selectRaw("SUM(CASE WHEN subscription_type = '无订阅' THEN COALESCE(paid_amount_usd, 0) ELSE 0 END) AS first_normal_revenue")
            ->selectRaw("SUM(CASE WHEN subscription_type LIKE '订阅复购%' THEN 1 ELSE 0 END) AS renewal_orders")
            ->selectRaw("SUM(CASE WHEN subscription_type LIKE '订阅复购%' THEN COALESCE(paid_amount_usd, 0) ELSE 0 END) AS renewal_revenue")
            ->selectRaw('COUNT(*) AS total_orders')
            ->selectRaw('SUM(COALESCE(paid_amount_usd, 0)) AS total_revenue')
            ->groupByRaw("DATE(CONVERT_TZ(order_created_at, '+00:00', '+08:00')), COALESCE(NULLIF(TRIM(operator_name), ''), '未分配'), COALESCE(NULLIF(TRIM(source_shop_domain), ''), NULLIF(TRIM(store_name), ''), '未知店铺'), COALESCE(NULLIF(TRIM(media_source), ''), '其他')")
            ->orderByDesc('order_date')
            ->orderBy('operator_name')
            ->orderBy('shop_domain')
            ->orderBy('media_source')
            ->get();

        $identityRowspans = [];
        for ($index = 0, $rowCount = $rows->count(); $index < $rowCount; $index++) {
            $row = $rows[$index];
            $identityKey = [$row->order_date, $row->operator_name, $row->shop_domain];

            if ($index > 0) {
                $previous = $rows[$index - 1];
                if ($identityKey === [$previous->order_date, $previous->operator_name, $previous->shop_domain]) {
                    $identityRowspans[$index] = 0;
                    continue;
                }
            }

            $rowspan = 1;
            while ($index + $rowspan < $rowCount) {
                $next = $rows[$index + $rowspan];
                if ($identityKey !== [$next->order_date, $next->operator_name, $next->shop_domain]) {
                    break;
                }
                $rowspan++;
            }
            $identityRowspans[$index] = $rowspan;
        }

        $totals = [
            'first_subscription_orders' => $rows->sum('first_subscription_orders'),
            'first_subscription_revenue' => $rows->sum('first_subscription_revenue'),
            'first_normal_orders' => $rows->sum('first_normal_orders'),
            'first_normal_revenue' => $rows->sum('first_normal_revenue'),
            'renewal_orders' => $rows->sum('renewal_orders'),
            'renewal_revenue' => $rows->sum('renewal_revenue'),
            'total_orders' => $rows->sum('total_orders'),
            'total_revenue' => $rows->sum('total_revenue'),
        ];

        return view('portals.operation.reports.index', [
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'rows' => $rows,
            'identityRowspans' => $identityRowspans,
            'totals' => $totals,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'media_source' => $mediaSource],
            'mediaSources' => array_values([...LogisticsOrder::MEDIA_SOURCES, LogisticsOrder::MEDIA_SOURCE_OTHER]),
            'reportScopeLabel' => $canViewAllStores ? '全部店铺' : '我的已分配店铺',
        ]);
    }
}
