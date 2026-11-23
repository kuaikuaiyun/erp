@extends('portal.layout')
@section('page_title', __('asset_accounts.department_title'))
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-reminders.css') }}?v=20261123-1">
<link rel="stylesheet" href="{{ asset('assets/erp/asset-departments.css') }}?v=20261123-3">
@endpush
@section('content')
<section class="content-header"><h1>{{ __('asset_accounts.department_title') }} <small>{{ __('asset_accounts.department_subtitle') }}</small></h1></section>
<section class="content ar-workspace hd-workspace">
    <div class="box box-default"><div class="box-header with-border"><h3 class="box-title">{{ __('asset_accounts.filters') }}</h3></div><form class="box-body hd-filter" method="get">
        <label>{{ __('asset_accounts.department_list_search') }}<input class="form-control" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></label>
        <label>{{ __('asset_accounts.department_group_size') }}<select class="form-control" name="per_page">@foreach([20,50,100] as $size)<option @selected($rows->perPage()===$size)>{{ $size }}</option>@endforeach</select></label>
        <button class="btn btn-primary">{{ __('asset_accounts.query') }}</button><a class="btn btn-default" href="{{ url('/asset-manager/departments') }}">{{ __('asset_accounts.reset') }}</a>
    </form></div>
    <div class="box box-default"><div class="table-responsive"><table class="table table-hover hd-table"><thead><tr>
        <th>{{ __('asset_accounts.department_fields.name') }}</th><th>{{ __('asset_accounts.department_fields.short_name') }}</th><th>{{ __('asset_accounts.department_structure') }}</th><th>{{ __('asset_accounts.updated_at') }}</th><th>{{ __('asset_accounts.actions') }}</th>
    </tr></thead><tbody>
    @forelse($treeGroups as $group)
        @if($group['root'])
            @include('portals.asset-manager.partials.department-row', ['row'=>$group['root'],'groupId'=>$group['id'],'isChild'=>false,'hasChildren'=>$group['children']->isNotEmpty()])
        @elseif($group['context'] && $group['children']->isNotEmpty())
            <tr class="hd-context"><td colspan="5"><button type="button" class="hd-tree-toggle" data-department-toggle="{{ $group['id'] }}" aria-expanded="{{ filled($filters['search']??null)?'true':'false' }}" aria-label="{{ __('asset_accounts.department_expand') }}"><i class="fa fa-chevron-right" aria-hidden="true"></i></button><strong>{{ $group['context']->name }}</strong><span class="hd-level">{{ __('asset_accounts.department_context') }}</span></td></tr>
        @endif
        @foreach($group['children'] as $row)
            @include('portals.asset-manager.partials.department-row', ['row'=>$row,'groupId'=>$group['id'],'isChild'=>true,'hasChildren'=>false])
        @endforeach
    @empty<tr><td colspan="5" class="ar-empty">{{ __('asset_accounts.empty') }}</td></tr>@endforelse
    </tbody></table></div>
    <div class="box-footer ar-pagination"><span>{{ __('asset_accounts.department_group_total',['from'=>$rows->firstItem()??0,'to'=>$rows->lastItem()??0,'total'=>$rows->total()]) }}</span><div>@if($rows->previousPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->previousPageUrl() }}">{{ __('asset_accounts.previous') }}</a>@endif {{ $rows->currentPage() }} / {{ $rows->lastPage() }} @if($rows->nextPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->nextPageUrl() }}">{{ __('asset_accounts.next') }}</a>@endif</div></div></div>
</section>
<dialog id="hr-department-dialog" class="ar-dialog" aria-labelledby="hr-department-title"><form id="hr-department-form" novalidate data-loading="off"><header><h3 id="hr-department-title"></h3><button type="button" class="close" data-department-close aria-label="{{ __('asset_accounts.close') }}">&times;</button></header><div class="ar-modal-body"><div id="hr-department-error" class="alert alert-danger" hidden></div><div id="hr-department-fields"></div></div><footer><button type="button" class="btn btn-default" data-department-close>{{ __('asset_accounts.close') }}</button><button type="submit" id="hr-department-save" class="btn btn-primary">{{ __('asset_accounts.save') }}</button></footer></form></dialog>
@endsection
@push('scripts')
<script>window.AssetDepartmentsConfig=@json(['base'=>url('/asset-manager/departments'),'text'=>__('asset_accounts')]);</script>
<script src="{{ asset('assets/erp/asset-departments.js') }}?v=20261123-3"></script>
@endpush
