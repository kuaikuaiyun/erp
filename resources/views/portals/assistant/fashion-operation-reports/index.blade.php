@extends('portal.layout')
@section('page_title','服装运营报表')
@push('styles')
<style>
.fashion-report-filter,.fashion-report-box{border:0;border-radius:12px;box-shadow:0 5px 19px rgba(28,65,92,.09);overflow:hidden}.report-filter{display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap}.report-filter .form-group{margin:0}.report-filter label{display:block;margin-bottom:5px;color:#688092;font-size:12px}.report-filter .form-control{height:37px;border-radius:6px;min-width:145px}.report-download-button{height:37px;border:0;border-radius:6px;background:#19a974;color:#fff;box-shadow:0 3px 9px rgba(25,169,116,.2)}.report-download-button:hover,.report-download-button:focus{background:#138b60;color:#fff}.report-download-button[disabled]{opacity:.65}.report-summary{display:grid;grid-template-columns:repeat(7,minmax(125px,1fr));gap:11px;margin:15px 0}.report-card{padding:14px 16px;border-left:4px solid #328fc0;border-radius:9px;background:#fff;box-shadow:0 3px 13px rgba(30,65,95,.08)}.report-card span{display:block;color:#718697;font-size:12px}.report-card strong{display:block;margin-top:5px;color:#173f60;font-size:21px}.fashion-report-table{min-width:1480px;margin:0}.fashion-report-table th{padding:10px 8px!important;background:#dcecf6!important;color:#244f6d;text-align:center;white-space:nowrap}.fashion-report-table td{padding:9px 8px!important;vertical-align:middle!important}.fashion-report-table tbody tr:nth-child(odd)>td{background:#f4f9fc}.fashion-report-table tbody tr:nth-child(even)>td{background:#fff8ef}.store-list,.media-list{display:grid;gap:6px;min-width:280px}.store-item,.media-item{padding:7px 9px;border:1px solid #dbe6ed;border-radius:6px;background:rgba(255,255,255,.7)}.media-item{display:grid;grid-template-columns:minmax(90px,1fr) auto auto;gap:12px}.roi{font-weight:700;color:#16805c}.report-note{padding:11px 15px;border-radius:7px;background:#fff7df;color:#765a17;margin-bottom:14px}.empty{padding:50px!important;text-align:center;color:#8295a3}@media(max-width:1000px){.report-summary{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush
@section('content')
<section class="content-header"><h1>服装运营报表 <small>XShoppy 店铺业绩与广告媒体 ROI 统计</small></h1></section>
<section class="content">
<div class="box fashion-report-filter"><div class="box-body"><form class="report-filter" method="get">
 <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div><div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
 <div class="form-group"><label>统计时区</label><select class="form-control" name="timezone">@foreach($timezoneOptions as $value=>$label)<option value="{{ $value }}" @selected($filters['timezone']===$value)>{{ $label }}</option>@endforeach</select></div>
 <div class="form-group"><label>运营部门</label><input class="form-control" name="department" value="{{ $filters['department']??'' }}" placeholder="输入部门"></div><div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $filters['operator']??'' }}" placeholder="输入姓名"></div>
 <button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('assistant.fashion-operation-reports.index') }}">重置</a><button type="button" class="btn report-download-button" id="fashionReportImageDownload"><i class="fa fa-picture-o"></i> <span>下载列表图片</span></button>
</form></div></div>
<div class="report-summary"><div class="report-card"><span>运营人数</span><strong>{{ $summary['people'] }}</strong></div><div class="report-card"><span>店铺数量</span><strong>{{ $summary['stores'] }}</strong></div><div class="report-card"><span>订单量</span><strong>{{ $summary['orders'] }}</strong></div><div class="report-card"><span>总业绩</span><strong>${{ number_format($summary['revenue'],2) }}</strong></div><div class="report-card"><span>客单价</span><strong>{{ $summary['aov']!==null?'$'.number_format($summary['aov'],2):'—' }}</strong></div><div class="report-card"><span>广告费</span><strong>${{ number_format($summary['ad_spend'],2) }}</strong></div><div class="report-card"><span>总 ROI</span><strong>{{ $summary['roi']!==null?number_format($summary['roi'],2):'—' }}</strong></div></div>
<div class="report-note"><i class="fa fa-info-circle"></i> 订单业绩来自运营绑定店铺的所有订单，不限制付款状态；广告费统计审核通过和审核不通过的服装广告费提报，待审核数据暂不计入。因当前未返回订单媒体归属，各媒体 ROI＝该运营总业绩÷该媒体实际广告费；总 ROI＝总业绩÷全部实际广告费。</div>
<div class="box fashion-report-box"><div class="box-header with-border"><h3 class="box-title">运营统计明细 <small>{{ $filters['date_from'] }} 至 {{ $filters['date_to'] }} · {{ $timezoneOptions[$filters['timezone']] }}</small></h3></div><div class="table-responsive"><table class="table table-bordered fashion-report-table"><thead><tr><th>运营部门</th><th>人名</th><th>入职日期</th><th>入职天数</th><th>店铺信息 / 业绩</th><th>订单量</th><th>总业绩($)</th><th>客单价($)</th><th>各媒体 / 广告费 / ROI</th><th>广告费($)</th><th>总 ROI</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row['department'] }}</td><td><b>{{ $row['operator'] }}</b></td><td>{{ $row['hired_at']?:'—' }}</td><td>{{ $row['hire_days']!==null?$row['hire_days'].' 天':'—' }}</td><td><div class="store-list">@foreach($row['stores'] as $store)<div class="store-item"><b>{{ $store['name'] }}</b> <small>{{ $store['domain']?:'—' }}</small><br><span>{{ $store['orders'] }} 单 · ${{ number_format($store['revenue'],2) }}</span></div>@endforeach</div></td><td class="text-right"><b>{{ $row['order_count'] }}</b></td><td class="text-right"><b>${{ number_format($row['revenue'],2) }}</b></td><td class="text-right">{{ $row['aov']!==null?number_format($row['aov'],2):'—' }}</td><td><div class="media-list">@forelse($row['media'] as $media)<div class="media-item"><b>{{ $media['name'] }}</b><span>${{ number_format($media['spend'],2) }}</span><span class="roi">ROI {{ $media['roi']!==null?number_format($media['roi'],2):'—' }}</span></div>@empty<span class="text-muted">暂无已审核的广告费</span>@endforelse</div></td><td class="text-right">${{ number_format($row['ad_spend'],2) }}</td><td class="text-center roi">{{ $row['roi']!==null?number_format($row['roi'],2):'—' }}</td></tr>
@empty<tr><td colspan="11" class="empty"><i class="fa fa-inbox fa-2x"></i><br>当前筛选范围暂无 XShoppy 运营数据</td></tr>@endforelse
</tbody></table></div></div>
</section>
@endsection

@push('scripts')
@php
    $imageFilterSummary = '日期：'.$filters['date_from'].' 至 '.$filters['date_to']
        .'　时区：'.$timezoneOptions[$filters['timezone']]
        .'　运营部门：'.(($filters['department'] ?? '') !== '' ? $filters['department'] : '全部')
        .'　运营人员：'.(($filters['operator'] ?? '') !== '' ? $filters['operator'] : '全部');
    $imageTotalSummary = '运营人数：'.$summary['people']
        .'　店铺数量：'.$summary['stores']
        .'　订单量：'.$summary['orders']
        .'　总业绩：$'.number_format($summary['revenue'], 2)
        .'　广告费：$'.number_format($summary['ad_spend'], 2)
        .'　总 ROI：'.($summary['roi'] !== null ? number_format($summary['roi'], 2) : '—');
@endphp
<script>
(function(){
    var button=document.getElementById('fashionReportImageDownload');
    if(!button)return;
    var rows=@json($rows->values());
    var filterSummary={{ Illuminate\Support\Js::from($imageFilterSummary) }};
    var totalSummary={{ Illuminate\Support\Js::from($imageTotalSummary) }};
    var headers=['运营部门','人名','入职日期','入职天数','店铺信息 / 业绩','订单量','总业绩($)','客单价($)','各媒体 / 广告费 / ROI','广告费($)','总 ROI'];
    var widths=[150,120,125,100,390,100,140,120,390,130,100],padding=34,lineHeight=24;
    function money(value){return '$'+Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function valueRows(row){
        var stores=(row.stores||[]).map(function(store){return (store.name||'—')+'  '+(store.domain||'—')+'\n'+Number(store.orders||0)+' 单 · '+money(store.revenue);});
        var media=(row.media||[]).map(function(item){return (item.name||'未设置媒体')+'  '+money(item.spend)+'  ROI '+(item.roi===null||item.roi===undefined?'—':Number(item.roi).toFixed(2));});
        return [row.department||'—',row.operator||'—',row.hired_at||'—',row.hire_days===null||row.hire_days===undefined?'—':row.hire_days+' 天',stores.length?stores.join('\n────────\n'):'—',String(row.order_count||0),money(row.revenue),row.aov===null||row.aov===undefined?'—':Number(row.aov).toFixed(2),media.length?media.join('\n'):'暂无已审核的广告费',money(row.ad_spend),row.roi===null||row.roi===undefined?'—':Number(row.roi).toFixed(2)];
    }
    function wrap(ctx,text,maxWidth){
        var output=[];String(text==null?'':text).split('\n').forEach(function(paragraph){if(paragraph===''){output.push('');return;}var line='';Array.from(paragraph).forEach(function(char){var next=line+char;if(line&&ctx.measureText(next).width>maxWidth){output.push(line);line=char;}else line=next;});if(line)output.push(line);});return output.length?output:[''];
    }
    button.addEventListener('click',function(){
        if(button.disabled)return;
        if(!rows.length){window.alert('当前筛选范围暂无可下载的数据。');return;}
        button.disabled=true;var label=button.querySelector('span'),original=label.textContent;label.textContent='正在生成高清图片…';
        window.setTimeout(function(){
            try{
                var measure=document.createElement('canvas').getContext('2d');measure.font='500 16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';
                var prepared=rows.map(function(row){var cells=valueRows(row).map(function(text,index){return wrap(measure,text,widths[index]-18);});return {cells:cells,height:Math.max(52,Math.max.apply(null,cells.map(function(lines){return lines.length;}))*lineHeight+18)};});
                var tableWidth=widths.reduce(function(total,width){return total+width;},0),headerTop=150,tableHeaderHeight=48,totalHeight=headerTop+tableHeaderHeight+prepared.reduce(function(total,row){return total+row.height;},0)+padding;
                var ratio=Math.min(2,30000/Math.max(tableWidth+padding*2,totalHeight)),canvas=document.createElement('canvas');canvas.width=Math.ceil((tableWidth+padding*2)*ratio);canvas.height=Math.ceil(totalHeight*ratio);
                var ctx=canvas.getContext('2d');ctx.scale(ratio,ratio);ctx.fillStyle='#f4f8fb';ctx.fillRect(0,0,tableWidth+padding*2,totalHeight);
                ctx.textBaseline='top';ctx.fillStyle='#173d5f';ctx.font='700 30px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.fillText('助理系统 · 服装运营报表（列表）',padding,22);
                ctx.fillStyle='#526f84';ctx.font='500 17px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.fillText(filterSummary,padding,67);
                ctx.fillText(totalSummary,padding,98);
                ctx.fillStyle='#acd4e9';ctx.fillRect(padding,130,tableWidth,4);
                var x=padding;ctx.font='700 16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';headers.forEach(function(header,index){ctx.fillStyle='#dcecf6';ctx.fillRect(x,headerTop,widths[index],tableHeaderHeight);ctx.strokeStyle='#b7ccd9';ctx.strokeRect(x+.5,headerTop+.5,widths[index]-1,tableHeaderHeight-1);ctx.fillStyle='#244f6d';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(header,x+widths[index]/2,headerTop+tableHeaderHeight/2);x+=widths[index];});
                var y=headerTop+tableHeaderHeight;prepared.forEach(function(row,rowIndex){x=padding;row.cells.forEach(function(lines,index){ctx.fillStyle=rowIndex%2===0?'#f4f9fc':'#fff8ef';ctx.fillRect(x,y,widths[index],row.height);ctx.strokeStyle='#c4d5df';ctx.strokeRect(x+.5,y+.5,widths[index]-1,row.height-1);ctx.fillStyle=index===10?'#16805c':'#294b61';ctx.font=(index===1||index===5||index===6||index===10?'700 ':'500 ')+'16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.textAlign=[5,6,7,9,10].indexOf(index)>=0?'center':'left';ctx.textBaseline='top';var start=y+(row.height-lines.length*lineHeight)/2,tx=ctx.textAlign==='center'?x+widths[index]/2:x+9;lines.forEach(function(line,lineIndex){ctx.fillText(line,tx,start+lineIndex*lineHeight);});x+=widths[index];});y+=row.height;});
                var link=document.createElement('a');link.download='服装运营报表_列表_{{ $filters['date_from'] }}_{{ $filters['date_to'] }}.png';link.href=canvas.toDataURL('image/png');document.body.appendChild(link);link.click();link.remove();
            }catch(error){window.alert('图片生成失败，请刷新页面后重试。');if(window.console)console.error(error);}finally{button.disabled=false;label.textContent=original;}
        },30);
    });
})();
</script>
@endpush
