@extends('portal.layout')
@section('page_title','编辑广告信息')
@push('styles')
<style>
.fashion-ad-edit .box{border:0;border-radius:10px;box-shadow:0 4px 18px rgba(35,67,92,.09);overflow:hidden}.fashion-ad-edit-head{display:flex;align-items:center;justify-content:space-between;gap:15px}.fashion-ad-edit-meta{display:flex;gap:24px;flex-wrap:wrap;padding:14px 17px;border-left:4px solid #3c8dbc;background:#f5fafd;color:#587184}.fashion-ad-edit-meta strong{color:#173e58}.fashion-ad-edit table{margin:0}.fashion-ad-edit th{background:#e9f3f9;color:#28516c;text-align:center;white-space:nowrap}.fashion-ad-edit td{vertical-align:middle!important}.fashion-ad-edit .form-control{min-width:125px;border-color:#d3e1ea;border-radius:5px;box-shadow:none}.fashion-ad-edit .money-input{min-width:105px}.fashion-ad-edit .shot-cell{min-width:135px}.fashion-ad-edit .shot-cell img{display:block;width:76px;height:52px;margin-bottom:7px;border:1px solid #d5e3ec;border-radius:5px;object-fit:cover;cursor:zoom-in}.fashion-ad-edit-actions{display:flex;justify-content:flex-end;gap:8px;padding:15px 18px;background:#f9fbfc}@media(max-width:900px){.fashion-ad-edit-head{align-items:flex-start;flex-direction:column}}
</style>
@endpush
@section('content')
<section class="content-header"><div class="fashion-ad-edit-head"><h1>编辑广告信息</h1><a class="btn btn-default" href="{{route('assistant.fashion-advertising-reviews.show',$report)}}"><i class="fa fa-arrow-left"></i> 返回详情</a></div></section>
<section class="content fashion-ad-edit">
 @if($errors->any())<div class="alert alert-danger"><i class="fa fa-warning"></i> {{$errors->first()}}</div>@endif
 <div class="box">
  <div class="fashion-ad-edit-meta"><span>报表日期：<strong>{{$report->report_date->format('Y-m-d')}}</strong></span><span>运营人员：<strong>{{$report->submitter_name}}</strong></span><span>运营部门：<strong>{{$report->submitter?->departments?->pluck('name')->filter()->unique()->implode('、')?:'—'}}</strong></span></div>
  <form method="post" enctype="multipart/form-data" action="{{route('assistant.fashion-advertising-reviews.update',$report)}}">@csrf @method('put')
   <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>广告代理</th><th>广告社媒</th><th>账户名称</th><th>账户编号</th><th>绑定店铺域名</th><th>账面广告费($)</th><th>结算系数</th><th>实际广告费($)</th><th>广告费截图</th></tr></thead><tbody>
   @foreach($report->adSpendEntries as $index=>$entry)
    <tr>
     <td><input type="hidden" name="entries[{{$index}}][id]" value="{{$entry->id}}"><input class="form-control" name="entries[{{$index}}][agency]" value="{{old("entries.$index.agency",$entry->agency)}}" required></td>
     <td><input class="form-control" name="entries[{{$index}}][media]" value="{{old("entries.$index.media",$entry->media)}}" required></td>
     <td><input class="form-control" name="entries[{{$index}}][account_name]" value="{{old("entries.$index.account_name",$entry->account_name)}}" required></td>
     <td><input class="form-control" name="entries[{{$index}}][account_number]" value="{{old("entries.$index.account_number",$entry->account_number)}}" required></td>
     <td><input class="form-control" name="entries[{{$index}}][store_domain]" value="{{old("entries.$index.store_domain",$entry->store_domain)}}"></td>
     <td><input class="form-control money-input js-book" type="number" min="0" step="0.01" name="entries[{{$index}}][book_spend_usd]" value="{{old("entries.$index.book_spend_usd",$entry->book_spend_usd)}}" required></td>
     <td><input class="form-control money-input js-rate" type="number" min="0" step="0.0001" name="entries[{{$index}}][settlement_coefficient]" value="{{old("entries.$index.settlement_coefficient",$entry->settlement_coefficient)}}" required></td>
     <td><strong class="js-actual">${{number_format((float)$entry->actual_spend_usd,2)}}</strong></td>
     <td class="shot-cell">@if($entry->screenshot_path)<img class="erp-zoomable" src="{{route('assistant.fashion-advertising-reviews.screenshot',['type'=>'advertising','entry'=>$entry->id])}}" alt="广告截图">@endif<input type="file" name="entries[{{$index}}][screenshot]" accept="image/jpeg,image/png,image/webp"></td>
    </tr>
   @endforeach
   </tbody></table></div>
   <div class="fashion-ad-edit-actions"><a class="btn btn-default" href="{{route('assistant.fashion-advertising-reviews.show',$report)}}">取消</a><button class="btn btn-primary" onclick="return confirm('确认保存修改后的广告信息吗？')"><i class="fa fa-save"></i> 保存修改</button></div>
  </form>
 </div>
</section>
@endsection
@push('scripts')
<script>(function(){document.querySelectorAll('.fashion-ad-edit tbody tr').forEach(function(row){function calc(){var book=parseFloat(row.querySelector('.js-book').value)||0,rate=parseFloat(row.querySelector('.js-rate').value)||0;row.querySelector('.js-actual').textContent='$'+(book*(1+rate)).toFixed(2);}row.querySelectorAll('.js-book,.js-rate').forEach(function(input){input.addEventListener('input',calc);});calc();});})();</script>
@endpush
