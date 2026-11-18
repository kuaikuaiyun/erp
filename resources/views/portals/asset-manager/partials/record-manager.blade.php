@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-manager.css') }}?v=20261118-2">
@endpush
<div class="am-workspace" id="asset-manager">
<h2>{{ __('asset_manager.titles.'.$kind) }}</h2><p class="text-muted">{{ __('asset_manager.expired_hint') }}</p>
<div id="am-notice" role="status"></div>
@if($kind==='ipfoxy')
<div class="am-toolbar">@if($canManage)<button class="btn btn-primary" id="am-add-account">{{ __('asset_manager.add') }}</button><button class="btn btn-default" id="am-sync-all">{{ __('asset_manager.sync_all') }}</button>
@endif
</div>
<div class="am-cards">@forelse($accounts as $account)<section class="am-card"><strong>{{ $account->label }}</strong><p>{{ __('asset_manager.fields.owner_department_id') }}: {{ $ownerDepartments[$account->owner_department_id] ?? '—' }}</p><p>{{ __('asset_manager.last_sync') }}: {{ $account->last_synced_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? __('asset_manager.never_synced') }}</p><p>{{ $account->last_status==='failed'?__('asset_manager.sync_failed'):($account->last_status==='success'?__('asset_manager.ready'):__('asset_manager.never_synced')) }}</p><div class="am-sync-status" data-account-status="{{ $account->id }}"></div><button class="btn btn-default btn-sm" data-account-view="{{ $account->id }}">{{ __('asset_manager.view') }}</button>@if($canManage)<button class="btn btn-default btn-sm" data-account-edit="{{ $account->id }}">{{ __('asset_manager.edit') }}</button><button class="btn btn-primary btn-sm" data-sync="{{ $account->id }}">{{ __('asset_manager.sync') }}</button><button class="btn btn-danger btn-sm" data-account-delete="{{ $account->id }}">{{ __('asset_manager.delete') }}</button>
@endif
</section>
@empty
<p>{{ __('asset_manager.account_required') }}</p>
@endforelse
</div>

@endif

@if($cards)<div class="am-cards">@foreach($cards as $key=>$value)@if($key!=='unknown_rent')<a class="am-card" href="{{ in_array($key,['expired','today','upcoming','unset']) ? request()->fullUrlWithQuery(['reminder'=>$key,'page'=>1]) : request()->fullUrlWithQuery(['reminder'=>null,'page'=>1]) }}"><span>{{ __('asset_manager.'.$key) }}</span><strong>{{ $value }}</strong>@if($key==='rent_total' && ($cards['unknown_rent']??0)>0)<small>{{ __('asset_manager.unknown_rent',['count'=>$cards['unknown_rent']]) }}</small>
@endif
</a>
@endif

@endforeach
</div>
@endif

<form method="get" id="am-filters" class="am-panel"><div class="am-grid">
@foreach($fields as $key=>$type)<label>{{ $labels[$key] }}
@if(in_array($type,['user','department']))<input class="form-control am-option-search" placeholder="{{ __('asset_manager.search') }}"><select class="form-control" name="filter[{{ $key }}]"><option value="">{{ __('asset_manager.all') }}</option>
@if($kind==='computers' && $key==='owner_department_id' && $user->isAdministrator())
<option value="0" @selected(request('filter.owner_department_id')==='0')>{{ __('asset_manager.computer_unassigned') }}</option>
@endif
@foreach($key==='owner_department_id'?$ownerDepartments:($type==='user'?$users:$departments) as $id=>$label)<option value="{{ $id }}" @selected((string)request('filter.'.$key)===(string)$id)>{{ $label }}</option>
@endforeach
</select>

@else
<input class="form-control" name="filter[{{ $key }}]" type="{{ in_array($type,['date','datetime'])?'date':($type==='money'?'number':'text') }}" @if($type==='money')step="0.01"
@endif
 value="{{ request('filter.'.$key) }}">
@endif
</label>
@endforeach

@if($kind!=='ipfoxy')@foreach(['from','to'] as $dateKey)<label>{{ __('asset_manager.'.$dateKey) }}<input class="form-control" type="date" name="{{ $dateKey }}" value="{{ request($dateKey) }}"></label>
@endforeach
 
@endif

@if($kind==='codex-renewals')@foreach(['renewal_from','renewal_to'] as $dateKey)<label>{{ __('asset_manager.'.$dateKey) }}<input class="form-control" type="date" name="{{ $dateKey }}" value="{{ request($dateKey) }}"></label>
@endforeach
<label>{{ __('asset_manager.status') }}<select class="form-control" name="reminder"><option value="">{{ __('asset_manager.all') }}</option>@foreach(['expired','today','upcoming','unset'] as $status)<option value="{{ $status }}" @selected(request('reminder')===$status)>{{ __('asset_manager.'.$status) }}</option>
@endforeach
</select></label>
@endif

<label>{{ __('asset_manager.per_page') }}<select class="form-control" name="per_page">@foreach([20,50,100] as $size)<option @selected($rows->perPage()===$size)>{{ $size }}</option>
@endforeach
</select></label></div><button class="btn btn-primary">{{ __('asset_manager.search') }}</button> <a class="btn btn-default" href="{{ route($routePrefix.'.index',$kind) }}">{{ __('asset_manager.reset') }}</a></form>
<div class="am-toolbar">@if($canManage && $kind!=='ipfoxy')<button class="btn btn-primary" id="am-add">{{ __('asset_manager.add') }}</button><button class="btn btn-default" id="am-import">{{ __('asset_manager.import') }}</button>
@endif
 @if($kind!=='ipfoxy')<a class="btn btn-default" href="{{ route($routePrefix.'.export',['kind'=>$kind,'template'=>1]) }}">{{ __('asset_manager.template') }}</a>
@endif
<button class="btn btn-default" id="am-export">{{ __('asset_manager.export') }}</button><button class="btn btn-default" id="am-export-selected">{{ __('asset_manager.export_selected') }}</button></div>
<div class="am-panel am-table-wrap"><table class="table table-bordered table-hover"><thead><tr><th><input type="checkbox" id="am-select-all" aria-label="{{ __('asset_manager.all') }}"></th>@foreach($fields as $key=>$type)<th>{{ $labels[$key] }}</th>
@endforeach
 @if($kind!=='ipfoxy')<th>{{ $labels['created_at'] }}</th>
@endif
 @if($kind==='codex-renewals')<th>{{ __('asset_manager.remaining') }}</th>
@endif
<th>{{ __('asset_manager.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><input type="checkbox" class="am-selected" value="{{ $row->id }}" aria-label="{{ __('asset_manager.record_id') }} {{ $row->id }}"></td>@foreach($fields as $key=>$type)<td>{{ $key==='owner_department_id' ? ($ownerDepartments[$row->owner_department_id] ?? ($kind==='computers'?__('asset_manager.computer_unassigned'):'—')) : ($kind==='ipfoxy' ? ($key==='expires_at'?($row->expires_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s')??'—'):($row->$key??'—')) : ($service->display($row,$key,$type) !== '' ? $service->display($row,$key,$type) : '—')) }}</td>
@endforeach

@if($kind!=='ipfoxy')<td>{{ $row->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td>
@endif

@if($kind==='codex-renewals')<td>@if($row->renewal_date)@php
$days=(int) \Carbon\CarbonImmutable::now('Asia/Shanghai')->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($row->renewal_date->format('Y-m-d'),'Asia/Shanghai'),false);
@endphp
<span class="label {{ $days<0?'label-danger':($days<=7?'label-warning':'label-success') }}">{{ $days<0?__('asset_manager.overdue_days',['days'=>abs($days)]):($days===0?__('asset_manager.today'):__('asset_manager.days',['days'=>$days])) }}</span>
@else
{{ __('asset_manager.unset') }}
@endif
</td>
@endif

<td class="am-actions"><button class="btn btn-default btn-sm" data-view="{{ $row->id }}">{{ __('asset_manager.view') }}</button>@if($kind==='ipfoxy' && $row->missing)<span class="label label-warning">{{ __('asset_manager.missing_proxy') }}</span>
@endif
 @if($canManage && $kind!=='ipfoxy')<button class="btn btn-default btn-sm" data-edit="{{ $row->id }}">{{ __('asset_manager.edit') }}</button><button class="btn btn-danger btn-sm" data-delete="{{ $row->id }}">{{ __('asset_manager.delete') }}</button>
@endif
</td></tr>
@empty
<tr><td colspan="20">{{ __('asset_manager.empty') }}</td></tr>
@endforelse

</tbody></table></div><div class="am-toolbar"><span>{{ __('asset_manager.total',['count'=>$rows->total()]) }} · {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>@if($rows->previousPageUrl())<a class="btn btn-default" href="{{ $rows->previousPageUrl() }}">{{ __('asset_manager.previous') }}</a>
@endif
 @if($rows->nextPageUrl())<a class="btn btn-default" href="{{ $rows->nextPageUrl() }}">{{ __('asset_manager.next') }}</a>
@endif
</div>
<div class="modal fade" id="am-modal" tabindex="-1" role="dialog" aria-labelledby="am-modal-title"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button class="close" data-dismiss="modal" aria-label="{{ __('asset_manager.cancel') }}">×</button><h4 id="am-modal-title"></h4></div><form id="am-form"><div class="modal-body"><div id="am-errors" role="alert"></div><div class="am-grid" id="am-fields"></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('asset_manager.cancel') }}</button><button class="btn btn-primary" id="am-save">{{ __('asset_manager.save') }}</button></div></form></div></div></div>
<div class="modal fade" id="am-import-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h4>{{ __('asset_manager.import') }}</h4></div><div class="modal-body"><p>{{ __('asset_manager.import_help') }}</p>
<label>{{ __('asset_manager.fields.owner_department_id') }}<select class="form-control" id="am-import-owner">
<option value="">{{ __('asset_manager.choose_owner') }}</option>
@foreach($ownerDepartments as $id=>$label)<option value="{{ $id }}" @selected(count($ownerDepartments)===1)>{{ $label }}</option>@endforeach
</select></label><p>{{ __($kind==='computers'?'asset_manager.computer_import_owner_help':'asset_manager.import_owner_help') }}</p>
<input type="file" id="am-file" accept=".xlsx,.csv"><div id="am-import-result" role="status"></div></div><div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">{{ __('asset_manager.cancel') }}</button><button class="btn btn-default" id="am-preview">{{ __('asset_manager.preview') }}</button><button class="btn btn-primary" id="am-import-confirm" disabled>{{ __('asset_manager.confirm_import') }}</button></div></div></div></div>
</div>
@push('scripts')
@php
$clientConfig = ['kind'=>$kind,'fields'=>$fields,'users'=>$users,'departments'=>$departments,'ownerDepartments'=>$ownerDepartments,'records'=>$rows->getCollection()->keyBy('id'),'accounts'=>$accounts->keyBy('id'),'text'=>array_replace(__('asset_manager'),['fields'=>$labels]),'base'=>$assetBase,'csrf'=>csrf_token()];
@endphp
<script>window.AssetManagerConfig=@json($clientConfig);</script>
<script src="{{ asset('assets/erp/asset-manager.js') }}?v=20261118-2"></script>
@endpush
