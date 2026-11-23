@extends('portal.layout')
@section('page_title', __('asset_accounts.title'))
@php
    $accountBase = url('/asset-manager/accounts');
    $config = ['base'=>$accountBase,'text'=>__('asset_accounts')];
    $options = ['base'=>$accountBase,'owner'=>$owner,'csrf'=>csrf_token()];
    $messages = array_merge(__('dingtalk.manage'),__('dingtalk.workspace'),__('dingtalk.company'),__('dingtalk.labels'));
    $pages = collect([1,2,$rows->currentPage()-1,$rows->currentPage(),$rows->currentPage()+1,$rows->lastPage()-1,$rows->lastPage()])->filter(fn($p)=>$p>=1 && $p<=$rows->lastPage())->unique()->sort()->values();
    $lastPage = null;
@endphp
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-reminders.css') }}?v=20261123-1">
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/dingtalk-bindings.css') }}?v=20261116-5">
<link rel="stylesheet" href="{{ asset('css/erp-account-labels.css') }}?v=20261116-1">
<link rel="stylesheet" href="{{ asset('css/dingtalk-companies.css') }}?v=20261116-4">
<link rel="stylesheet" href="{{ asset('assets/erp/asset-accounts.css') }}?v=20261118-2">
@endpush
@section('content')
<section class="content-header"><h1>{{ __('asset_accounts.title') }} <small>{{ __('asset_accounts.subtitle') }}</small></h1></section>
<section class="content hr-accounts">
    <div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __('asset_accounts.filters') }}</h3></div>
        <form method="get" action="{{ $accountBase }}" class="box-body">
            <div class="hr-filter-grid">
                <div class="form-group"><label for="hr-search">{{ __('asset_accounts.search') }}</label><input id="hr-search" class="form-control" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('asset_accounts.search_placeholder') }}"></div>
                <div class="form-group"><label for="hr-departments">{{ __('asset_accounts.department') }}</label><select id="hr-departments" class="form-control" name="department_ids[]" multiple>
                    @foreach($departments as $id=>$name)
                    <option value="{{ $id }}" @selected(in_array((string)$id,array_map('strval',$filters['department_ids'] ?? []),true))>{{ $name }}</option>
                    @endforeach
                </select></div>
                <div class="form-group"><label for="hr-employment">{{ __('asset_accounts.employment_status') }}</label><select id="hr-employment" class="form-control" name="employment_status"><option value="">{{ __('asset_accounts.all') }}</option>
                    @foreach(['employed','resigned'] as $status)
                    <option value="{{ $status }}" @selected(($filters['employment_status'] ?? '')===$status)>{{ __('asset_accounts.'.$status) }}</option>
                    @endforeach
                </select></div>
                <div class="form-group"><label for="hr-status">{{ __('asset_accounts.status') }}</label><select id="hr-status" class="form-control" name="status"><option value="">{{ __('asset_accounts.all') }}</option><option value="1" @selected((string)($filters['status']??'')==='1')>{{ __('asset_accounts.enabled') }}</option><option value="0" @selected((string)($filters['status']??'')==='0')>{{ __('asset_accounts.disabled') }}</option></select></div>
                <div class="form-group"><label for="hr-size">{{ __('asset_accounts.per_page') }}</label><select id="hr-size" class="form-control" name="per_page">
                    @foreach([20,50,100] as $size)
                    <option value="{{ $size }}" @selected($rows->perPage()===$size)>{{ $size }}</option>
                    @endforeach
                </select></div>
            </div>
            <div class="hr-toolbar"><button class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('asset_accounts.query') }}</button><a class="btn btn-default" href="{{ $accountBase }}">{{ __('asset_accounts.reset') }}</a></div>
        </form>
        <div class="box-footer hr-hint">{{ __('asset_accounts.scope_hint') }} @if(!$canManage) {{ __('asset_accounts.readonly') }} @endif</div>
    </div>
    <div class="box box-default"><div class="box-header with-border hr-list-head"><h3 class="box-title">{{ __('asset_accounts.list') }}</h3><div class="pull-right hr-list-buttons">
        @if($owner)
        <button class="btn btn-default btn-sm" type="button" data-ding-grants>{{ __('dingtalk.company.assign') }}</button>
        @endif
        <button type="button" class="btn btn-default btn-sm" data-hr-transfer="export">{{ __('asset_accounts.transfer.export') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-hr-transfer="selected">{{ __('asset_accounts.transfer.export_selected') }}</button>
        @if($canManage)
        <button type="button" class="btn btn-default btn-sm" data-hr-transfer="import">{{ __('asset_accounts.transfer.import') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-hr-transfer="company">{{ __('asset_accounts.transfer.company') }}</button>
        <button class="btn btn-primary btn-sm" id="hr-add" type="button"><i class="fa fa-plus" aria-hidden="true"></i> {{ __('asset_accounts.add') }}</button>
        @endif
    </div></div>
        <div class="table-responsive"><table class="table table-bordered table-hover hr-table"><thead><tr>
            <th><input type="checkbox" id="hr-select-all" aria-label="{{ __('asset_accounts.transfer.select_all') }}"></th><th><a href="{{ request()->fullUrlWithQuery(['direction'=>($filters['direction']??'desc')==='asc'?'desc':'asc','page'=>1]) }}">{{ __('asset_accounts.id') }} <i class="fa fa-sort" aria-hidden="true"></i></a></th>
            @foreach(['username','name','company','hired_at','employment_status','status','memberships','binding','updated_at','actions'] as $heading)
            <th>{{ __('asset_accounts.'.$heading) }}</th>
            @endforeach
        </tr></thead><tbody>
            @forelse($accounts as $account)
            <tr><td><input type="checkbox" data-hr-select="{{ $account['id'] }}" data-writable="{{ $account['writable'] ? 1 : 0 }}" aria-label="{{ $account['username'] }}"></td><td>{{ $account['id'] }}</td><td>{{ $account['username'] }}</td><td>{{ $account['name'] }} @if($account['administrator'])<span class="label label-info">{{ __('asset_accounts.system') }}</span>@endif</td><td>{{ $account['company'] }}</td><td>{{ $account['hired_at'] ?? '—' }}</td><td>{{ __('asset_accounts.'.($account['employment_status']==='resigned' ? 'resigned':'employed')) }} @if($account['resigned_at'])<small class="hr-date">{{ $account['resigned_at'] }}</small>@endif</td><td><span class="label {{ $account['status'] ? 'label-success':'label-default' }}">{{ __($account['status'] ? 'asset_accounts.enabled':'asset_accounts.disabled') }}</span></td>
                <td>
                    @forelse($account['memberships'] as $membership)
                    <span class="hr-tag">{{ $membership['department_name'] }} · {{ __('asset_accounts.'.$membership['position']) }}</span>
                    @empty
                    {{ __('asset_accounts.no_membership') }}
                    @endforelse
                </td><td><span data-binding-labels="{{ $account['id'] }}">
                    @forelse($account['bindings'] as $binding)
                    <span class="hr-tag">{{ $binding }}</span>
                    @empty
                    {{ __('asset_accounts.no_binding') }}
                    @endforelse
                </span></td><td>{{ $account['updated_at'] }}</td><td class="hr-actions">
                    <button type="button" class="btn btn-default btn-xs" data-hr-view="{{ $account['id'] }}">{{ __('asset_accounts.view') }}</button>
                    @if($account['writable'])
                    <button type="button" class="btn btn-primary btn-xs" data-hr-edit="{{ $account['id'] }}">{{ __('asset_accounts.edit') }}</button>
                    @if(!$account['administrator'])
                    <button type="button" class="btn btn-danger btn-xs" data-hr-delete="{{ $account['id'] }}">{{ __('asset_accounts.delete') }}</button>
                    @endif
                    @endif
                    @if($account['can_bind'])
                    <button type="button" class="btn btn-default btn-xs" data-ding-account="{{ $account['id'] }}">{{ __('asset_accounts.binding') }}</button>
                    @endif
                    @if($user->isAdministrator() && !$account['administrator'])
                    <button type="button" class="btn btn-info btn-xs" data-hr-permissions="{{ $account['id'] }}">{{ __('asset_accounts.permissions') }}</button>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="12" class="hr-empty">{{ __('asset_accounts.empty') }}</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
    <div class="hr-pagination"><span>{{ __('asset_accounts.total',['from'=>$rows->firstItem()??0,'to'=>$rows->lastItem()??0,'total'=>$rows->total()]) }}</span>
        @if($rows->hasPages())
        <ul class="pagination pagination-sm no-margin">
            @if($rows->previousPageUrl())<li><a href="{{ $rows->previousPageUrl() }}" aria-label="{{ __('asset_accounts.previous') }}">&laquo;</a></li>@endif
            @foreach($pages as $page)
                @if($lastPage!==null && $page-$lastPage>1)<li class="disabled"><span>&hellip;</span></li>@endif
                <li class="{{ $page===$rows->currentPage() ? 'active':'' }}"><a href="{{ $rows->url($page) }}">{{ $page }}</a></li>
                @php
                    $lastPage=$page;
                @endphp
            @endforeach
            @if($rows->nextPageUrl())<li><a href="{{ $rows->nextPageUrl() }}" aria-label="{{ __('asset_accounts.next') }}">&raquo;</a></li>@endif
        </ul>
        @endif
    </div>
</section>
<div class="modal fade hr-modal" id="hr-modal" tabindex="-1" role="dialog" aria-labelledby="hr-modal-title"><div class="modal-dialog modal-lg"><div class="modal-content"><form id="hr-form" novalidate data-loading="off">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('asset_accounts.close') }}">&times;</button><h4 class="modal-title" id="hr-modal-title"></h4></div>
    <div class="modal-body" id="hr-modal-body"><div class="alert alert-danger" id="hr-error" role="alert" hidden></div><div id="hr-fields"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('asset_accounts.close') }}</button><button type="submit" class="btn btn-primary" id="hr-save">{{ __('asset_accounts.save') }}</button></div>
</form></div></div></div>
<dialog id="hr-transfer-dialog" class="ar-dialog" aria-labelledby="hr-transfer-title"><form id="hr-transfer-form" novalidate data-loading="off"><header><h3 id="hr-transfer-title"></h3><button type="button" data-transfer-close class="close" aria-label="{{ __('asset_accounts.close') }}">&times;</button></header><div class="ar-modal-body"><div id="hr-transfer-error" class="alert alert-danger" hidden></div><div id="hr-transfer-fields"></div></div><footer><button type="button" data-transfer-close class="btn btn-default">{{ __('asset_accounts.close') }}</button><button type="submit" id="hr-transfer-save" class="btn btn-primary">{{ __('asset_accounts.save') }}</button></footer></form></dialog>
@include('admin.organization.dingtalk-modals',compact('owner','options','messages'))
@endsection
@push('scripts')
<script>window.AssetAccountsConfig=@json($config);</script>
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script src="{{ asset('js/erp-account-labels.js') }}?v=20261116-2"></script>
<script src="{{ asset('js/dingtalk-directory.js') }}?v=20261116-2"></script>
<script src="{{ asset('js/dingtalk-bindings.js') }}?v=20261116-2"></script>
<script src="{{ asset('assets/erp/asset-accounts.js') }}?v=20261118-2"></script>
<script src="{{ asset('assets/erp/asset-account-transfer.js') }}?v=20261123-1"></script>
@endpush
