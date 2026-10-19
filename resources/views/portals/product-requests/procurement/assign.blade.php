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
        <p class="help-block">{{ __('product_requests.procurement.assign_subtitle', ['count' => $records->count()]) }}</p>
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
        <div class="form-group">
            <label for="product-request-assignee-select">{{ __('product_requests.procurement.assignee_label') }}</label>
            <select class="form-control" name="assignee_id" id="product-request-assignee-select" data-product-request-assignee-select required>
                <option value="">{{ __('product_requests.procurement.assignee_placeholder') }}</option>
                @forelse($assignees as $assignee)
                    <option value="{{ $assignee['id'] }}" data-today-count="{{ $assignee['today_count'] }}" data-display-name="{{ $assignee['display_name'] }}">
                        {{ $assignee['display_name'] }}（{{ __('product_requests.procurement.assignee_today_option', ['count' => $assignee['today_count']]) }}）
                    </option>
                @empty
                    <option value="" disabled>{{ __('product_requests.procurement.assignee_empty') }}</option>
                @endforelse
            </select>
            <p class="help-block product-request-assignee-hint" data-product-request-assignee-hint hidden></p>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button>
        <button type="submit" class="btn btn-primary">{{ __('product_requests.procurement.assign_confirm') }}</button>
    </div>
</form>
