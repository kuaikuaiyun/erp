@extends('portal.layout')
@section('page_title', __('discipline.title'))
@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/discipline.css?v=20261118-1">
@endpush
@section('content')
@php
    $monthDate = \Carbon\CarbonImmutable::parse($filters['month'].'-01', 'Asia/Shanghai');
    $baseUrl = url('/asset-manager/discipline');
    $clientConfig = ['base' => $baseUrl, 'month' => $filters['month'], 'department' => $filters['department_id'] ?? '', 'canManage' => $canManage, 'members' => $data['members'], 'summaries' => $data['summaries'], 'text' => __('discipline')];
@endphp
<div class="discipline-page">
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">{{ __('discipline.title') }}</h3></div>
        <div class="box-body">
            <p class="text-muted">{{ __($canManage ? 'discipline.manage_scope' : 'discipline.self_scope') }}</p>
            <form method="get" action="{{ $baseUrl }}" class="discipline-filters">
                <label>{{ __('discipline.month') }}<input class="form-control" type="month" name="month" min="1900-01" max="9999-12" value="{{ $filters['month'] }}" required></label>
                @if($canManage)
                <label>{{ __('discipline.department') }}<select class="form-control" name="department_id"><option value="">{{ __('discipline.all_departments') }}</option>
                    @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string)($filters['department_id'] ?? '') === (string)$department->id)>{{ $department->name }}</option>
                    @endforeach
                </select></label>
                @endif
                <label>{{ __('discipline.member_search') }}<input class="form-control" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></label>
                <label>{{ __('discipline.per_page') }}<select class="form-control" name="per_page">
                    @foreach([30,50,100] as $size)
                    <option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 30) === $size)>{{ $size }}</option>
                    @endforeach
                </select></label>
                <button class="btn btn-primary" type="submit">{{ __('discipline.filter') }}</button>
                <a class="btn btn-default" href="{{ $baseUrl.'/export?'.http_build_query(array_diff_key($filters, ['page'=>1])) }}">{{ __('discipline.export') }}</a>
                @if($canManage)
                <button class="btn btn-success" id="discipline-add" type="button">{{ __('discipline.add') }}</button>
                @endif
            </form>
            <div class="discipline-toolbar">
                <div class="btn-group">
                    @if($filters['month'] > '1900-01')
                    <a class="btn btn-default" href="{{ $baseUrl.'?'.http_build_query(array_merge($filters, ['month'=>$monthDate->subMonth()->format('Y-m'), 'page'=>1])) }}">{{ __('discipline.previous') }}</a>
                    @endif
                    <a class="btn btn-default" href="{{ $baseUrl.'?'.http_build_query(array_merge($filters, ['month'=>now('Asia/Shanghai')->format('Y-m'), 'page'=>1])) }}">{{ __('discipline.current') }}</a>
                    @if($filters['month'] < '9999-12')
                    <a class="btn btn-default" href="{{ $baseUrl.'?'.http_build_query(array_merge($filters, ['month'=>$monthDate->addMonth()->format('Y-m'), 'page'=>1])) }}">{{ __('discipline.next') }}</a>
                    @endif
                </div>
                <strong>{{ $filters['month'] }} · {{ __('discipline.calendar') }}</strong>
                <span>{{ __('discipline.total_members', ['count'=>$members->total()]) }}</span>
            </div>
            <div class="discipline-grid" tabindex="0" aria-label="{{ __('discipline.calendar') }}">
                <table class="discipline-table">
                    <thead><tr><th class="discipline-member">{{ __('discipline.member') }}</th>
                        @foreach($days as $day)
                        <th class="{{ $day['weekend'] ? 'is-weekend' : '' }} {{ $day['today'] ? 'is-today' : '' }}">{{ $day['day'] }}<br><small>{{ $day['weekday'] }}</small></th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach($data['members'] as $member)
                        <tr><th scope="row" class="discipline-member"><span>{{ $member['name'] }}</span><small>{{ $member['username'] }}</small></th>
                            @foreach($days as $day)
                            <td class="{{ $day['weekend'] ? 'is-weekend' : '' }} {{ $day['today'] ? 'is-today' : '' }}"><button class="discipline-cell" type="button" data-member="{{ $member['id'] }}" data-date="{{ $day['date'] }}" aria-label="{{ __('discipline.day_title', ['name'=>$member['name'], 'date'=>$day['date']]) }}"></button></td>
                            @endforeach
                        </tr>
                        @endforeach
                        @if($members->isEmpty())
                        <tr><td colspan="{{ $days->count()+1 }}" class="text-muted">{{ __('discipline.empty') }}</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="discipline-toolbar">
                <span>{{ __('discipline.page', ['page'=>$members->currentPage(), 'total'=>$members->lastPage()]) }}</span>
                <div class="btn-group">
                    @if($members->previousPageUrl())
                    <a class="btn btn-default" href="{{ $members->previousPageUrl() }}">{{ __('discipline.previous_page') }}</a>
                    @endif
                    @if($members->nextPageUrl())
                    <a class="btn btn-default" href="{{ $members->nextPageUrl() }}">{{ __('discipline.next_page') }}</a>
                    @endif
                </div>
            </div>
            <p class="text-muted">{{ __('discipline.history_hint') }} {{ __('discipline.export_hint') }}</p>
        </div>
    </div>
</div>
<div class="modal fade" id="discipline-day" tabindex="-1" role="dialog" aria-labelledby="discipline-day-title">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('discipline.close') }}">&times;</button><h4 id="discipline-day-title" class="modal-title"></h4></div>
        <div class="modal-body"><div id="discipline-day-error" class="alert alert-danger" hidden role="alert"></div><div id="discipline-records"></div></div>
        <div class="modal-footer"><button class="btn btn-default" id="discipline-day-prev">{{ __('discipline.previous_page') }}</button><span id="discipline-day-page"></span><button class="btn btn-default" id="discipline-day-next">{{ __('discipline.next_page') }}</button><button class="btn btn-success" id="discipline-day-add">{{ __('discipline.add') }}</button><button class="btn btn-default" data-dismiss="modal">{{ __('discipline.close') }}</button></div>
    </div></div>
</div>
<div class="modal fade" id="discipline-editor" tabindex="-1" role="dialog" aria-labelledby="discipline-editor-title">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <form id="discipline-form">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('discipline.close') }}">&times;</button><h4 id="discipline-editor-title" class="modal-title"></h4></div>
            <div class="modal-body">
                <div id="discipline-editor-error" class="alert alert-danger" hidden role="alert"></div>
                <div id="discipline-member-picker"><label for="discipline-member-search">{{ __('discipline.choose_member') }}</label><div class="input-group"><input id="discipline-member-search" class="form-control" maxlength="100" autocomplete="off"><span class="input-group-btn"><button id="discipline-member-find" class="btn btn-default" type="button">{{ __('discipline.search') }}</button></span></div><div id="discipline-member-results"></div><div class="btn-group"><button id="discipline-member-prev" class="btn btn-default" type="button">{{ __('discipline.previous_page') }}</button><button id="discipline-member-next" class="btn btn-default" type="button">{{ __('discipline.next_page') }}</button></div></div>
                <div class="form-group"><label>{{ __('discipline.member') }}</label><p id="discipline-member-name" class="form-control-static"></p></div>
                <div class="form-group"><label for="discipline-owner">{{ __('discipline.department') }}</label><select id="discipline-owner" class="form-control" required></select></div>
                <div class="form-group"><label for="discipline-date">{{ __('discipline.date') }}</label><input id="discipline-date" type="date" class="form-control" min="1900-01-01" max="9999-12-31" required></div>
                <div class="form-group"><label for="discipline-remarks">{{ __('discipline.remarks') }}</label><textarea id="discipline-remarks" class="form-control" rows="5" maxlength="2000" required></textarea><small class="text-muted">{{ __('discipline.max_remarks') }}</small></div>
                <p id="discipline-timestamps" class="text-muted"></p>
                <div id="discipline-delete-confirm" class="alert alert-warning" hidden>{{ __('discipline.delete_confirm') }}</div>
                <div id="discipline-audit" hidden><h4>{{ __('discipline.audit') }}</h4><div id="discipline-audit-rows"></div><button id="discipline-log-prev" type="button" class="btn btn-default">{{ __('discipline.previous_page') }}</button><button id="discipline-log-next" type="button" class="btn btn-default">{{ __('discipline.next_page') }}</button></div>
            </div>
            <div class="modal-footer"><button id="discipline-save" class="btn btn-primary" type="submit">{{ __('discipline.save') }}</button><button id="discipline-delete" class="btn btn-danger" type="button">{{ __('discipline.delete') }}</button><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('discipline.close') }}</button></div>
        </form>
    </div></div>
</div>
@endsection
@push('scripts')
<script>window.DisciplineConfig = @json($clientConfig);</script>
<script src="{{ $portalOrigin }}/assets/erp/discipline.js?v=20261118-1"></script>
@endpush
