@php
    $historyText = 'baokuan_general_advertising_expenses.';
    $historyUrl = route($operationRoute.'.index');
    $portalCode = $operationProject['code'];
    $canChangePageSize = $portalCode === 'baokuan-general';
    $pageSizeQuery = $canChangePageSize ? ['per_page' => $reports->perPage()] : [];
    $historyScalarQuery = array_filter(request()->only(['from', 'to', 'operator_user_id']), 'is_scalar') + $pageSizeQuery;
    $historyQuery = $historyScalarQuery + ($submitterIds ? ['submitter_ids' => $submitterIds] : []);
    $editorQuery = $historyQuery + [$reports->getPageName() => $reports->currentPage()];
    $lastPage = $reports->lastPage();
@endphp

<div class="flt baokuan-ad-history">
    <form method="get" action="{{ $historyUrl }}" class="flt-card flt-filters baokuan-ad-filters" data-loading="region" data-loading-target="#baokuan-ad-records">
        @if(isset($historyQuery['operator_user_id']))
            <input type="hidden" name="operator_user_id" value="{{ $historyQuery['operator_user_id'] }}">
        @endif
        @if($canChangePageSize)<input type="hidden" name="per_page" value="{{ $reports->perPage() }}">@endif
        <div class="flt-filter-grid{{ $canFilterSubmitters ? ' has-submitter-filter' : '' }}">
            <label>{{ __($historyText.'start') }}<input type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label>{{ __($historyText.'end') }}<input type="date" name="to" value="{{ $filters['to'] }}"></label>
            @if($canFilterSubmitters)
                @php
                    $selectedNames = $submitterOptions->only($submitterIds)->implode('、');
                    $allSubmittersLabel = __($historyText.'all_submitters');
                    $selectedLabel = $submitterIds
                        ? __('frontend_list_template.selected_options', ['names' => $selectedNames, 'count' => count($submitterIds)])
                        : $allSubmittersLabel;
                @endphp
                <div class="baokuan-ad-submitter-field">
                    <span class="flt-field-label" id="baokuan-ad-submitter-label">{{ __($historyText.'submitter') }}</span>
                    <details class="flt-picker" data-baokuan-submitters data-all="{{ $allSubmittersLabel }}"
                        data-selected-text="{{ __('frontend_list_template.selected_options', ['names' => '__names__', 'count' => '__count__']) }}">
                        <summary id="baokuan-ad-submitter-summary" aria-labelledby="baokuan-ad-submitter-label baokuan-ad-submitter-summary" title="{{ $selectedLabel }}">{{ $selectedLabel }}</summary>
                        <div class="flt-picker-panel">
                            <input type="search" data-baokuan-submitter-search autocomplete="off"
                                placeholder="{{ __('frontend_list_template.search_options') }}" aria-label="{{ __('frontend_list_template.search_options') }}">
                            <div class="flt-picker-options">
                                @foreach($submitterOptions as $id => $name)
                                    <label data-baokuan-submitter-option>
                                        <input type="checkbox" name="submitter_ids[]" value="{{ $id }}" @checked(in_array((int) $id, $submitterIds, true))>
                                        <span>{{ $name ?: '#'.$id }}</span>
                                    </label>
                                @endforeach
                                <p data-baokuan-submitter-empty @if($submitterOptions->isNotEmpty()) hidden @endif>{{ __('frontend_list_template.no_options') }}</p>
                            </div>
                            <div class="flt-picker-actions">
                                <button class="flt-picker-clear" type="button" data-baokuan-submitter-clear>{{ __('frontend_list_template.clear_choice') }}</button>
                            </div>
                        </div>
                    </details>
                </div>
            @endif
        </div>
        <div class="flt-filter-actions">
            <button type="submit" class="flt-btn flt-primary" data-loading-text="{{ __('frontend_list_template.loading') }}">
                <i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}
            </button>
            <a class="flt-btn" href="{{ route($operationRoute.'.index', $pageSizeQuery) }}" data-baokuan-reset>{{ __('frontend_list_template.reset') }}</a>
        </div>
    </form>

    <section class="flt-card flt-records" id="baokuan-ad-records" aria-label="{{ __($historyText.'history') }}"
        data-selection-user="{{ $user->getKey() }}" data-selection-portal="{{ $portalCode }}">
        <div class="flt-toolbar">
            <div class="flt-selection">
                <span>{{ __('frontend_list_template.records', ['count' => $reports->total()]) }}</span>
                <span class="flt-toolbar-divider" aria-hidden="true"></span>
                <span aria-live="polite" data-baokuan-selected-count data-text="{{ __('frontend_list_template.selected_records', ['count' => '__count__']) }}">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
                <button type="button" data-baokuan-clear-selection disabled>{{ __('frontend_list_template.clear_selected') }}</button>
            </div>
            <div class="flt-toolbar-actions">
                <a class="flt-btn flt-primary" href="{{ route($operationRoute.'.index', $editorQuery + ['fill' => 1]) }}"
                    @if(!$editing && !request()->filled('reuse')) data-baokuan-open-editor aria-haspopup="dialog" @endif>
                    <i class="fa fa-plus" aria-hidden="true"></i> {{ __($historyText.'fill_report') }}
                </a>
            </div>
        </div>

        <div class="flt-table-region">
            <div class="flt-table-scroll{{ $reports->isEmpty() ? ' is-empty' : '' }}" tabindex="0" role="region" aria-label="{{ __($historyText.'history') }}">
                @php
                    $columns = ['basic_info', 'media', 'agency', 'account_name', 'account_number', 'book_spend', 'coefficient', 'actual'];
                    if ($supportsMiscFeeRmb) $columns[] = 'misc_fee';
                    array_push($columns, 'screenshot', 'actions');
                @endphp
                <table class="baokuan-ad-table">
                    <colgroup>
                        <col class="baokuan-ad-col-check">
                        @foreach($columns as $column)<col class="baokuan-ad-col-{{ $column }}">@endforeach
                    </colgroup>
                    <thead><tr>
                        <th scope="col" class="flt-check"><input type="checkbox" data-baokuan-check-page aria-label="{{ __('frontend_list_template.check_page') }}" @disabled($reports->isEmpty())></th>
                        @foreach($columns as $column)
                            <th scope="col">{{ __($historyText.$column) }}</th>
                        @endforeach
                    </tr></thead>
                    @foreach($reports as $report)
                        @php
                            $accountCount = $report->adSpendEntries->count();
                            $accountRows = $accountCount ? $report->adSpendEntries : [null];
                            $span = max(1, $accountCount);
                            $statusTone = match($report->status) {
                                'approved' => 'active',
                                'rejected' => 'rejected',
                                default => 'pending',
                            };
                        @endphp
                        <tbody class="baokuan-ad-report-group">
                            @foreach($accountRows as $item)
                                <tr>
                                    @if($loop->first)
                                        <td rowspan="{{ $span }}" class="baokuan-ad-report-check">
                                            <input type="checkbox" data-baokuan-check-report value="{{ $report->id }}" aria-label="{{ __('frontend_list_template.check_record', ['id' => $report->id]) }}">
                                        </td>
                                        <th scope="rowgroup" rowspan="{{ $span }}" class="baokuan-ad-report-info">
                                            <dl class="baokuan-ad-report-meta">
                                                <dt>{{ __($historyText.'date') }}</dt><dd class="flt-time">{{ $report->report_date->format('Y-m-d') }}</dd>
                                                <dt>{{ __($historyText.'submitter') }}</dt><dd>{{ $report->submitter_name }}</dd>
                                                <dt>{{ __($historyText.'accounts') }}</dt><dd>{{ $accountCount }}</dd>
                                                <dt>{{ __($historyText.'actual_total') }}</dt><dd class="baokuan-ad-amount">${{ number_format($report->total_ad_spend_usd, 2) }}</dd>
                                                <dt>{{ __($historyText.'status') }}</dt>
                                                <dd><span class="flt-status" data-status="{{ $statusTone }}">{{ __($historyText.'statuses.'.$statusTone) }}</span></dd>
                                            </dl>
                                            @if($report->status === 'rejected')
                                                <p class="baokuan-ad-reason"><strong>{{ __($historyText.'rejection_reason') }}</strong><br>{{ $report->review_reason ?: '—' }}</p>
                                            @endif
                                        </th>
                                    @endif
                                    @if($item)
                                        <td>{{ $item->media ?: '—' }}</td>
                                        <td>{{ $item->agency ?: '—' }}</td>
                                        <td class="baokuan-ad-account-name">
                                            <strong>{{ $item->account_name ?: '—' }}</strong>
                                            @if(strtolower(trim((string) $item->media)) === 'applovin')
                                                <small>{{ __($historyText.'bound_store') }} · {{ $item->store_domain ?: __($historyText.'unbound') }}</small>
                                            @endif
                                        </td>
                                        <td class="baokuan-ad-account-number">{{ $item->account_number ?: '—' }}</td>
                                        <td class="baokuan-ad-money">${{ number_format($item->book_spend_usd, 2) }}</td>
                                        <td class="baokuan-ad-money">{{ number_format($item->settlement_coefficient, 4) }}</td>
                                        <td class="baokuan-ad-money baokuan-ad-amount">${{ number_format($item->actual_spend_usd, 2) }}</td>
                                        @if($supportsMiscFeeRmb)
                                            <td class="baokuan-ad-money">¥{{ number_format((float) $item->misc_fee_rmb, 2) }}</td>
                                        @endif
                                        <td>
                                            @if($item->screenshot_path)
                                                <img class="fashion-image-thumb erp-zoomable" src="{{ route($operationRoute.'.screenshot', ['type' => 'advertising', 'entry' => $item->id]) }}" alt="{{ __($historyText.'screenshot') }}" title="{{ __($historyText.'zoom') }}" loading="lazy">
                                            @else
                                                <span class="baokuan-ad-muted">{{ __($historyText.'no_screenshot') }}</span>
                                            @endif
                                        </td>
                                    @else
                                        <td colspan="{{ count($columns) - 2 }}" class="baokuan-ad-muted">{{ __($historyText.'no_accounts') }}</td>
                                    @endif
                                    @if($loop->first)
                                        <td rowspan="{{ $span }}" class="baokuan-ad-report-actions">
                                            <div class="baokuan-ad-actions">
                                                <a class="flt-row-action" href="{{ route($operationRoute.'.index', array_filter(['reuse' => $report->id, 'operator_user_id' => $isBoss ? $report->submitter_id : null]) + $editorQuery) }}">{{ __($historyText.'reuse') }}</a>
                                                @if(in_array($report->status, ['pending', 'approved', 'rejected'], true))
                                                    @if($portalCode === 'baokuan-general' && $report->status === 'approved')
                                                        <button class="flt-row-action" type="button" disabled title="{{ __($historyText.'approved_locked') }}" aria-label="{{ __($historyText.'approved_locked') }}">{{ __('frontend_list_template.edit') }}</button>
                                                    @else
                                                        <a class="flt-row-action" href="{{ route($operationRoute.'.index', ['edit' => $report->id] + $editorQuery) }}">{{ $report->status === 'rejected' ? __($historyText.'resubmit') : __('frontend_list_template.edit') }}</a>
                                                    @endif
                                                    <form method="post" action="{{ route($operationRoute.'.destroy', $report) }}" data-baokuan-delete="{{ __($historyText.'confirm_delete') }}">
                                                        @csrf
                                                        @method('delete')
                                                        <button type="submit" class="flt-row-action" data-action="delete">{{ __('frontend_list_template.delete') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
                @if($reports->isEmpty())
                    <div class="flt-empty">
                        <i class="fa fa-search" aria-hidden="true"></i>
                        <h4>{{ __('frontend_list_template.empty_title') }}</h4>
                        <p>{{ __('frontend_list_template.empty_hint') }}</p>
                    </div>
                @endif
            </div>
        </div>

            <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
                @if($canChangePageSize)
                <form method="get" action="{{ $historyUrl }}" data-loading="region" data-loading-target="#baokuan-ad-records">
                    @foreach(array_diff_key($historyScalarQuery, ['per_page' => true]) as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    @foreach($submitterIds as $id)
                        <input type="hidden" name="submitter_ids[]" value="{{ $id }}">
                    @endforeach
                    <label>{{ __('frontend_list_template.per_page') }}
                        <select name="per_page" data-baokuan-page-size aria-label="{{ __('frontend_list_template.page_size') }}">
                            @foreach([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($reports->perPage() === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                </form>
                @else
                    <span>{{ __($historyText.'per_page', ['count' => $reports->perPage()]) }}</span>
                @endif
                @if($reports->onFirstPage())
                    <button class="flt-btn" type="button" disabled>{{ __('frontend_list_template.previous') }}</button>
                @else
                    <a class="flt-btn" rel="prev" href="{{ route($operationRoute.'.index', $historyQuery + [$reports->getPageName() => $reports->currentPage() - 1]) }}">{{ __('frontend_list_template.previous') }}</a>
                @endif
                <strong>{{ __('frontend_list_template.page_label', ['page' => $reports->currentPage(), 'pages' => $lastPage]) }}</strong>
                @if($reports->hasMorePages())
                    <a class="flt-btn" rel="next" href="{{ route($operationRoute.'.index', $historyQuery + [$reports->getPageName() => $reports->currentPage() + 1]) }}">{{ __('frontend_list_template.next') }}</a>
                @else
                    <button class="flt-btn" type="button" disabled>{{ __('frontend_list_template.next') }}</button>
                @endif
                <form method="get" action="{{ $historyUrl }}" class="baokuan-ad-page-jump" data-loading="region" data-loading-target="#baokuan-ad-records">
                    @foreach($historyScalarQuery as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    @foreach($submitterIds as $id)
                        <input type="hidden" name="submitter_ids[]" value="{{ $id }}">
                    @endforeach
                    <label>{{ __('frontend_list_template.jump_to') }}
                        <input type="number" name="{{ $reports->getPageName() }}" min="1" max="{{ $lastPage }}" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}">
                    </label>
                    <button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button>
                </form>
            </nav>
    </section>
</div>

@push('scripts')
<script>
    document.getElementById('baokuan-ad-records').addEventListener('submit', (event) => {
        const message = event.target.dataset.baokuanDelete;
        if (message && !window.confirm(message)) event.preventDefault();
    }, true);
</script>
@endpush

