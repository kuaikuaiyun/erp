@extends('portal.layout')
@section('page_title', __('asset_reminders.title'))
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-reminders.css') }}?v=20261121-1">
@endpush
@section('content')
<section class="content-header"><h1>{{ __('asset_reminders.title') }} <small>{{ __('asset_reminders.subtitle') }}</small></h1></section>
<section class="content ar-workspace">
    <div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ __('asset_reminders.filters') }}</h3></div>
        <form method="get" class="box-body ar-filter">
            <div class="ar-grid">
            <label>{{ __('asset_reminders.fields.department_id') }}<select name="department_id" class="form-control"><option value="">{{ __('asset_reminders.all') }}</option>@foreach($departments as $id=>$name)<option value="{{ $id }}" @selected((string)($filters['department_id']??'')===(string)$id)>{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('asset_reminders.fields.type') }}<select name="type" class="form-control"><option value="">{{ __('asset_reminders.all') }}</option>@foreach(__('asset_reminders.types') as $key=>$name)<option value="{{ $key }}" @selected(($filters['type']??'')===$key)>{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('asset_reminders.fields.enabled') }}<select name="enabled" class="form-control"><option value="">{{ __('asset_reminders.all') }}</option>@foreach([1=>'enabled',0=>'disabled'] as $key=>$name)<option value="{{ $key }}" @selected((string)($filters['enabled']??'')===(string)$key)>{{ __('asset_reminders.'.$name) }}</option>@endforeach</select></label>
            <label>{{ __('asset_reminders.fields.per_page') }}<select name="per_page" class="form-control">@foreach([20,50,100] as $size)<option @selected($rows->perPage()===$size)>{{ $size }}</option>@endforeach</select></label>
            </div>
            <div class="ar-tools ar-filter-actions"><button class="btn btn-primary"><i class="fa fa-search"></i> {{ __('asset_reminders.query') }}</button><a class="btn btn-default" href="{{ url('/asset-manager/reminders') }}">{{ __('asset_reminders.reset') }}</a></div>
        </form><div class="box-footer ar-hint">{{ __('asset_reminders.scope_hint') }} @unless($canManage){{ __('asset_reminders.readonly') }}@endunless</div>
    </div>
    <div class="box box-default"><div class="box-header with-border ar-heading"><h3 class="box-title">{{ __('asset_reminders.rules') }}</h3><div class="ar-tools"><button type="button" class="btn btn-default btn-sm" data-ar-calendar>{{ __('asset_reminders.calendar.title') }}</button><button type="button" class="btn btn-default btn-sm" data-ar-guide="robot"><i class="fa fa-book"></i> {{ __('asset_reminders.guides.robot.title') }}</button><button type="button" class="btn btn-default btn-sm" data-ar-guide="tool"><i class="fa fa-book"></i> {{ __('asset_reminders.guides.tool.title') }}</button>@if($canManage)<button type="button" class="btn btn-primary btn-sm" data-ar-create="rules"><i class="fa fa-plus"></i> {{ __('asset_reminders.add_rule') }}</button><button type="button" class="btn btn-primary btn-sm" data-ar-create="rules" data-ar-notification><i class="fa fa-plus"></i> {{ __('asset_reminders.add_notification') }}</button>@endif</div></div>
        <div class="ar-section-note"><details class="ar-help"><summary>{{ __('asset_reminders.usage_help') }}</summary><p class="ar-hint">{{ __('asset_reminders.calendar_hint') }}</p><p class="ar-hint">{{ __('asset_reminders.advertising_scope_hint') }}</p></details></div>
        <div class="ar-table-wrap"><div class="table-responsive"><table class="table table-bordered table-hover ar-table"><thead><tr>
            @foreach(['department_id','type','group_id','trigger','enabled','last_error'] as $field)<th>{{ __('asset_reminders.fields.'.$field) }}</th>@endforeach<th>{{ __('asset_reminders.actions') }}</th>
        </tr></thead><tbody>
        @forelse($rows as $row)<tr>
            <td>{{ implode(' / ', array_map(fn ($id) => $departments[$id] ?? $id, json_decode($row->department_ids ?? 'null', true) ?: [$row->department_id])) }}</td><td>{{ __('asset_reminders.types.'.$row->type) }}</td><td>{{ $groupNames[$row->group_id]??$row->group_id }}</td><td class="ar-trigger">
                @if($row->type === 'submission')
                    <strong>{{ __('asset_reminders.instant_trigger') }}</strong>
                    <div>{{ implode(' / ', array_map(fn ($type) => __('asset_reminders.submission_types.'.$type), json_decode($row->submission_types ?? '[]', true))) }}</div>
                @else
                    <strong>{{ $calendarNames[$row->calendar_company_id] ?? __('asset_reminders.calendar.unlinked') }}</strong>
                    <div>{{ __('asset_reminders.fields.work_times') }}：{{ implode(' / ', json_decode($row->work_times, true)) ?: '—' }}</div>
                    <div>{{ __('asset_reminders.fields.rest_times') }}：{{ implode(' / ', json_decode($row->rest_times, true)) ?: '—' }}</div>
                    <div>{{ __('asset_reminders.fields.starts_on') }}：{{ $row->starts_on }}</div>
                @endif
            </td><td><span class="ar-badge {{ $row->enabled?'is-on':'' }}">{{ __('asset_reminders.'.($row->enabled?'enabled':'disabled')) }}</span></td>
            <td class="ar-error-cell">{{ $row->last_error ?: '—' }}<small>{{ $row->last_checked_at ? \App\Portals\OperationTasks\Support\OperationTaskTime::format($row->last_checked_at) : __('asset_reminders.never') }}</small></td>
            <td class="ar-actions"><button type="button" class="btn btn-default btn-xs" data-ar-view="rules" data-id="{{ $row->id }}">{{ __('asset_reminders.view') }}</button>
            @if(in_array((int)$row->department_id,$writeIds,true))
                <button type="button" class="btn btn-primary btn-xs" data-ar-edit="rules" data-id="{{ $row->id }}">{{ __('asset_reminders.edit') }}</button>
                @if($row->type !== 'recap')<button type="button" class="btn btn-default btn-xs" data-ar-preview="{{ $row->id }}">{{ __('asset_reminders.preview') }}</button>@endif
                @if($row->type === 'advertising')<button type="button" class="btn btn-default btn-xs" data-ar-test="{{ $row->id }}">{{ __('asset_reminders.test') }}</button>@endif
                <button type="button" class="btn btn-default btn-xs" data-ar-remove="rules" data-id="{{ $row->id }}">{{ __('asset_reminders.stop') }}</button><button type="button" class="btn btn-danger btn-xs" data-ar-delete="{{ $row->id }}">{{ __('asset_reminders.remove') }}</button>
            @endif</td>
        </tr>@empty<tr><td colspan="7" class="ar-empty">{{ __('asset_reminders.empty') }}</td></tr>@endforelse
        </tbody></table></div></div>
        <div class="box-footer ar-pagination"><span>{{ __('asset_reminders.total',['count'=>$rows->total()]) }}</span><div class="ar-page-links">@if($rows->previousPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->previousPageUrl() }}">{{ __('asset_reminders.previous') }}</a>@endif <span>{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span> @if($rows->nextPageUrl())<a class="btn btn-default btn-sm" href="{{ $rows->nextPageUrl() }}">{{ __('asset_reminders.next') }}</a>@endif</div></div>
    </div>
    <div class="box box-default"><div class="box-header with-border ar-heading"><h3 class="box-title">{{ __('asset_reminders.groups') }}</h3>@if($canManageGroups)<button type="button" class="btn btn-primary btn-sm" data-ar-create="groups"><i class="fa fa-plus"></i> {{ __('asset_reminders.add_group') }}</button>@endif</div>
        <div class="box-body ar-groups-body"><details class="ar-help"><summary>{{ __('asset_reminders.usage_help') }}</summary><p class="ar-hint">{{ __('asset_reminders.config_hint') }}</p></details><div class="ar-group-grid">
        @forelse($groups as $group)<article class="ar-group"><div class="ar-heading"><strong><i class="fa fa-comments-o"></i> {{ $group->name }}</strong><span class="ar-badge {{ $group->enabled?'is-on':'' }}">{{ __('asset_reminders.'.($group->enabled?'enabled':'disabled')) }}</span></div>
            <p>{{ $group->owner_label }}</p><p class="ar-hint">{{ $companyNames[$group->company_id]??$group->company_id }} · {{ $group->remark }}</p>
            <p class="ar-hint">{{ __('asset_reminders.fields.webhook') }}：{{ __('asset_reminders.'.($group->webhook_configured?'configured':'not_configured')) }} · {{ __('asset_reminders.fields.webhook_secret') }}：{{ __('asset_reminders.'.($group->webhook_secret_configured?'configured':'not_configured')) }}</p><div class="ar-tools">
                <button type="button" class="btn btn-default btn-sm" data-ar-view="groups" data-id="{{ $group->id }}">{{ __('asset_reminders.view') }}</button>
                <button type="button" class="btn btn-default btn-sm" data-ar-runs="{{ $group->id }}">{{ __('asset_reminders.runs') }}</button>
                @if($group->can_manage)<button type="button" class="btn btn-primary btn-sm" data-ar-edit="groups" data-id="{{ $group->id }}">{{ __('asset_reminders.edit') }}</button><button type="button" class="btn btn-default btn-sm" data-ar-remove="groups" data-id="{{ $group->id }}">{{ __('asset_reminders.remove') }}</button>@endif
            </div></article>@empty<p class="ar-empty">{{ __('asset_reminders.empty') }}</p>@endforelse
        </div></div><div class="box-footer ar-pagination"><span>{{ __('asset_reminders.total',['count'=>$groups->total()]) }}</span><div class="ar-page-links">@if($groups->previousPageUrl())<a class="btn btn-default btn-sm" href="{{ $groups->previousPageUrl() }}">{{ __('asset_reminders.previous') }}</a>@endif <span>{{ $groups->currentPage() }} / {{ $groups->lastPage() }}</span> @if($groups->nextPageUrl())<a class="btn btn-default btn-sm" href="{{ $groups->nextPageUrl() }}">{{ __('asset_reminders.next') }}</a>@endif</div></div>
    </div>
</section>
<dialog class="ar-dialog" id="ar-modal" aria-labelledby="ar-modal-title"><form id="ar-form" novalidate data-loading="off">
    <header><h3 id="ar-modal-title"></h3><button type="button" class="close" data-ar-close aria-label="{{ __('asset_reminders.close') }}">&times;</button></header>
    <div class="ar-modal-body"><div id="ar-error" class="alert alert-danger" role="alert" hidden></div><div id="ar-fields"></div></div>
    <footer><button type="button" class="btn btn-default" data-ar-close>{{ __('asset_reminders.close') }}</button><button type="submit" class="btn btn-primary" id="ar-save">{{ __('asset_reminders.save') }}</button></footer>
</form></dialog>
@foreach(['robot','tool'] as $guide)
<template id="ar-guide-{{ $guide }}"><div class="ar-guide">
    <p class="ar-guide-intro">{{ __('asset_reminders.guides.'.$guide.'.intro') }}</p>
    @foreach(__('asset_reminders.guides.'.$guide.'.steps') as $number => $step)
    <section class="ar-guide-step"><h4><span>{{ $number + 1 }}</span>{{ $step['title'] }}</h4><p>{{ $step['body'] }}</p>
        @foreach($step['images'] as $file)
        <figure><img src="{{ asset('assets/erp/reminder-guides/'.$file) }}" alt="{{ $step['title'] }}" loading="lazy" decoding="async"></figure>
        @endforeach
    </section>
    @endforeach
</div></template>
@endforeach
@endsection
@push('scripts')
@php($reminderConfig = ['calendarCompanies'=>$calendarNames,'defaultEntryUrl'=>\App\Portals\AssetManager\ReminderService::DEFAULT_ENTRY_URL,'base'=>url('/asset-manager/reminders'),'text'=>__('asset_reminders'),'departments'=>$departments,'companies'=>$companyNames,'groups'=>$groupNames])
<script>window.AssetRemindersConfig=@json($reminderConfig);</script>
<script src="{{ asset('assets/erp/asset-reminders.js') }}?v=20261121-1"></script>
@endpush
