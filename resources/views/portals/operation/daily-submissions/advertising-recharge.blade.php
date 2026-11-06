@extends('portal.layout')
@section('page_title', '广告充值')
@push('styles')
<style>
.recharge-card{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.recharge-card .box-header{padding:15px 18px}.recharge-card .box-title{font-weight:700;color:#234b68}.recharge-form-table{min-width:1050px;margin:0}.recharge-form-table th{background:#eaf3f9;text-align:center;color:#34546b}.recharge-form-table td{padding:10px!important;vertical-align:middle!important}.recharge-form-table .form-control{min-width:145px;height:36px;border-radius:6px}.recharge-actions{display:flex;gap:10px;padding:15px 18px}.status-pill{display:inline-block;padding:5px 11px;border-radius:15px;font-size:12px}.status-pill.pending{background:#fff0c2;color:#986700}.status-pill.processed{background:#dff5e9;color:#18784c}.history-wrap{overflow:auto}.history-table{min-width:1100px;margin:0}.history-table th{background:#edf4f8}.entry-lines{line-height:1.65}.entry-lines div+div{border-top:1px dashed #dce5eb;margin-top:4px;padding-top:4px}
</style>
@endpush
@section('content')
<section class="content-header"><h1>广告充值 <small>填写广告账户充值信息并提交助理系统处理</small></h1></section>
<section class="content">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>提交失败：</strong>{{ $errors->first() }}</div>@endif
<div class="box recharge-card">
 <div class="box-header with-border"><h3 class="box-title">广告充值提报</h3></div>
 <form method="post" action="{{ route('operation.daily-submissions.advertising-recharge.store') }}">@csrf
  <div class="box-body"><div class="form-group" style="max-width:260px"><label>提报日期 <span class="text-danger">*</span></label><input class="form-control" type="date" name="report_date" value="{{ old('report_date', now()->format('Y-m-d')) }}" required></div></div>
  <div class="table-responsive"><table class="table table-bordered recharge-form-table"><thead><tr><th>广告代理 *</th><th>广告社媒 *</th><th>广告账户名称 *</th><th>广告账户编号 *</th><th>充值金额 *</th></tr></thead><tbody id="recharge-rows"></tbody></table></div>
  <div class="recharge-actions"><button class="btn btn-default" type="button" id="add-recharge-row"><i class="fa fa-plus"></i> 添加广告账户</button><button class="btn btn-primary" type="submit"><i class="fa fa-send"></i> 提交</button></div>
 </form>
</div>
<div class="box recharge-card"><div class="box-header with-border"><h3 class="box-title">提交记录</h3></div><div class="history-wrap"><table class="table table-bordered table-hover history-table"><thead><tr><th>提报日期</th><th>运营</th><th>广告账户明细</th><th>合计金额($)</th><th>状态</th><th>处理人</th><th>提交时间</th></tr></thead><tbody>@forelse($history as $item)<tr><td>{{ $item->report_date->format('Y-m-d') }}</td><td>{{ $item->operator_name }}</td><td class="entry-lines">@foreach($item->entries ?? [] as $entry)<div>{{ $entry['agency'] ?? '—' }} / {{ $entry['media'] ?? '—' }} / {{ $entry['account_name'] ?? '—' }} / {{ $entry['account_number'] ?? '—' }}：${{ number_format((float)($entry['amount'] ?? 0),2) }}</div>@endforeach</td><td>${{ number_format((float)$item->total_amount,2) }}</td><td><span class="status-pill {{ $item->status }}">{{ $item->statusLabel() }}</span></td><td>{{ $item->processed_by_name ?: '—' }}</td><td>{{ $item->created_at?->format('Y-m-d H:i') }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">暂无提报记录</td></tr>@endforelse</tbody></table></div>@if($history->hasPages())<div class="box-footer">{{ $history->links() }}</div>@endif</div>
</section>
<template id="recharge-row-template"><tr><td><select class="form-control agency-select" required><option value="">请选择代理</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" data-media="{{ $agency->advertisingMedia?->name }}">{{ $agency->name }}</option>@endforeach</select></td><td><input class="form-control media-input" type="text" readonly placeholder="选择代理后自动显示"></td><td><input class="form-control account-name" type="text" required></td><td><input class="form-control account-number" type="text" required></td><td><input class="form-control amount" type="number" min="0.01" step="0.01" required></td></tr></template>
@endsection
@push('scripts')
<script>(function(){var body=document.getElementById('recharge-rows'),tpl=document.getElementById('recharge-row-template'),add=document.getElementById('add-recharge-row');function names(){Array.prototype.forEach.call(body.rows,function(row,i){row.querySelector('.agency-select').name='entries['+i+'][agency_id]';row.querySelector('.account-name').name='entries['+i+'][account_name]';row.querySelector('.account-number').name='entries['+i+'][account_number]';row.querySelector('.amount').name='entries['+i+'][amount]';});}function append(){body.appendChild(tpl.content.cloneNode(true));names();}add.addEventListener('click',append);body.addEventListener('change',function(e){if(!e.target.classList.contains('agency-select'))return;var option=e.target.options[e.target.selectedIndex];e.target.closest('tr').querySelector('.media-input').value=option?option.dataset.media||'':'';});append();})();</script>
@endpush
