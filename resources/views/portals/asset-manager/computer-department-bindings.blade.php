@extends('portal.layout')
@section('page_title', __('asset_manager.bindings.title'))
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-computer-department-bindings.css') }}?v=20261118-1">
@endpush
@section('content')
@php
    $selectedSource = $sources->firstWhere('id', $sourceId);
    $departmentGroups = $departments->groupBy(fn ($d) => (int)($d->parent_id ?: $d->id));
    $targetLabels = $departments->mapWithKeys(fn ($d) => [$d->id => $bindings->label($d)]);
    $bindingClient = ['url'=>route('asset-manager.computer-department-bindings.update'), 'source'=>$sourceId, 'version'=>$version, 'text'=>__('asset_manager.bindings')];
@endphp
<section class="content-header"><h1>{{ __('asset_manager.bindings.title') }} <small>{{ __('asset_manager.bindings.subtitle') }}</small></h1></section>
<section class="content acb-page">
    @if(session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    <div class="acb-layout">
        <aside class="box box-default acb-source">
            <div class="box-header with-border"><h3 class="box-title"><span class="acb-step">1</span> {{ __('asset_manager.bindings.source') }}</h3></div>
            <div class="box-body">
                @if($sources->isNotEmpty())
                <form method="get" action="{{ route('asset-manager.computer-department-bindings.index') }}">
                    <label for="acb-source">{{ __('asset_manager.bindings.choose_source') }}</label>
                    <select id="acb-source" name="source_department_id" class="form-control" onchange="this.form.submit()">
                        @foreach($sources as $source)
                        <option value="{{ $source->id }}" @selected((int)$source->id===$sourceId)>{{ $bindings->label($source) }}</option>
                        @endforeach
                    </select>
                </form>
                @else
                <p class="text-muted">{{ __('asset_manager.bindings.no_sources') }}</p>
                @endif
                <p class="acb-note">{{ __('asset_manager.bindings.source_hint') }}</p>
                <p class="acb-note">{{ __('asset_manager.bindings.scope_hint') }}</p>
                <p class="acb-note">{{ __('asset_manager.bindings.empty_hint') }}</p>
            </div>
        </aside>
        <div class="box box-default acb-targets">
            <div class="box-header with-border"><h3 class="box-title"><span class="acb-step">2</span> {{ __('asset_manager.bindings.targets') }}</h3></div>
            <div class="box-body">
                @if($selectedSource)
                <h4>{{ $bindings->label($selectedSource) }}</h4>
                <div class="acb-tags">
                    @forelse($selectedIds as $departmentId)
                    <span class="acb-tag">{{ $targetLabels[$departmentId] ?? '#'.$departmentId }}</span>
                    @empty
                    <span class="text-muted">{{ __('asset_manager.bindings.none') }}</span>
                    @endforelse
                </div>
                <p class="acb-note">{{ __('asset_manager.bindings.root_hint') }}</p>
                <button class="btn btn-primary" type="button" data-toggle="modal" data-target="#acb-modal"><i class="fa fa-pencil" aria-hidden="true"></i> {{ __('asset_manager.bindings.edit') }}</button>
                @else
                <p class="text-muted">{{ __('asset_manager.bindings.no_sources') }}</p>
                @endif
            </div>
        </div>
    </div>
    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-history" aria-hidden="true"></i> {{ __('asset_manager.bindings.history') }}</h3></div>
        <div class="table-responsive"><table class="table table-bordered acb-history"><thead><tr>
            @foreach(['changed_at','actor','before','after'] as $heading)
            <th>{{ __('asset_manager.bindings.'.$heading) }}</th>
            @endforeach
        </tr></thead><tbody>
            @forelse($history as $entry)
            <tr><td class="acb-time">{{ \Carbon\CarbonImmutable::parse($entry->created_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td><td>{{ $entry->actor_name }}</td>
                @foreach(['before','after'] as $column)
                <td>
                    @forelse(json_decode($entry->{$column.'_labels'}, true) ?: [] as $name)
                    <span class="acb-tag">{{ $name }}</span>
                    @empty
                    <span class="text-muted">{{ __('asset_manager.bindings.none') }}</span>
                    @endforelse
                </td>
                @endforeach
            </tr>
            @empty
            <tr><td colspan="4" class="text-muted">{{ __('asset_manager.bindings.no_history') }}</td></tr>
            @endforelse
        </tbody></table></div>
        @if($history->hasPages())
        <div class="box-footer acb-pagination"><span>{{ __('asset_manager.total', ['count'=>$history->total()]) }}</span>
            @if($history->previousPageUrl())
            <a class="btn btn-default btn-sm" href="{{ $history->previousPageUrl() }}">{{ __('asset_manager.previous') }}</a>
            @endif
            @if($history->nextPageUrl())
            <a class="btn btn-default btn-sm" href="{{ $history->nextPageUrl() }}">{{ __('asset_manager.next') }}</a>
            @endif
        </div>
        @endif
    </div>
    @if($selectedSource)
    <div class="modal fade" id="acb-modal" tabindex="-1" role="dialog" aria-labelledby="acb-modal-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <form id="acb-form">
            <div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="{{ __('asset_manager.bindings.cancel') }}">&times;</button><h4 class="modal-title" id="acb-modal-title">{{ __('asset_manager.bindings.edit') }} · {{ $bindings->label($selectedSource) }}</h4></div>
            <div class="modal-body">
                <div id="acb-error" class="alert alert-danger" role="alert" hidden></div>
                <label for="acb-search">{{ __('asset_manager.bindings.search') }}</label><input id="acb-search" class="form-control" type="search" maxlength="100" autocomplete="off">
                <p class="acb-note">{{ __('asset_manager.bindings.root_hint') }}</p>
                <div class="acb-department-groups">
                    @foreach($departmentGroups as $group)
                    @php
                        $orderedGroup = $group->sortBy(fn ($d) => $d->parent_id ? 1 : 0);
                    @endphp
                    <div class="acb-group">
                        @foreach($orderedGroup as $department)
                        <label class="acb-option {{ $department->parent_id ? 'acb-child' : '' }}" data-search="{{ $bindings->label($department) }}">
                            <input type="checkbox" name="department_ids[]" value="{{ $department->id }}" @checked(in_array((int)$department->id, $selectedIds, true))>
                            <span><strong>{{ $department->name }}</strong><small>{{ __($department->parent_id ? 'asset_manager.bindings.secondary' : 'asset_manager.bindings.root') }}</small></span>
                        </label>
                        @endforeach
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer"><span id="acb-count" aria-live="polite"></span><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('asset_manager.bindings.cancel') }}</button><button type="submit" class="btn btn-primary" id="acb-save">{{ __('asset_manager.bindings.save') }}</button></div>
        </form>
    </div></div></div>
    @endif
</section>
@endsection
@push('scripts')
<script>
(function ($) {
    var c = @json($bindingClient);
    var form = document.getElementById('acb-form');
    if (!form) return;
    var busy = false, inputs = Array.from(form.querySelectorAll('input[type="checkbox"]'));
    var button = document.getElementById('acb-save'), error = document.getElementById('acb-error');
    function count() {
        document.getElementById('acb-count').textContent = c.text.selected.replace(':count', String(inputs.filter(function (i) { return i.checked; }).length));
    }
    form.addEventListener('change', count); count();
    document.getElementById('acb-search').addEventListener('input', function () {
        var term = this.value.trim().toLocaleLowerCase();
        form.querySelectorAll('.acb-option').forEach(function (option) { option.hidden = option.dataset.search.toLocaleLowerCase().indexOf(term) < 0; });
        form.querySelectorAll('.acb-group').forEach(function (group) { group.hidden = !Array.from(group.querySelectorAll('.acb-option')).some(function (option) { return !option.hidden; }); });
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (busy) return;
        busy = true; button.disabled = true; button.textContent = c.text.saving; error.hidden = true;
        inputs.forEach(function (input) { input.disabled = true; });
        try {
            var response = await fetch(c.url, {method:'PUT', credentials:'same-origin', headers:{'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify({source_department_id:c.source, version:c.version, department_ids:inputs.filter(function (i) { return i.checked; }).map(function (i) { return Number(i.value); })})});
            var data = await response.json().catch(function () { return {}; });
            if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join('\n') : (data.message || c.text.failed));
            window.location.reload();
        } catch (e) {
            error.textContent = e.message; error.hidden = false; busy = false; button.disabled = false; button.textContent = c.text.save;
            inputs.forEach(function (input) { input.disabled = false; });
        }
    });
    $('#acb-modal').on('hide.bs.modal', function (event) { if (busy) event.preventDefault(); });
})(window.jQuery);
</script>
@endpush
