@extends('portal.layout')
@section('page_title', '编辑广告信息')
@push('styles')
<style>
.assistant-ad-edit .box{border:0;border-radius:10px;box-shadow:0 4px 18px rgba(35,67,92,.09);overflow:hidden}.assistant-ad-edit .edit-head{display:flex;align-items:center;justify-content:space-between;gap:15px}.assistant-ad-edit .edit-meta{display:flex;gap:25px;flex-wrap:wrap;padding:13px 17px;border-left:4px solid #3c8dbc;background:#f5fafd;color:#526d80}.assistant-ad-edit .edit-meta strong{color:#183f59}.assistant-ad-edit table{margin:0}.assistant-ad-edit th{background:#eaf4fa;color:#28516d;text-align:center;white-space:nowrap}.assistant-ad-edit td{vertical-align:middle!important}.assistant-ad-edit .form-control{min-width:125px;border-color:#d4e2eb;border-radius:5px;box-shadow:none}.assistant-ad-edit .money-input{min-width:105px}.assistant-ad-edit .shot-cell{min-width:130px}.assistant-ad-edit .shot-cell img{display:block;width:76px;height:52px;margin-bottom:7px;border:1px solid #d5e3ec;border-radius:5px;object-fit:cover;cursor:zoom-in}.assistant-ad-edit .delete-cell{min-width:82px;text-align:center}.assistant-ad-edit .edit-actions{display:flex;justify-content:flex-end;gap:8px;padding:15px 18px;background:#f9fbfc}.assistant-ad-edit .edit-tip{margin:0;padding:11px 16px;border:0;border-radius:0;background:#fff7df;color:#846113}.assistant-ad-edit .text-danger{margin-top:5px;font-size:12px}@media(max-width:900px){.assistant-ad-edit .edit-head{align-items:flex-start;flex-direction:column}}
</style>
@endpush
@section('content')
<section class="content-header"><div class="edit-head"><h1>编辑广告信息</h1><a class="btn btn-default" href="{{ route('assistant.operation-report-reviews.show',$report) }}"><i class="fa fa-arrow-left"></i> 返回详情</a></div></section>
<section class="content assistant-ad-edit">
    @if($errors->any())<div class="alert alert-danger"><i class="fa fa-warning"></i> {{ $errors->first() }}</div>@endif
    <div class="box">
        <div class="edit-meta"><span>报表日期：<strong>{{ $report->report_date->format('Y-m-d') }}</strong></span><span>运营人员：<strong>{{ $report->operator_name }}</strong></span><span>运营团队：<strong>{{ $report->operation_department_name ?: '—' }}</strong></span></div>
        <form method="post" enctype="multipart/form-data" action="{{ route('assistant.operation-report-reviews.update',$report) }}">@csrf @method('put')
            <div id="assistant-deleted-ad-inputs">@foreach(old('deleted_indexes',[]) as $deletedIndex)<input type="hidden" name="deleted_indexes[]" value="{{$deletedIndex}}">@endforeach</div>
            <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>广告代理</th><th>广告社媒</th><th>账户名称</th><th>账户编号</th><th>账面广告费($)</th><th>结算系数</th><th>实际广告费($)</th><th>广告费截图</th><th>操作</th></tr></thead><tbody>
            @foreach(old('ads',$advertisingRows) as $index=>$row)
                @php($existing = $row['existing_screenshot'] ?? $row['screenshot'] ?? null)
                <tr>
                    <td><input class="form-control" name="ads[{{$index}}][agency]" value="{{ $row['agency'] ?? '' }}" required></td>
                    <td><input class="form-control" name="ads[{{$index}}][media_source]" value="{{ $row['media_source'] ?? '' }}" required></td>
                    <td><input class="form-control" name="ads[{{$index}}][account_name]" value="{{ $row['account_name'] ?? '' }}" required></td>
                    <td><input class="form-control" name="ads[{{$index}}][account_number]" value="{{ $row['account_number'] ?? '' }}" required></td>
                    <td><input class="form-control money-input js-book-spend" type="number" min="0" step="0.0001" name="ads[{{$index}}][book_spend]" value="{{ $row['book_spend'] ?? 0 }}" required></td>
                    <td><input class="form-control money-input js-coefficient" type="number" min="0" step="0.0001" name="ads[{{$index}}][settlement_coefficient]" value="{{ $row['settlement_coefficient'] ?? 0 }}" required></td>
                    <td><strong class="js-actual-spend">${{ number_format((float)($row['actual_spend'] ?? 0),2) }}</strong></td>
                    <td class="shot-cell">@if($existing)<img class="erp-zoomable" src="{{ route('assistant.operation-report-reviews.screenshot',['report_id'=>$report->id,'path'=>$existing]) }}" alt="广告截图"><input type="hidden" name="ads[{{$index}}][existing_screenshot]" value="{{$existing}}">@endif<input type="file" name="ads[{{$index}}][screenshot]" accept="image/jpeg,image/png,image/webp"></td>
                    <td class="delete-cell"><button type="button" class="btn btn-danger btn-sm js-delete-ad" data-index="{{$index}}"><i class="fa fa-trash"></i> 删除</button></td>
                </tr>
            @endforeach
            </tbody></table></div>
            <div class="edit-actions"><span class="text-danger js-delete-message" style="margin-right:auto"></span><a class="btn btn-default" href="{{ route('assistant.operation-report-reviews.show',$report) }}">取消</a><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> 保存修改</button></div>
        </form>
    </div>
</section>
@endsection
@push('scripts')
<script>
(function(){var body=document.querySelector('.assistant-ad-edit tbody'),deleted=document.getElementById('assistant-deleted-ad-inputs'),message=document.querySelector('.assistant-ad-edit .js-delete-message');if(!body||!deleted)return;function calculate(row){var spend=parseFloat(row.querySelector('.js-book-spend').value)||0,rate=parseFloat(row.querySelector('.js-coefficient').value)||0;row.querySelector('.js-actual-spend').textContent='$'+(spend*(1+rate)).toFixed(2);}body.querySelectorAll('tr').forEach(function(row){row.querySelectorAll('.js-book-spend,.js-coefficient').forEach(function(input){input.addEventListener('input',function(){calculate(row);});});row.querySelector('.js-delete-ad').addEventListener('click',function(){if(body.querySelectorAll('tr').length<=1){if(message)message.textContent='至少需要保留一条广告记录。';return;}var input=document.createElement('input');input.type='hidden';input.name='deleted_indexes[]';input.value=this.getAttribute('data-index');deleted.appendChild(input);row.remove();if(message)message.textContent='已标记删除，点击“保存修改”后生效。';});calculate(row);});})();
</script>
@endpush
