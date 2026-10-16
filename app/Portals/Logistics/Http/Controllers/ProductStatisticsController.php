<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Logistics\LogisticsProductStatisticsSpreadsheetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly LogisticsProductStatisticsService $statistics,
        private readonly LogisticsProductStatisticsSpreadsheetService $spreadsheets,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $data = $this->statistics->page($filters);

        return view('portals.logistics.product-statistics.index', [
            ...$this->viewContext($context),
            'products' => $data['products'],
            'productSummary' => $data['summary'],
            'productPagination' => $data['pagination'],
            'stores' => $this->statistics->storeOptions($filters),
            'filters' => $filters,
            'decisionOptions' => $this->decisionOptions(),
            'labels' => $this->labels(),
        ]);
    }

    public function detail(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:512'],
            ...$this->filterRules(false),
        ]);
        $product = $this->statistics->detail(
            $this->normaliseFilters($validated),
            (string) $validated['product_id'],
        );

        if ($product === null) {
            return response()->json([
                'message' => __('logistics.product_statistics.ui.detail_not_found'),
            ], 404);
        }

        return response()->json(['product' => $product]);
    }

    public function export(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'selected_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'selected_ids.*' => ['string', 'distinct'],
            ...$this->filterRules(false),
        ]);
        $filters = $this->normaliseFilters($validated);
        $selectedIds = $validated['scope'] === 'selected' ? $validated['selected_ids'] : null;
        if (!$this->statistics->hasExportRows($filters, $selectedIds)) {
            throw ValidationException::withMessages([
                'scope' => __('logistics.product_statistics.export_empty'),
            ]);
        }

        return $this->spreadsheets->exportResponse(
            $this->statistics->productsForRows(
                $this->statistics->exportRows($filters, $selectedIds),
                $filters,
            ),
        );
    }

    /** @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->logistics->assertLogisticsPortal($context['portal']);

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     * @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return $this->normaliseFilters($request->validate($this->filterRules(true)));
    }

    /** @return array<string,mixed> */
    private function filterRules(bool $withPage): array
    {
        $rules = [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'store' => ['nullable', 'string', 'max:191'],
            'decision' => ['nullable', Rule::in(['', 'continue', 'optimize', 'watch', 'stop'])],
            'sort' => ['nullable', Rule::in([
                '', 'name', 'sku', 'shop_count', 'sales', 'revenue', 'cost', 'fees',
                'paypal_refund_amount', 'paypal_refund_rate', 'paypal_dispute_count', 'paypal_dispute_rate',
                'credit_card_refund_amount', 'credit_card_refund_rate', 'combined_refund_rate',
                'profit', 'profit_rate', 'decision',
            ])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ];
        if ($withPage) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', Rule::in([5, 10, 20])];
        }

        return $rules;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normaliseFilters(array $input): array
    {
        return [
            'date_from' => trim((string) ($input['date_from'] ?? '')),
            'date_to' => trim((string) ($input['date_to'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'store' => trim((string) ($input['store'] ?? '')),
            'decision' => trim((string) ($input['decision'] ?? '')),
            'sort' => trim((string) ($input['sort'] ?? '')),
            'direction' => trim((string) ($input['direction'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array((int) ($input['per_page'] ?? 10), [5, 10, 20], true)
                ? (int) ($input['per_page'] ?? 10)
                : 10,
        ];
    }

    /** @return array<string,string> */
    private function decisionOptions(): array
    {
        return [
            'continue' => __('logistics.product_statistics.decisions.continue'),
            'optimize' => __('logistics.product_statistics.decisions.optimize'),
            'watch' => __('logistics.product_statistics.decisions.watch'),
            'stop' => __('logistics.product_statistics.decisions.stop'),
        ];
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return [
            'notAvailable' => __('logistics.common.not_available'),
            'view' => __('logistics.product_statistics.ui.view'),
            'detail' => __('logistics.product_statistics.ui.detail'),
            'variant' => __('logistics.product_statistics.ui.variant'),
            'orderedVariant' => __('logistics.product_statistics.ui.ordered_variant'),
            'pageEmpty' => __('logistics.product_statistics.ui.page_empty'),
            'pageSummary' => __('logistics.product_statistics.ui.page_summary'),
            'totalProducts' => __('logistics.product_statistics.ui.total_products'),
            'selectedCount' => __('logistics.product_statistics.ui.selected_count'),
            'filteredCount' => __('logistics.product_statistics.ui.filtered_count'),
            'selectExportWarning' => __('logistics.product_statistics.ui.select_export_warning'),
            'detailTitle' => __('logistics.product_statistics.ui.detail_title'),
            'detailLoading' => __('logistics.product_statistics.ui.detail_loading'),
            'detailLoadFailed' => __('logistics.product_statistics.ui.detail_load_failed'),
            'variantDetailTitle' => __('logistics.product_statistics.ui.variant_detail_title'),
            'profitBreakdown' => __('logistics.product_statistics.ui.profit_breakdown'),
            'storeOrderDistribution' => __('logistics.product_statistics.ui.store_order_distribution'),
            'orders' => __('logistics.product_statistics.ui.orders'),
            'storeName' => __('logistics.product_statistics.ui.store_name'),
            'period' => __('logistics.product_statistics.ui.period'),
            'sales' => __('logistics.product_statistics.headers.sales'),
            'revenue' => __('logistics.product_statistics.headers.revenue'),
            'cost' => __('logistics.product_statistics.headers.cost'),
            'fees' => __('logistics.product_statistics.headers.fees'),
            'refunds' => __('logistics.product_statistics.headers.refunds'),
            'paypalRefundAmount' => __('logistics.product_statistics.headers.paypal_refund_amount'),
            'paypalRefundRate' => __('logistics.product_statistics.headers.paypal_refund_rate'),
            'paypalDisputeCount' => __('logistics.product_statistics.headers.paypal_dispute_count'),
            'paypalDisputeRate' => __('logistics.product_statistics.headers.paypal_dispute_rate'),
            'creditCardRefundAmount' => __('logistics.product_statistics.headers.credit_card_refund_amount'),
            'creditCardRefundRate' => __('logistics.product_statistics.headers.credit_card_refund_rate'),
            'combinedRefundRate' => __('logistics.product_statistics.headers.combined_refund_rate'),
            'shippingRate' => __('logistics.product_statistics.headers.shipping_rate'),
            'deliveryRate' => __('logistics.product_statistics.headers.delivery_rate'),
            'profit' => __('logistics.product_statistics.headers.profit'),
            'profitRate' => __('logistics.product_statistics.headers.profit_rate'),
            'refundRate' => __('logistics.product_statistics.headers.refund_rate'),
            'actualRefunds' => __('logistics.product_statistics.ui.actual_refunds'),
            'actualRefundCount' => __('logistics.product_statistics.ui.actual_refund_count'),
            'refundDetails' => __('logistics.product_statistics.ui.refund_details'),
            'refundAllocationNote' => __('logistics.product_statistics.ui.refund_allocation_note'),
            'crossProductNote' => __('logistics.product_statistics.ui.cross_product_note'),
            'creditCardDisputeNote' => __('logistics.product_statistics.ui.credit_card_dispute_note'),
            'paypalOrders' => __('logistics.product_statistics.ui.paypal_orders'),
            'creditCardOrders' => __('logistics.product_statistics.ui.credit_card_orders'),
            'refundsAndDisputes' => __('logistics.product_statistics.ui.refunds_and_disputes'),
            'refundRecords' => __('logistics.product_statistics.ui.refund_records'),
            'relatedOrders' => __('logistics.product_statistics.ui.related_orders'),
            'detailFilterKeyword' => __('logistics.product_statistics.ui.detail_filter_keyword'),
            'detailFilterSearch' => __('logistics.product_statistics.ui.detail_filter_search'),
            'detailFilterReset' => __('logistics.product_statistics.ui.detail_filter_reset'),
            'detailFilterAll' => __('logistics.product_statistics.ui.detail_filter_all'),
            'detailFilterStatus' => __('logistics.product_statistics.ui.detail_filter_status'),
            'detailFilterType' => __('logistics.product_statistics.ui.detail_filter_type'),
            'detailFilterOutcome' => __('logistics.product_statistics.ui.detail_filter_outcome'),
            'detailFilterLogisticsStatus' => __('logistics.product_statistics.ui.detail_filter_logistics_status'),
            'detailFilterOrdersPlaceholder' => __('logistics.product_statistics.ui.detail_filter_orders_placeholder'),
            'detailFilterRefundsPlaceholder' => __('logistics.product_statistics.ui.detail_filter_refunds_placeholder'),
            'detailFilterDisputesPlaceholder' => __('logistics.product_statistics.ui.detail_filter_disputes_placeholder'),
            'detailPageSummary' => __('logistics.product_statistics.ui.detail_page_summary'),
            'detailPageEmpty' => __('logistics.product_statistics.ui.detail_page_empty'),
            'paymentMethod' => __('logistics.product_statistics.ui.payment_method'),
            'paypalRefund' => __('logistics.product_statistics.ui.paypal_refund'),
            'creditCardRefund' => __('logistics.product_statistics.ui.credit_card_refund'),
            'paypalDisputes' => __('logistics.product_statistics.ui.paypal_disputes'),
            'refundReference' => __('logistics.product_statistics.ui.refund_reference'),
            'sourceReason' => __('logistics.product_statistics.ui.source_reason'),
            'manualReason' => __('logistics.product_statistics.ui.manual_reason'),
            'linkedRefunds' => __('logistics.product_statistics.ui.linked_refunds'),
            'disputeOutcome' => __('logistics.product_statistics.ui.dispute_outcome'),
            'responseDueAt' => __('logistics.product_statistics.ui.response_due_at'),
            'updatedAt' => __('logistics.product_statistics.ui.updated_at'),
            'orderNumber' => __('logistics.product_statistics.ui.order_number'),
            'billNumber' => __('logistics.product_statistics.ui.bill_number'),
            'orderCreatedAt' => __('logistics.product_statistics.ui.order_created_at'),
            'trackingNumber' => __('logistics.product_statistics.ui.tracking_number'),
            'logisticsStatus' => __('logistics.product_statistics.ui.logistics_status'),
            'quantity' => __('logistics.product_statistics.ui.quantity'),
            'actualProfit' => __('logistics.product_statistics.ui.actual_profit'),
            'amount' => __('logistics.product_statistics.ui.amount'),
            'reason' => __('logistics.product_statistics.ui.reason'),
            'status' => __('logistics.product_statistics.ui.status'),
            'disputeType' => __('logistics.product_statistics.ui.dispute_type'),
            'emptyRows' => __('logistics.product_statistics.ui.empty_rows'),
            'viewOrder' => __('logistics.product_statistics.ui.view_order'),
            'salesChange' => __('logistics.product_statistics.ui.sales_change'),
        ];
    }
}
