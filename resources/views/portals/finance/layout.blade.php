@extends('portal.layout')
@section('body_class', 'finance-system')

@section('page_title', $title)

@push('styles')
<link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-theme.css?v=20261104-2">
<style>
    .finance-summary-tabs{display:flex;gap:4px;margin:0 0 18px;border-bottom:1px solid #d7e3ed}.finance-summary-tabs a{display:block;padding:12px 20px;color:#7b8fa1;border-bottom:3px solid transparent;text-decoration:none}.finance-summary-tabs a:hover{color:#258bc7;background:#f4f8fc}.finance-summary-tabs a.is-active{color:#258bc7;border-bottom-color:#258bc7;font-weight:600}
    .finance-table-wrap{overflow-x:auto}.finance-table th{white-space:nowrap;background:#f3f6f9}.finance-table td{min-width:100px;max-width:260px;overflow-wrap:anywhere;vertical-align:middle!important}.finance-filter{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}.finance-filter .form-group{margin-bottom:12px;min-width:150px}.finance-tools{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}.finance-note{color:#5b6878;line-height:1.8}.finance-stat{font-size:28px;font-weight:600}.finance-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}.finance-card{background:#fff;border-top:3px solid #3c8dbc;padding:18px;border-radius:4px;margin-bottom:20px}.finance-condition{display:flex;gap:8px;margin-bottom:10px}.finance-condition select,.finance-condition input{max-width:240px}.finance-pages{display:flex;justify-content:space-between;align-items:center;margin-top:12px}.finance-status{padding:18px;border-left:3px solid #e0a13d;background:#fff9ed;margin-bottom:20px}.finance-links a{display:inline-block;margin:0 18px 12px 0}
.finance-system .finance-selection{width:36px!important;min-width:36px!important;padding:6px!important;text-align:center!important}.finance-selection input{margin:0;vertical-align:middle}.finance-export-count{font-size:12px;color:#637c90;margin-left:8px}
</style>
@endpush

@section('content')
<section class="content-header">@yield('finance_header_actions')<h1>{{ $title }} <small>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($portal->name)) }}</small></h1></section>
<section class="content">
    @if(session('success'))<div class="alert alert-success" role="status" data-finance-notice>{{ session('success') }}</div>@endif
    @if(session('syncResults'))<div class="finance-status" role="status" data-finance-notice>@foreach(session('syncResults') as $result)<p>{{ $result['account'] }}：{{ $result['ok'] ? __('finance.sync.success', ['count' => $result['count']]) : $result['error'].' '.__('finance.sync.partial', ['count' => $result['count']]) }}</p>@endforeach</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($error)) }}</li>@endforeach</ul></div>@endif
    @yield('finance_content')
</section>
@endsection

@push('scripts')
<script src="{{ asset('assets/erp/finance-export-selection.js') }}?v=4"></script>
<script>
document.addEventListener('change', function (event) {
    var select = event.target;
    if (!select.matches('select[data-finance-page-size]')) return;
    var url = new window.URL(window.location.href);
    url.searchParams.set(select.dataset.financePageSize, select.value);
    if (select.dataset.financePageName === 'page') url.searchParams.delete('page');
    else url.searchParams.set(select.dataset.financePageName, '1');
    window.location.assign(url.toString());
});
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-finance-download]');
    if (!button) return;
    if (button.dataset.financeExportUrl) { window.location.assign(button.dataset.financeExportUrl); return; }
    var form = button.form;
    if (!form || !form.reportValidity()) return;
    var url = new URL(form.action || window.location.href, window.location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();
    url.searchParams.set('download', '1');
    form.dispatchEvent(new CustomEvent('finance:prepare-export', {detail: url.searchParams}));
    window.location.assign(url.toString());
});
document.querySelectorAll('[data-finance-notice]').forEach(function (notice) {
    window.setTimeout(function () { notice.remove(); }, 10000);
});
</script>
@endpush
