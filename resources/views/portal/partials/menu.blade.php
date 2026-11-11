@php
    $configurationCodes = ['backend_management', 'menu_configuration'];
    $isConfigurationMenu = in_array($menu->code, $configurationCodes, true);
    $children = $menus
        ->where('parent_id', $menu->getKey())
        ->where('is_enabled', true)
        ->filter(fn ($child) => ($canManagePortalMenus || !in_array($child->code, $configurationCodes, true))
            && (($canManageOperationTasks ?? false) || !in_array($child->code, ['fashion_operation_task_reviews', 'fashion_operation_task_dashboard'], true)));
    $path = trim((string) $menu->path);
    if (!\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '#'])) {
        $path = app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal, $path);
    }
    $href = \Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])
        ? $path
        : $portalOrigin.'/'.ltrim($path, '/');
    $icon = $menu->icon ?: 'fa-circle-o';
    $currentPath = '/'.trim(request()->path(), '/');
    $matchesCurrentPath = static function (string $candidatePath) use ($currentPath, $portal): bool {
        if (\Illuminate\Support\Str::startsWith($candidatePath, ['http://', 'https://', '#'])) {
            return false;
        }

        $candidatePath = app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal, $candidatePath);
        $menuPath = '/'.trim(parse_url($candidatePath, PHP_URL_PATH) ?: $candidatePath, '/');

        if ($menuPath === '/' || $menuPath === app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal)) {
            return $currentPath === '/' || $currentPath === $menuPath;
        }

        return $menuPath === $currentPath
            || \Illuminate\Support\Str::startsWith($currentPath, rtrim($menuPath, '/').'/');
    };
    $isCurrent = $matchesCurrentPath($path);
    $hasActiveChild = $children->contains(
        fn ($child): bool => $matchesCurrentPath(trim((string) $child->path))
    );
    $isDefaultExpanded = $portal->code === 'zhuli'
        && $menu->code === 'assistant_daily_submissions';
    $isExpanded = $hasActiveChild || $isDefaultExpanded;
    $label = $menu->title ?: __($menu->title_key);
    $canAccessProductRequestMenu = match ($menu->code) {
        'procurement_inspections' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canInspectProcurement($user),
        'placement_reviews' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canReviewPlacement($user),
        'link_sku_manager' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canManageLinkSkus($user),
        'existing_product_library' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canViewExistingProductLibrary($user),
        'operation_non_runnable_product_library' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canViewExistingProductLibrary($user),
        'operation_ai_product_library' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canViewOperationAiProductLibrary($user),
        default => true,
    };
    $canAccessAssistantAnalyticsMenu = $menu->code !== 'assistant_shopify_analytics'
        || app(\App\Portals\Assistant\AssistantPortalAuthorizationService::class)->canViewShopifyAnalytics($user);
    $canAccessOperationTaskManagementMenu = ($canManageOperationTasks ?? false)
        || !in_array($menu->code, ['fashion_operation_task_reviews', 'fashion_operation_task_dashboard'], true);
@endphp

@if ((!$isConfigurationMenu || $canManagePortalMenus) && $canAccessProductRequestMenu && $canAccessAssistantAnalyticsMenu && $canAccessOperationTaskManagementMenu)
    @if ($children->isEmpty())
    <li class="{{ $isCurrent ? 'active' : '' }}">
        <a href="{{ $href }}">
            <i class="fa {{ $icon }}"></i>
            <span>{{ $label }}</span>
            @if($portal->code === 'fuzhuang' && $menu->code === 'fashion_operation_task_recaps' && ($operationTaskReviewCounts['rejected_recaps'] ?? 0) > 0)
            <span class="operation-recap-deadline" title="每日复盘请在每天 11 点前提交"><span>每天 11 点前提交</span></span>
            @endif
            @if($portal->code === 'fuzhuang' && $menu->code === 'fashion_operation_task_recaps' && ($operationTaskReviewCounts['rejected_recaps'] ?? 0) > 0)
            <span class="operation-rejected-count" title="复盘被驳回待修改">驳回 {{ $operationTaskReviewCounts['rejected_recaps'] }}</span>
            @endif
            @if($portal->code === 'fuzhuang' && $menu->code === 'fashion_operation_task_reviews' && (($operationTaskReviewCounts['recaps'] ?? 0) > 0 || ($operationTaskReviewCounts['tests'] ?? 0) > 0))
            <span class="operation-review-counts" title="待审核：每日复盘 / 测品任务">
                <span class="operation-review-count operation-review-count-recap">复 {{ $operationTaskReviewCounts['recaps'] ?? 0 }}</span>
                <span class="operation-review-count operation-review-count-test">测 {{ $operationTaskReviewCounts['tests'] ?? 0 }}</span>
            </span>
            @endif
            @if(array_key_exists($menu->code, $assistantDailyPendingCounts ?? []))
            <span class="badge {{ $assistantDailyPendingCounts[$menu->code] > 0 ? 'bg-yellow' : 'bg-gray' }} pull-right" title="待处理任务数">{{ $assistantDailyPendingCounts[$menu->code] }}</span>
            @endif
            @if($menu->code === 'ai_product_requests')
            <i class="fa fa-question-circle" style="margin-left:4px;color:#f39c12;font-size:14px;cursor:pointer;" data-toggle="tooltip" data-placement="right" title="{{ __('ai_products.menus.request_hint') }}"></i>
            @endif
            @if($menu->code === 'product_requests')
            <span id="operation-pending-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'procurement_inspections')
            <span id="procurement-pending-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'placement_reviews')
            <span id="placement-review-pending-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'warehouse_acceptances')
            <span id="warehouse-pending-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'caigou_warehouse_weight_exceptions')
            <span id="warehouse-weight-exception-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'operation_internal_messages')
            <span id="operation-internal-message-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'operation_abnormal_orders')
            <span id="operation-abnormal-order-badge" class="badge bg-yellow" style="margin-left:6px;">0</span>
            @elseif($menu->code === 'operation_daily_applovin_spends')
            <span id="operation-applovin-gift-badge" class="badge bg-yellow" style="margin-left:6px;display:none;" title="待上传礼金图数量">0</span>
            @endif
        </a>
    </li>
    @else
    <li class="treeview {{ $hasActiveChild ? 'active' : '' }} {{ $isExpanded ? 'menu-open' : '' }}">
        <a href="#">
            <i class="fa {{ $icon }}"></i>
            <span>{{ $label }}</span>
            @if($portal->code === 'fuzhuang' && $menu->code === 'fashion_operation_tasks')
                <span class="operation-recap-deadline">每天 11 点前提交</span>
            @endif
            @if($portal->code === 'fuzhuang' && $menu->code === 'fashion_operation_tasks')
                @if(($operationTaskReviewCounts['rejected_recaps'] ?? 0) > 0)
                    <span class="operation-rejected-count">驳回 {{ $operationTaskReviewCounts['rejected_recaps'] }}</span>
                @endif
            @endif
            <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu" @if($isExpanded) style="display:block" @endif>
            @foreach ($children as $child)
                @include('portal.partials.menu', [
                    'menu' => $child,
                    'menus' => $menus,
                    'portalOrigin' => $portalOrigin,
                    'canManagePortalMenus' => $canManagePortalMenus,
                    'canManageOperationTasks' => $canManageOperationTasks,
                    'operationTaskReviewCounts' => $operationTaskReviewCounts ?? ['recaps' => 0, 'tests' => 0, 'rejected_recaps' => 0],
                ])
            @endforeach
        </ul>
    </li>
    @endif
@endif
