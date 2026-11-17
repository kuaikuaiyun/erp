@extends('portal.layout')
@section('page_title', __('review_center.department_bindings'))
@section('content')
<section class="content-header"><h1>{{ __('review_center.department_bindings') }}</h1></section>
<section class="content">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="box box-primary"><div class="box-body">
        <p class="text-muted">{{ __('review_center.binding_hint') }}</p>
        <form method="get" action="{{ route('review-center.department-bindings.index') }}">
            <div class="form-group"><label for="binding-member">{{ __('review_center.member') }}</label>
                <select id="binding-member" name="user_id" class="form-control">@foreach($users as $member)<option value="{{ $member->id }}" @selected($selectedUserId === (int) $member->id)>{{ $member->name }} ({{ $member->username }})</option>@endforeach</select>
            </div><button class="btn btn-default">{{ __('review_center.load_member') }}</button>
        </form><hr>
        @if($selectedUserId)
        <form method="post" action="{{ route('review-center.department-bindings.update') }}">@csrf @method('PUT')
            <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
            <h4>{{ __('review_center.departments') }} — {{ $users->firstWhere('id', $selectedUserId)?->name }}</h4>
            @foreach($departments as $department)
                <div class="checkbox"><label><input type="checkbox" name="department_ids[]" value="{{ $department->id }}" @checked(in_array((int) $department->id, array_map('intval', old('department_ids', $selectedIds)), true))>
                    @if($department->parent){{ $department->parent->name }} / @endif{{ $department->name }}
                    <small class="text-muted">{{ $department->parent_id ? __('review_center.secondary_scope') : __('review_center.root_scope') }}</small>
                </label></div>
            @endforeach
            <p class="text-muted">{{ __('review_center.clear_hint') }}</p>
            <button class="btn btn-primary">{{ __('review_center.save_bindings') }}</button>
        </form>
        @endif
    </div></div>
    <div class="box"><div class="box-header"><h3 class="box-title">{{ __('review_center.history') }}</h3></div><div class="box-body table-responsive">
        <table class="table table-bordered"><thead><tr><th>{{ __('review_center.changed_at') }}</th><th>{{ __('review_center.actor') }}</th><th>{{ __('review_center.before') }}</th><th>{{ __('review_center.after') }}</th></tr></thead><tbody>
        @forelse($history as $entry)<tr><td>{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($entry->created_at) }}</td><td>{{ $entry->actor_name }}</td>
            @foreach(['before_ids', 'after_ids'] as $column)<td>{{ collect(json_decode($entry->{$column}, true) ?: [])->map(fn ($id) => $departments->firstWhere('id', (int) $id)?->name ?: '#'.$id)->implode('、') ?: __('review_center.none') }}</td>@endforeach
        </tr>@empty<tr><td colspan="4">{{ __('review_center.no_history') }}</td></tr>@endforelse
        </tbody></table>
    </div></div>
</section>
@endsection
