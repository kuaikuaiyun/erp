<form class="product-request-assign-form" method="post" action="{{ route('product-requests.procurement.assign') }}" data-product-request-modal-form>
    @csrf
    @foreach($records as $record)
        <input type="hidden" name="ids[]" value="{{ $record->getKey() }}">
    @endforeach
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.procurement.assign_title') }}</h4>
    </div>
    <div class="modal-body product-request-assign-body">
        <div class="product-request-assign-intro">
            <span class="product-request-assign-intro-icon"><i class="fa fa-user-plus"></i></span>
            <div>
                <strong>分配说明</strong>
                <p>{{ __('product_requests.procurement.assign_subtitle', ['count' => $records->count()]) }}</p>
            </div>
        </div>
        <ul class="product-request-assign-records">
            @forelse($records as $record)
                <li>
                    <span class="product-request-assign-report">{{ $record->report_no }}</span>
                    <span class="product-request-assign-name">{{ $record->standard_chinese_name ?: __('product_requests.common.not_filled') }}</span>
                    @if($record->procurement_assignee_name)
                        <span class="product-request-assign-current">{{ __('product_requests.procurement.assign_current') }}：{{ $record->procurement_assignee_name }}</span>
                    @endif
                </li>
            @empty
                <li>{{ __('product_requests.common.no_records') }}</li>
            @endforelse
        </ul>
        <div class="form-group product-request-assignee-field">
            <div class="product-request-assignee-heading">
                <label>{{ __('product_requests.procurement.assignee_label') }} <span class="text-red">*</span></label>
                <span>请选择一名人员接收本次任务</span>
            </div>
            <div class="product-request-assignee-search">
                <i class="fa fa-search"></i>
                <input type="search" class="form-control" placeholder="搜索录入人员" autocomplete="off" data-product-request-assignee-search>
            </div>
            <div class="product-request-assignee-options" data-product-request-assignee-options>
                @forelse($assignees as $assignee)
                    <label class="product-request-assignee-option" data-product-request-assignee-option data-search-text="{{ $assignee['display_name'] }}">
                        <input type="radio" name="assignee_id" value="{{ $assignee['id'] }}" data-product-request-assignee-choice data-today-count="{{ $assignee['today_count'] }}" data-display-name="{{ $assignee['display_name'] }}" required>
                        <span class="product-request-assignee-avatar">{{ mb_substr(trim((string) $assignee['name']), 0, 1) ?: mb_substr(trim((string) $assignee['display_name']), 0, 1) }}</span>
                        <span class="product-request-assignee-copy">
                            <strong>{{ $assignee['display_name'] }}</strong>
                            <small>{{ __('product_requests.procurement.assignee_today_option', ['count' => $assignee['today_count']]) }}</small>
                        </span>
                        <i class="fa fa-check product-request-assignee-check"></i>
                    </label>
                @empty
                    <div class="product-request-assignee-empty">{{ __('product_requests.procurement.assignee_empty') }}</div>
                @endforelse
                <div class="product-request-assignee-empty" data-product-request-assignee-no-result hidden>没有匹配的录入人员</div>
            </div>
            <p class="help-block product-request-assignee-hint" data-product-request-assignee-hint hidden></p>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ __('product_requests.procurement.assign_confirm') }}</button>
    </div>
</form>
