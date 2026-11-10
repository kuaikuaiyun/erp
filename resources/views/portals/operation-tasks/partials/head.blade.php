@push('styles')<link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/operation-tasks.css?v=20261110-1">@endpush
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>操作未完成：</strong>{{ $errors->first() }}</div>@endif
