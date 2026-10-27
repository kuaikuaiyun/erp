<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Logistics\LogisticsProductStatisticsSpreadsheetService;
use App\Portals\Logistics\TongtuSkuMappingService;
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
        private readonly TongtuSkuMappingService $tongtuMappings,
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
            'refundReasonReport' => $data['refund_reason_report'],
            'productPagination' => $data['pagination'],
            'stores' => $this->statistics->storeOptions($filters),
            'filters' => $filters,
            'matchStatusOptions' => $this->matchStatusOptions(),
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
            ...$this->detailRules(),
        ]);
        $detail = $this->statistics->detailPage(
            $this->normaliseFilters($validated),
            (string) $validated['product_id'],
            $this->normaliseDetailOptions($validated),
        );

        if ($detail === null) {
            return response()->json([
                'message' => __('logistics.product_statistics.ui.detail_not_found'),
            ], 404);
        }

        return response()->json($detail);
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

    public function retryTongtuSkus(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'selected_ids.*' => ['string', 'distinct', 'max:512'],
            ...$this->filterRules(false),
        ]);

        $skus = $this->statistics->shopifySkusForProductIds(
            $this->normaliseFilters($validated),
            $validated['selected_ids'],
        );
        $queued = $this->tongtuMappings->retryUnmatchedForSkus($skus);

        return redirect()->back()->with(
            $queued > 0 ? 'success' : 'info',
            $queued > 0
                ? __('logistics.product_statistics.ui.retry_tongtu_skus_queued', ['count' => $queued])
                : __('logistics.product_statistics.ui.retry_tongtu_skus_none'),
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
            'match_status' => ['nullable', Rule::in(['', 'matched', 'unmatched', 'queued', 'not_found', 'conflict', 'failed'])],
            'sort' => ['nullable', Rule::in([
                '', 'name', 'sku', 'product_code', 'shop_count', 'sales', 'revenue',
                'paypal_refund_amount', 'paypal_refund_rate', 'paypal_dispute_count', 'paypal_dispute_rate',
                'credit_card_refund_amount', 'credit_card_refund_rate', 'combined_refund_rate',
            ])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ];
        if ($withPage) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', Rule::in([5, 10, 20])];
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    private function detailRules(): array
    {
        return [
            'detail_tab' => ['nullable', Rule::in([
                'overview', 'paypal_orders', 'credit_card_orders', 'refunds', 'disputes', 'orders',
            ])],
            'detail_page' => ['nullable', 'integer', 'min:1'],
            'detail_per_page' => ['nullable', 'integer', Rule::in([5, 10, 20])],
            'detail_sort' => ['nullable', 'string', 'max:64'],
            'detail_direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
            'detail_keyword' => ['nullable', 'string', 'max:191'],
            'detail_status' => ['nullable', 'string', 'max:191'],
            'detail_type' => ['nullable', 'string', 'max:191'],
            'detail_outcome' => ['nullable', 'string', 'max:191'],
            'detail_payment_method' => ['nullable', Rule::in(['paypal', 'credit_card'])],
            'detail_logistics_status' => ['nullable', 'string', 'max:191'],
            'detail_source_reason' => ['nullable', 'string', 'max:1000'],
            'detail_manual_reason' => ['nullable', 'string', 'max:1000'],
        ];
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
            'match_status' => trim((string) ($input['match_status'] ?? '')),
            'sort' => trim((string) ($input['sort'] ?? '')),
            'direction' => trim((string) ($input['direction'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array((int) ($input['per_page'] ?? 10), [5, 10, 20], true)
                ? (int) ($input['per_page'] ?? 10)
                : 10,
        ];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normaliseDetailOptions(array $input): array
    {
        return [
            'tab' => trim((string) ($input['detail_tab'] ?? 'overview')) ?: 'overview',
            'page' => max(1, (int) ($input['detail_page'] ?? 1)),
            'per_page' => in_array((int) ($input['detail_per_page'] ?? 10), [5, 10, 20], true)
                ? (int) ($input['detail_per_page'] ?? 10)
                : 10,
            'sort' => trim((string) ($input['detail_sort'] ?? '')),
            'direction' => trim((string) ($input['detail_direction'] ?? '')),
            'keyword' => trim((string) ($input['detail_keyword'] ?? '')),
            'status' => trim((string) ($input['detail_status'] ?? '')),
            'type' => trim((string) ($input['detail_type'] ?? '')),
            'outcome' => trim((string) ($input['detail_outcome'] ?? '')),
            'payment_method' => trim((string) ($input['detail_payment_method'] ?? '')),
            'logistics_status' => trim((string) ($input['detail_logistics_status'] ?? '')),
            'source_reason' => trim((string) ($input['detail_source_reason'] ?? '')),
            'manual_reason' => trim((string) ($input['detail_manual_reason'] ?? '')),
        ];
    }

    /** @return array<string,string> */
    private function matchStatusOptions(): array
    {
        return [
            'matched' => __('logistics.product_statistics.mapping.statuses.matched'),
            'unmatched' => __('logistics.product_statistics.mapping.statuses.unmatched'),
            'queued' => __('logistics.product_statistics.mapping.statuses.queued'),
            'not_found' => __('logistics.product_statistics.mapping.statuses.not_found'),
            'conflict' => __('logistics.product_statistics.mapping.statuses.conflict'),
            'failed' => __('logistics.product_statistics.mapping.statuses.failed'),
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
            'retryTongtuSkusConfirm' => __('logistics.product_statistics.ui.retry_tongtu_skus_confirm'),
            'filteredCount' => __('logistics.product_statistics.ui.filtered_count'),
            'selectExportWarning' => __('logistics.product_statistics.ui.select_export_warning'),
            'detailTitle' => __('logistics.product_statistics.ui.detail_title'),
            'detailLoading' => __('logistics.product_statistics.ui.detail_loading'),
            'detailLoadFailed' => __('logistics.product_statistics.ui.detail_load_failed'),
            'variantDetailTitle' => __('logistics.product_statistics.ui.variant_detail_title'),
            'overviewOverall' => __('logistics.product_statistics.ui.overview_overall'),
            'overviewPayPal' => __('logistics.product_statistics.ui.overview_paypal'),
            'overviewCreditCard' => __('logistics.product_statistics.ui.overview_credit_card'),
            'overviewUnitCount' => __('logistics.product_statistics.ui.overview_unit_count'),
            'overviewOrderCount' => __('logistics.product_statistics.ui.overview_order_count'),
            'overviewReceivedAmount' => __('logistics.product_statistics.ui.overview_received_amount'),
            'overviewRefundAmount' => __('logistics.product_statistics.ui.overview_refund_amount'),
            'overviewRefundRate' => __('logistics.product_statistics.ui.overview_refund_rate'),
            'overviewChargebackRate' => __('logistics.product_statistics.ui.overview_chargeback_rate'),
            'overviewChargebackRateFormula' => __('logistics.product_statistics.ui.overview_chargeback_rate_formula'),
            'overviewCustomerServiceRefundRate' => __('logistics.product_statistics.ui.overview_customer_service_refund_rate'),
            'overviewCustomerServiceRefundRateFormula' => __('logistics.product_statistics.ui.overview_customer_service_refund_rate_formula'),
            'overviewCreditCardDisputeRate' => __('logistics.product_statistics.ui.overview_credit_card_dispute_rate'),
            'overviewCreditCardDisputeRateFormula' => __('logistics.product_statistics.ui.overview_credit_card_dispute_rate_formula'),
            'overviewDisputeSummary' => __('logistics.product_statistics.ui.overview_dispute_summary'),
            'overviewDisputeCounts' => __('logistics.product_statistics.ui.overview_dispute_counts'),
            'overviewDisputeOrderCount' => __('logistics.product_statistics.ui.overview_dispute_order_count'),
            'overviewDisputeRecordCount' => __('logistics.product_statistics.ui.overview_dispute_record_count'),
            'overviewDisputeAmount' => __('logistics.product_statistics.ui.overview_dispute_amount'),
            'overviewDisputeRate' => __('logistics.product_statistics.ui.overview_dispute_rate'),
            'overviewDisputeRateFormula' => __('logistics.product_statistics.ui.overview_dispute_rate_formula'),
            'overviewDisputeBreakdown' => __('logistics.product_statistics.ui.overview_dispute_breakdown'),
            'overviewDisputeFormula' => __('logistics.product_statistics.ui.overview_dispute_formula'),
            'overviewDisputeReason' => __('logistics.product_statistics.ui.overview_dispute_reason'),
            'overviewDisputeNotApplicable' => __('logistics.product_statistics.ui.overview_dispute_not_applicable'),
            'overviewCreditCardAlertSummary' => __('logistics.product_statistics.ui.overview_credit_card_alert_summary'),
            'overviewCreditCardDisputeSummary' => __('logistics.product_statistics.ui.overview_credit_card_dispute_summary'),
            'overviewCreditCardRecordCounts' => __('logistics.product_statistics.ui.overview_credit_card_record_counts'),
            'overviewCreditCardReasonBreakdown' => __('logistics.product_statistics.ui.overview_credit_card_reason_breakdown'),
            'overviewCreditCardReason' => __('logistics.product_statistics.ui.overview_credit_card_reason'),
            'overviewCreditCardReasonFormula' => __('logistics.product_statistics.ui.overview_credit_card_reason_formula'),
            'overviewShippingRate' => __('logistics.product_statistics.ui.overview_shipping_rate'),
            'overviewDeliveryRate' => __('logistics.product_statistics.ui.overview_delivery_rate'),
            'overviewRefundFormula' => __('logistics.product_statistics.ui.overview_refund_formula'),
            'overviewShippingFormula' => __('logistics.product_statistics.ui.overview_shipping_formula'),
            'overviewDeliveryFormula' => __('logistics.product_statistics.ui.overview_delivery_formula'),
            'storeOrderDistribution' => __('logistics.product_statistics.ui.store_order_distribution'),
            'orders' => __('logistics.product_statistics.ui.orders'),
            'storeName' => __('logistics.product_statistics.ui.store_name'),
            'period' => __('logistics.product_statistics.ui.period'),
            'sales' => __('logistics.product_statistics.headers.sales'),
            'productCode' => __('logistics.product_statistics.headers.product_code'),
            'variantCount' => __('logistics.product_statistics.headers.variant_count'),
            'pendingTongtuMatch' => __('logistics.product_statistics.ui.pending_tongtu_match'),
            'shopifySkuPrefix' => __('logistics.product_statistics.ui.shopify_sku_prefix'),
            'variantCountPending' => __('logistics.product_statistics.ui.variant_count_pending'),
            'tongtuSku' => __('logistics.product_statistics.headers.tongtu_sku'),
            'shopifySku' => __('logistics.product_statistics.headers.shopify_sku'),
            'tongtuProductName' => __('logistics.product_statistics.headers.tongtu_product_name'),
            'orderCount' => __('logistics.product_statistics.headers.order_count'),
            'revenue' => __('logistics.product_statistics.headers.revenue'),
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
            'detailFilterVariantsPlaceholder' => __('logistics.product_statistics.ui.variant_details_placeholder'),
            'detailFilterRefundsPlaceholder' => __('logistics.product_statistics.ui.detail_filter_refunds_placeholder'),
            'detailFilterDisputesPlaceholder' => __('logistics.product_statistics.ui.detail_filter_disputes_placeholder'),
            'detailPageSummary' => __('logistics.product_statistics.ui.detail_page_summary'),
            'detailPageEmpty' => __('logistics.product_statistics.ui.detail_page_empty'),
            'previousPage' => __('logistics.product_statistics.ui.previous_page'),
            'nextPage' => __('logistics.product_statistics.ui.next_page'),
            'perPage' => __('logistics.product_statistics.ui.per_page'),
            'items' => __('logistics.product_statistics.ui.items'),
            'paymentMethod' => __('logistics.product_statistics.ui.payment_method'),
            'paypalRefund' => __('logistics.product_statistics.ui.paypal_refund'),
            'creditCardRefund' => __('logistics.product_statistics.ui.credit_card_refund'),
            'paypalDisputes' => __('logistics.product_statistics.ui.paypal_disputes'),
            'refundReference' => __('logistics.product_statistics.ui.refund_reference'),
            'sourceReason' => __('logistics.product_statistics.ui.source_reason'),
            'manualReason' => __('logistics.product_statistics.ui.manual_reason'),
            'noReason' => __('logistics.product_statistics.ui.no_reason'),
            'reasonSelectionTitle' => __('logistics.product_statistics.ui.reason_selection_title'),
            'reasonSelectionCount' => __('logistics.product_statistics.ui.reason_selection_count'),
            'reasonSelectionAmount' => __('logistics.product_statistics.ui.reason_selection_amount'),
            'reasonSelectionCountRatio' => __('logistics.product_statistics.ui.reason_selection_count_ratio'),
            'reasonSelectionAmountRatio' => __('logistics.product_statistics.ui.reason_selection_amount_ratio'),
            'reasonReportTitle' => __('logistics.product_statistics.ui.reason_report_title'),
            'reasonReportHint' => __('logistics.product_statistics.ui.reason_report_hint'),
            'reasonReportSource' => __('logistics.product_statistics.ui.reason_report_source'),
            'reasonReportManual' => __('logistics.product_statistics.ui.reason_report_manual'),
            'reasonReportTotal' => __('logistics.product_statistics.ui.reason_report_total'),
            'reasonReportOther' => __('logistics.product_statistics.ui.reason_report_other'),
            'reasonReportEmpty' => __('logistics.product_statistics.ui.reason_report_empty'),
            'reasonReportCountShare' => __('logistics.product_statistics.ui.reason_report_count_share'),
            'reasonReportAmountShare' => __('logistics.product_statistics.ui.reason_report_amount_share'),
            'reasonReportExpand' => __('logistics.product_statistics.ui.reason_report_expand'),
            'reasonReportCollapse' => __('logistics.product_statistics.ui.reason_report_collapse'),
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
            'amount' => __('logistics.product_statistics.ui.amount'),
            'reason' => __('logistics.product_statistics.ui.reason'),
            'status' => __('logistics.product_statistics.ui.status'),
            'disputeType' => __('logistics.product_statistics.ui.dispute_type'),
            'emptyRows' => __('logistics.product_statistics.ui.empty_rows'),
            'viewOrder' => __('logistics.product_statistics.ui.view_order'),
            'salesChange' => __('logistics.product_statistics.ui.sales_change'),
            'matchStatus' => __('logistics.product_statistics.headers.match_status'),
            'currentPclProductData' => __('logistics.product_statistics.mapping.current_pcl_product_data'),
            'currentPclProductDataEmpty' => __('logistics.product_statistics.mapping.current_pcl_product_data_empty'),
            'currentSkuData' => __('logistics.product_statistics.mapping.current_sku_data'),
            'shopifySkus' => __('logistics.product_statistics.mapping.shopify_skus'),
            'productWeight' => __('logistics.product_statistics.mapping.product_weight'),
            'packageWeight' => __('logistics.product_statistics.mapping.package_weight'),
            'currentCostCny' => __('logistics.product_statistics.mapping.current_cost_cny'),
            'averageCostCny' => __('logistics.product_statistics.mapping.average_cost_cny'),
            'packageCostCny' => __('logistics.product_statistics.mapping.package_cost_cny'),
            'guideCostCny' => __('logistics.product_statistics.mapping.guide_cost_cny'),
            'productDimensions' => __('logistics.product_statistics.mapping.product_dimensions'),
            'packageDimensions' => __('logistics.product_statistics.mapping.package_dimensions'),
            'tongtuUpdatedAt' => __('logistics.product_statistics.mapping.tongtu_updated_at'),
            'syncedAt' => __('logistics.product_statistics.mapping.synced_at'),
        ];
    }
}
