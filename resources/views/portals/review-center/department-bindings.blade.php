@extends('portals.review-center.layout')
@section('page_title', __('review_center.department_bindings'))
@push('styles')
<link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/review-center-bindings.css?v=20261117-2">
@endpush
@section('content')
@php
    $selectedMember = $users->firstWhere('id', $selectedUserId);
    $checkedDepartmentIds = (int) old('user_id', $selectedUserId) === $selectedUserId
        ? (array) old('department_ids', $selectedIds) : $selectedIds;
    $checkedDepartmentIds = array_map('intval', $checkedDepartmentIds);
    $departmentGroups = $departments->groupBy(fn ($department) => (int) ($department->parent_id ?: $department->id));
    $selectedCount = $departments->filter(fn ($department) => in_array((int) $department->id, $checkedDepartmentIds, true))->count();
@endphp
<div class="rc-bindings-page">
    <section class="content-header rc-heading"><h1>{{ __('review_center.department_bindings') }}</h1><p>{{ __('review_center.bindings_ui.subtitle') }}</p></section>
    <section class="content">
        @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        <div class="rc-binding-layout">
            <aside class="rc-panel rc-member-panel">
                <div class="rc-panel-heading"><span class="rc-step" aria-hidden="true">1</span><h2>{{ __('review_center.member') }}</h2></div>
                <form method="get" action="{{ route('review-center.department-bindings.index') }}" class="rc-member-form">
                    <label for="binding-member">{{ __('review_center.bindings_ui.select_member') }}</label>
                    <select id="binding-member" name="user_id" class="form-control" @disabled($users->isEmpty())>
                        @foreach($users as $member)<option value="{{ $member->id }}" @selected($selectedUserId === (int) $member->id)>{{ $member->name }} ({{ $member->username }})</option>@endforeach
                    </select>
                    <span class="rc-member-loading" role="status" hidden>{{ __('review_center.bindings_ui.loading') }}</span>
                </form>
                @if($selectedMember)
                    <div class="rc-current-member"><span class="rc-member-avatar" aria-hidden="true"><i class="fa fa-user"></i></span><div><span class="rc-muted">{{ __('review_center.bindings_ui.current_member') }}</span><strong>{{ $selectedMember->name }}</strong><span class="rc-member-account">{{ $selectedMember->username }}</span></div></div>
                @endif
                <div class="rc-permission-note"><i class="fa fa-info-circle" aria-hidden="true"></i><p>{{ __('review_center.binding_hint') }}</p></div>
            </aside>
            <div class="rc-panel rc-department-panel">
                <div class="rc-panel-heading"><span class="rc-step" aria-hidden="true">2</span><h2>{{ __('review_center.departments') }}</h2><span class="rc-multiple-label">{{ __('review_center.bindings_ui.multiple') }}</span></div>
                <div class="rc-loading-placeholder" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                @if($users->isEmpty())
                    <div class="rc-empty"><i class="fa fa-user-o" aria-hidden="true"></i><p>{{ __('review_center.no_authorized_members') }}</p></div>
                @elseif($selectedUserId)
                    <form method="post" action="{{ route('review-center.department-bindings.update') }}" id="rc-department-form">
                        @csrf @method('PUT')
                        <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
                        <p class="rc-department-help">{{ __('review_center.bindings_ui.department_hint') }}</p>
                        <div class="rc-department-grid">
                            @forelse($departmentGroups as $groupId => $group)
                                @php
                                    $rootDepartment = $group->first(fn ($department) => (int) $department->id === (int) $groupId);
                                    $orderedGroup = $rootDepartment ? collect([$rootDepartment])->concat($group->reject(fn ($department) => (int) $department->id === (int) $groupId)) : $group;
                                @endphp
                                <div class="rc-department-group">
                                    @if(! $rootDepartment)<div class="rc-orphan-heading">{{ $group->first()->parent?->name ?: __('review_center.departments') }}</div>@endif
                                    @foreach($orderedGroup as $department)
                                        @php($isChecked = in_array((int) $department->id, $checkedDepartmentIds, true))
                                        <label class="rc-department-option {{ $department->parent_id ? 'rc-child-option' : 'rc-root-option' }} {{ $isChecked ? 'is-selected' : '' }}">
                                            <input type="checkbox" name="department_ids[]" value="{{ $department->id }}" @checked($isChecked)>
                                            <span class="rc-department-copy"><span class="rc-department-name">{{ $department->name }}</span><small>{{ $department->parent_id ? __('review_center.secondary_scope') : __('review_center.root_scope') }}</small></span>
                                            <i class="fa {{ $department->parent_id ? 'fa-angle-right' : 'fa-folder-o' }} rc-department-icon" aria-hidden="true"></i>
                                        </label>
                                    @endforeach
                                </div>
                            @empty<p class="rc-empty">{{ __('review_center.bindings_ui.no_departments') }}</p>@endforelse
                        </div>
                        <div class="rc-save-bar"><div><span class="rc-selected-summary" id="rc-selected-summary" aria-live="polite">{{ __('review_center.bindings_ui.selected_count', ['count' => $selectedCount]) }}</span><p>{{ __('review_center.clear_hint') }}</p></div><button type="submit" class="btn btn-primary"><i class="fa fa-check" aria-hidden="true"></i> {{ __('review_center.save_bindings') }}</button></div>
                    </form>
                @endif
            </div>
        </div>
        <div class="rc-panel rc-history-panel">
            <div class="rc-panel-heading"><i class="fa fa-history rc-history-icon" aria-hidden="true"></i><h2>{{ __('review_center.history') }}</h2>@if($selectedMember)<span class="rc-history-member">{{ $selectedMember->name }}</span>@endif</div>
            <div class="rc-loading-placeholder" aria-hidden="true"><span></span><span></span><span></span></div>
            <div class="table-responsive"><table class="table rc-history-table"><thead><tr><th scope="col">{{ __('review_center.changed_at') }}</th><th scope="col">{{ __('review_center.actor') }}</th><th scope="col">{{ __('review_center.before') }}</th><th scope="col">{{ __('review_center.after') }}</th></tr></thead><tbody>
                @forelse($history as $entry)
                    <tr><td class="rc-history-time">{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($entry->created_at) }}</td><td>{{ $entry->actor_name }}</td>
                        @foreach(['before_ids', 'after_ids'] as $column)<td>@forelse(json_decode($entry->{$column}, true) ?: [] as $departmentId)<span class="rc-department-tag">{{ $departments->firstWhere('id', (int) $departmentId)?->name ?: '#'.$departmentId }}</span>@empty<span class="rc-muted">{{ __('review_center.none') }}</span>@endforelse</td>@endforeach
                    </tr>
                @empty<tr><td colspan="4" class="rc-history-empty">{{ __('review_center.no_history') }}</td></tr>@endforelse
            </tbody></table></div>
        </div>
    </section>
</div>
@endsection
@push('scripts')
<script>
(function () {
    var form = document.getElementById('rc-department-form');
    if (!form) return;
    var inputs = form.querySelectorAll('input[name="department_ids[]"]');
    var summary = document.getElementById('rc-selected-summary');
    var countLabel = @json(__('review_center.bindings_ui.selected_count', ['count' => ':count']));
    var member = document.getElementById('binding-member');
    var page = document.querySelector('.rc-bindings-page');
    var loadedMemberId = form.querySelector('input[name="user_id"]').value;
    var discardMessage = @json(__('review_center.bindings_ui.discard_changes'));
    var switching = false;
    var saving = false;
    function selectedValues() {
        return Array.prototype.filter.call(inputs, function (input) { return input.checked; })
            .map(function (input) { return input.value; }).sort().join(',');
    }
    var initialValues = selectedValues();
    function resetLoading() {
        switching = false;
        saving = false;
        member.value = loadedMemberId;
        member.disabled = false;
        page.classList.remove('is-member-loading');
        page.querySelector('.rc-member-loading').hidden = true;
        Array.prototype.forEach.call(page.querySelectorAll('.rc-department-panel, .rc-history-panel'), function (panel) {
            panel.removeAttribute('aria-busy');
        });
        Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"], button[type="submit"]'), function (input) { input.disabled = false; });
        updateSelection();
    }
    function switchMember(event) {
        if (event) event.preventDefault();
        if (switching || saving || member.value === loadedMemberId) return;
        if (selectedValues() !== initialValues && !window.confirm(discardMessage)) {
            member.value = loadedMemberId;
            return;
        }
        var destination = new URL(member.form.action, window.location.href);
        destination.searchParams.set('user_id', member.value);
        switching = true;
        member.disabled = true;
        page.classList.add('is-member-loading');
        page.querySelector('.rc-member-loading').hidden = false;
        Array.prototype.forEach.call(page.querySelectorAll('.rc-department-panel, .rc-history-panel'), function (panel) {
            panel.setAttribute('aria-busy', 'true');
        });
        Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"], button[type="submit"]'), function (input) { input.disabled = true; });
        // Navigate only after the placeholder has had a chance to paint.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { window.location.assign(destination.href); });
        });
    }
    member.addEventListener('change', switchMember);
    member.form.addEventListener('submit', switchMember);
    form.addEventListener('submit', function (event) {
        if (switching || saving) { event.preventDefault(); return; }
        saving = true;
        member.disabled = true;
    });
    window.addEventListener('pageshow', function (event) { if (event.persisted) resetLoading(); });
    function updateSelection() {
        var count = 0;
        Array.prototype.forEach.call(inputs, function (input) {
            input.closest('.rc-department-option').classList.toggle('is-selected', input.checked);
            if (input.checked) count++;
        });
        summary.textContent = countLabel.replace(':count', String(count));
    }
    form.addEventListener('change', updateSelection);
    updateSelection();
})();
</script>
@endpush
