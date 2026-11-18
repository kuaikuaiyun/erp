@php
    $computerOwners = array_intersect_key($ownerDepartments, array_flip($writableDepartmentIds));
    $pageNumbers = collect([1, 2, $rows->currentPage()-1, $rows->currentPage(), $rows->currentPage()+1, $rows->lastPage()-1, $rows->lastPage()])->filter(fn ($page) => $page >= 1 && $page <= $rows->lastPage())->unique()->sort()->values();
    $lastVisiblePage = null;
@endphp
<section class="content">
<div class="am-workspace am-computers" id="asset-manager">
    <div id="am-notice" role="status"></div>
    <div class="am-computer-summary" role="status">
        <div class="am-computer-card"><span class="am-computer-icon"><i class="fa fa-desktop" aria-hidden="true"></i></span><div><span>{{ __('asset_manager.total_assets') }}</span><strong>{{ number_format($cards['total_assets']) }}</strong></div></div>
        <div class="am-computer-card"><span class="am-computer-icon"><i class="fa fa-cny" aria-hidden="true"></i></span><div><span>{{ __('asset_manager.rent_total') }}</span><strong>{{ __('asset_manager.computer_ui.currency_prefix') }}{{ $cards['rent_total'] }}</strong>
            @if(($cards['unknown_rent'] ?? 0) > 0)
            <small>{{ __('asset_manager.unknown_rent', ['count'=>$cards['unknown_rent']]) }}</small>
            @endif
        </div></div>
    </div>
    <div class="box box-default am-computer-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __('asset_manager.computer_ui.filters') }}</h3></div>
        <form method="get" action="{{ route($routePrefix.'.index', $kind) }}" id="am-filters" class="box-body" data-loading="region" data-loading-target=".am-computers">
            <input type="hidden" name="per_page" value="{{ $rows->perPage() }}">
            <div class="am-computer-filter-grid">
                @foreach($fields as $key=>$type)
                <div class="form-group"><label for="am-filter-{{ $key }}">{{ $labels[$key] }}</label>
                    @if(in_array($type, ['user','department']))
                    <select id="am-filter-{{ $key }}" class="form-control am-computer-search-select" name="filter[{{ $key }}]">
                        <option value="">{{ __('asset_manager.all') }}</option>
                        @if($key==='owner_department_id' && $user->isAdministrator())
                        <option value="0" @selected((string)request('filter.owner_department_id')==='0')>{{ __('asset_manager.computer_unassigned') }}</option>
                        @endif
                        @foreach($key==='owner_department_id' ? $ownerDepartments : $users as $id=>$label)
                        <option value="{{ $id }}" @selected((string)request('filter.'.$key)===(string)$id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @else
                    <input id="am-filter-{{ $key }}" class="form-control" name="filter[{{ $key }}]" type="{{ $type==='money' ? 'number' : 'text' }}" @if($type==='money') step="0.01" @endif value="{{ request('filter.'.$key) }}" placeholder="{{ __('asset_manager.computer_ui.search_placeholder', ['key'=>$labels[$key]]) }}">
                    @endif
                </div>
                @endforeach
            </div>
            <div class="am-computer-filter-bottom">
                @foreach(['from','to'] as $dateKey)
                <div class="form-group"><label for="am-{{ $dateKey }}">{{ __('asset_manager.'.$dateKey) }}</label><input id="am-{{ $dateKey }}" class="form-control" type="date" name="{{ $dateKey }}" value="{{ request($dateKey) }}"></div>
                @endforeach
                <div class="am-computer-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('asset_manager.search') }}</button>
                    <button type="button" class="btn btn-success" id="am-export"><i class="fa fa-download" aria-hidden="true"></i> {{ __('asset_manager.export') }}</button>
                    <a class="btn btn-default" href="{{ route($routePrefix.'.index', $kind) }}">{{ __('asset_manager.reset') }}</a>
                </div>
            </div>
        </form>
        <div class="box-footer text-muted am-computer-hint">{{ __('asset_manager.expired_hint') }} {{ __($canManage ? 'asset_manager.computer_ui.manage_hint' : 'asset_manager.computer_ui.read_only') }}</div>
    </div>
    <div class="box box-default am-computer-list-box">
        <div class="am-computer-list-toolbar">
            <h3 class="box-title">{{ __('asset_manager.computer_ui.list') }}</h3>
            <div class="am-computer-list-actions">
                @if($canManage)
                <button class="btn btn-primary btn-sm" id="am-add"><i class="fa fa-plus" aria-hidden="true"></i> {{ __('asset_manager.add') }}</button>
                <button class="btn btn-default btn-sm" id="am-import"><i class="fa fa-upload" aria-hidden="true"></i> {{ __('asset_manager.import') }}</button>
                <a class="btn btn-default btn-sm" href="{{ route($routePrefix.'.export', ['kind'=>$kind, 'template'=>1]) }}"><i class="fa fa-file-excel-o" aria-hidden="true"></i> {{ __('asset_manager.template') }}</a>
                @endif
                <button class="btn btn-success btn-sm" id="am-export-selected"><i class="fa fa-download" aria-hidden="true"></i> {{ __('asset_manager.export_selected') }}</button>
            </div>
        </div>
        <div class="am-table-wrap am-computer-table-scroll">
            <table class="table table-bordered table-hover am-computer-table">
                <thead><tr><th class="am-computer-select"><input type="checkbox" id="am-select-all" aria-label="{{ __('asset_manager.all') }}"></th>
                    @foreach($fields as $key=>$type)
                    <th>{{ $labels[$key] }}</th>
                    @endforeach
                    <th>{{ $labels['created_at'] }}</th><th>{{ __('asset_manager.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $row)
                    <tr><td class="am-computer-select"><input type="checkbox" class="am-selected" value="{{ $row->id }}" aria-label="{{ __('asset_manager.record_id') }} {{ $row->id }}"></td>
                        @foreach($fields as $key=>$type)
                        <td class="{{ $key==='monthly_rent' ? 'am-computer-money' : '' }}">{{ $key==='owner_department_id' ? ($ownerDepartments[$row->owner_department_id] ?? __('asset_manager.computer_unassigned')) : ($service->display($row, $key, $type) !== '' ? $service->display($row, $key, $type) : '—') }}</td>
                        @endforeach
                        <td class="am-computer-time">{{ $row->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td>
                        <td class="am-actions"><button class="btn btn-default btn-xs" data-view="{{ $row->id }}"><i class="fa fa-eye" aria-hidden="true"></i> {{ __('asset_manager.view') }}</button>
                            @if($canManage && ($user->isAdministrator() || in_array((int)$row->owner_department_id, $writableDepartmentIds, true)))
                            <button class="btn btn-primary btn-xs" data-edit="{{ $row->id }}"><i class="fa fa-pencil" aria-hidden="true"></i> {{ __('asset_manager.edit') }}</button>
                            <button class="btn btn-danger btn-xs" data-delete="{{ $row->id }}"><i class="fa fa-trash-o" aria-hidden="true"></i> {{ __('asset_manager.delete') }}</button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ count($fields)+3 }}" class="am-computer-empty">{{ __('asset_manager.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="am-computer-pagination" data-loading="region" data-loading-target=".am-computers">
        <span>{{ __('asset_manager.computer_ui.pagination_summary', ['from'=>$rows->firstItem() ?? 0, 'to'=>$rows->lastItem() ?? 0, 'total'=>$rows->total()]) }}</span>
        @if($rows->lastPage()>1)
        <nav aria-label="{{ __('asset_manager.computer_ui.pagination_navigation') }}"><ul class="pagination pagination-sm no-margin">
            <li class="{{ $rows->onFirstPage() ? 'disabled' : '' }}">
                @if($rows->onFirstPage())
                <span aria-label="{{ __('asset_manager.previous') }}">&laquo;</span>
                @else
                <a href="{{ $rows->previousPageUrl() }}" aria-label="{{ __('asset_manager.previous') }}">&laquo;</a>
                @endif
            </li>
            @foreach($pageNumbers as $pageNumber)
                @if($lastVisiblePage!==null && $pageNumber-$lastVisiblePage>1)
                <li class="disabled"><span>&hellip;</span></li>
                @endif
                <li class="{{ $rows->currentPage()===$pageNumber ? 'active' : '' }}"><a href="{{ $rows->url($pageNumber) }}" @if($rows->currentPage()===$pageNumber) aria-current="page" @endif>{{ $pageNumber }}</a></li>
                @php
                    $lastVisiblePage=$pageNumber;
                @endphp
            @endforeach
            <li class="{{ $rows->hasMorePages() ? '' : 'disabled' }}">
                @if($rows->hasMorePages())
                <a href="{{ $rows->nextPageUrl() }}" aria-label="{{ __('asset_manager.next') }}">&raquo;</a>
                @else
                <span aria-label="{{ __('asset_manager.next') }}">&raquo;</span>
                @endif
            </li>
        </ul></nav>
        @endif
        <form method="get" action="{{ route($routePrefix.'.index', $kind) }}" class="am-computer-per-page">
            @foreach($fields as $key=>$type)
            <input type="hidden" name="filter[{{ $key }}]" value="{{ request('filter.'.$key) }}">
            @endforeach
            <input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}">
            <label>{{ __('asset_manager.computer_ui.per_page') }} <select name="per_page" class="form-control input-sm" onchange="this.form.submit()">
                @foreach([20,50,100] as $size)
                <option value="{{ $size }}" @selected($rows->perPage()===$size)>{{ $size }}</option>
                @endforeach
            </select> {{ __('asset_manager.computer_ui.records_suffix') }}</label>
        </form>
    </div>
<div class="modal fade" id="am-modal" tabindex="-1" role="dialog" aria-labelledby="am-modal-title"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button class="close" data-dismiss="modal" aria-label="{{ __('asset_manager.cancel') }}">×</button><h4 id="am-modal-title"></h4></div><form id="am-form"><div class="modal-body"><div id="am-errors" role="alert"></div><div class="am-grid" id="am-fields"></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('asset_manager.cancel') }}</button><button class="btn btn-primary" id="am-save">{{ __('asset_manager.save') }}</button></div></form></div></div></div>
<div class="modal fade" id="am-import-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h4>{{ __('asset_manager.import') }}</h4></div><div class="modal-body"><p>{{ __('asset_manager.import_help') }}</p>
<label>{{ __('asset_manager.fields.owner_department_id') }}<select class="form-control" id="am-import-owner">
<option value="">{{ __('asset_manager.choose_owner') }}</option>
@foreach($computerOwners as $id=>$label)<option value="{{ $id }}" @selected(count($computerOwners)===1)>{{ $label }}</option>@endforeach
</select></label><p>{{ __($kind==='computers'?'asset_manager.computer_import_owner_help':'asset_manager.import_owner_help') }}</p>
<input type="file" id="am-file" accept=".xlsx,.csv"><div id="am-import-result" role="status"></div></div><div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">{{ __('asset_manager.cancel') }}</button><button class="btn btn-default" id="am-preview">{{ __('asset_manager.preview') }}</button><button class="btn btn-primary" id="am-import-confirm" disabled>{{ __('asset_manager.confirm_import') }}</button></div></div></div></div>
</div>
</section>
@push('scripts')
@php
$clientConfig = ['kind'=>$kind,'fields'=>$fields,'users'=>$users,'departments'=>$departments,'ownerDepartments'=>$ownerDepartments,'records'=>$rows->getCollection()->keyBy('id'),'accounts'=>$accounts->keyBy('id'),'text'=>array_replace(__('asset_manager'),['fields'=>$labels]),'base'=>$assetBase,'csrf'=>csrf_token()];
@endphp
<script>window.AssetManagerConfig=@json($clientConfig);</script>
<script src="{{ asset('assets/erp/asset-manager.js') }}?v=20261118-2"></script>
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script>
(function ($) {
    var writableDepartments = @json(array_values($writableDepartmentIds));
    var pickerText = window.AssetManagerConfig.text.computer_ui;
    if ($.fn.select2) {
        $('.am-computers .am-computer-search-select').each(function () {
            var select = $(this);
            select.select2({
                width: '100%',
                minimumResultsForSearch: 0,
                dropdownCssClass: 'am-computer-select-dropdown',
                language: {
                    noResults: function () { return pickerText.no_results; },
                    searching: function () { return pickerText.searching; },
                    errorLoading: function () { return pickerText.load_error; }
                }
            });
            select.on('select2:open', function () {
                $('.am-computer-select-dropdown .select2-search__field')
                    .attr('placeholder', pickerText.option_search)
                    .attr('aria-label', pickerText.option_search);
            });
        });
    }
    $('#am-modal').on('show.bs.modal', function () {
        var owner = this.querySelector('select[name="owner_department_id"]');
        if (!owner || owner.disabled) return;
        Array.from(owner.options).forEach(function (option) {
            var blocked = option.value !== '' && writableDepartments.indexOf(Number(option.value)) < 0;
            option.disabled = blocked;
            option.hidden = blocked;
        });
        if (owner.value !== '' && writableDepartments.indexOf(Number(owner.value)) < 0) owner.value = '';
        if (!owner.value && writableDepartments.length === 1) owner.value = String(writableDepartments[0]);
    });
})(window.jQuery);
</script>
@endpush
