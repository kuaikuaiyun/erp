@extends('portal.layout')
@section('page_title','每日复盘')
@section('content')
@include('portals.operation-tasks.partials.head')
@php
    $recapRevenue = (float) ($yesterdayRecap?->total_revenue_usd ?? 0);
    $recapSpend = (float) ($yesterdayRecap?->total_spend_usd ?? 0);
    $recapRoi = $recapSpend > 0 ? $recapRevenue / $recapSpend : null;
    $initialMedia = old('media', collect($yesterdayRecap?->media_items ?? [])->map(fn($item) => [
        'name' => in_array($item['name'] ?? '', $mediaOptions, true) ? $item['name'] : '其他',
        'custom_name' => in_array($item['name'] ?? '', $mediaOptions, true) ? '' : ($item['name'] ?? ''),
        'spend' => $item['spend'] ?? 0,
        'orders' => $item['orders'] ?? 0,
        'revenue' => $item['revenue'] ?? 0,
    ])->values()->all());
@endphp
<section class="content ot-page ot-recap-page">
    <div class="ot-head ot-recap-head">
        <div><span class="ot-eyebrow">OPERATIONS / 运营管理</span><h1>每日复盘</h1><small>上班先回顾昨日数据，再明确今天的动作。所有日期按北京时间。</small></div>
        <button type="button" class="btn ot-primary" id="ot-open-recap"><i class="fa fa-plus"></i> {{ $yesterdayRecap ? '修改昨日复盘' : '填写昨日复盘' }}</button>
    </div>

    <div class="ot-recap-kpis">
        <div class="ot-recap-kpi"><span>我的昨日复盘</span><strong>{{ $yesterdayRecap ? '已填写' : '待填写' }}</strong><small>数据日期 {{ $yesterday }} · {{ $user->name ?: $user->username }}</small></div>
        <div class="ot-recap-kpi"><span>昨日估算营业额</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapRevenue,2) : '—' }}</strong><small>统一币种填写</small></div>
        <div class="ot-recap-kpi"><span>昨日估算广告费</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapSpend,2) : '—' }}</strong><small>所选媒体花费自动合计</small></div>
        <div class="ot-recap-kpi"><span>估算ROI · 营业额 / 广告费</span><strong>{{ $recapRoi !== null ? number_format($recapRoi,2) : '—' }}</strong><small>自动计算 · 不等同于净利润</small></div>
    </div>

    <div class="ot-card ot-recap-guide">
        <div class="ot-card-head"><span>复盘内容与今日测试逻辑</span><span class="ot-badge">简短、具体、有依据</span></div>
        <div class="ot-card-body ot-guide-grid">
            <div><b><em>01</em> 昨天最重要的发现是什么？你据此做出什么判断？</b><p>具体到单品、类目、素材或投放：哪些值得继续，哪些需要调整？写出关键数据或观察依据。</p></div>
            <div><b><em>02</em> 今天重点推进什么？相比昨天具体改变什么，为什么？</b><p>写清对象、原来的做法、准备采取的动作及依据。新测试或延续有效方案均可填写。</p></div>
            <div><b><em>03</em> 今天广告准备怎么跑，什么情况下继续、调整或暂停？</b><p>写明媒体、重点单品或类目、素材安排、预算、检查时间和判断条件。</p></div>
            <p class="ot-guide-note">选填：昨天计划但没完成的是什么？卡在哪里，需要什么支持？不要求上传截图或报表。</p>
        </div>
    </div>

    <div class="ot-card ot-recap-form-card" id="ot-recap-form-card" @if(!$errors->any() && !request()->boolean('fill')) hidden @endif>
        <div class="ot-card-head"><span>{{ $yesterdayRecap ? '修改昨日复盘' : '填写昨日复盘' }}</span><button type="button" class="btn btn-default btn-xs" id="ot-close-recap"><i class="fa fa-times"></i> 收起</button></div>
        <div class="ot-card-body"><form class="ot-form" method="post" action="{{ route('operation-tasks.recaps.store') }}">@csrf
            <div class="form-group" style="max-width:260px"><label>数据日期 *</label><input class="form-control" type="date" name="data_date" max="{{ now('Asia/Shanghai')->format('Y-m-d') }}" value="{{ old('data_date',$yesterdayRecap?->data_date?->format('Y-m-d') ?: $yesterday) }}" required></div>
            <div class="form-group"><label>媒体经营数据 *</label><div id="ot-media-list"></div><button type="button" class="btn btn-default btn-sm" id="ot-add-media"><i class="fa fa-plus"></i> 添加媒体</button><p class="ot-hint">媒体ROI＝营业额÷广告费；广告费为0时显示“—”。</p></div>
            <div class="ot-grid">
                <div class="ot-full form-group"><label>1. 昨天最重要的发现是什么？你据此做出什么判断？ *</label><textarea class="form-control" rows="4" name="finding_answer" required>{{ old('finding_answer',$yesterdayRecap?->finding_answer) }}</textarea></div>
                <div class="ot-full form-group"><label>2. 今天重点推进什么？相比昨天具体改变什么，为什么？ *</label><textarea class="form-control" rows="4" name="action_answer" required>{{ old('action_answer',$yesterdayRecap?->action_answer) }}</textarea></div>
                <div class="ot-full form-group"><label>3. 今天广告准备怎么跑，什么情况下继续、调整或暂停？ *</label><textarea class="form-control" rows="4" name="advertising_plan" required>{{ old('advertising_plan',$yesterdayRecap?->advertising_plan) }}</textarea></div>
                <div class="ot-full form-group"><label>未完成事项与所需支持（选填）</label><textarea class="form-control" rows="3" name="support_needed">{{ old('support_needed',$yesterdayRecap?->support_needed) }}</textarea></div>
            </div>
            <button class="btn ot-primary"><i class="fa fa-save"></i> 保存复盘</button>
        </form></div>
    </div>

    <div class="ot-card"><div class="ot-card-head"><span>复盘记录</span><span class="ot-badge">{{ $recaps->total() }} 条</span></div>
        <div class="table-responsive"><table class="table ot-table"><thead><tr><th>数据日期</th><th>运营</th><th>媒体</th><th>总营业额</th><th>总单量</th><th>总广告费</th><th>总ROI</th><th>版本</th></tr></thead><tbody>
        @forelse($recaps as $r)<tr><td>{{ $r->data_date->format('Y-m-d') }}</td><td>{{ $r->user_name }}</td><td>{{ collect($r->media_items)->pluck('name')->implode('、') }}</td><td>${{ number_format($r->total_revenue_usd,2) }}</td><td>{{ $r->total_orders }}</td><td>${{ number_format($r->total_spend_usd,2) }}</td><td>{{ $r->total_spend_usd>0 ? number_format($r->total_revenue_usd/$r->total_spend_usd,2) : '—' }}</td><td>v{{ $r->version }}</td></tr>
        @empty<tr><td colspan="8" class="ot-recap-empty"><p>暂无复盘记录。填写昨日数据、复盘内容与今日测试逻辑，开始积累每日判断。</p><button type="button" class="btn ot-primary ot-open-recap-secondary">填写昨日复盘</button></td></tr>@endforelse
        </tbody></table></div>@if($recaps->hasPages())<div class="box-footer">{{ $recaps->links() }}</div>@endif
    </div>
</section>
@endsection

@push('styles')
<style>
.ot-recap-head{align-items:center}.ot-eyebrow{display:block;margin-bottom:8px;color:#18806f;font-size:11px;font-weight:800;letter-spacing:1.6px}.ot-recap-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.ot-recap-kpi{min-height:126px;padding:21px;background:#fff;border:1px solid #e3e9ec;border-radius:12px;box-shadow:0 8px 24px rgba(27,58,73,.05)}.ot-recap-kpi span,.ot-recap-kpi small{display:block;color:#768994}.ot-recap-kpi strong{display:block;margin:10px 0;font-size:25px;color:#162d38}.ot-guide-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px 34px}.ot-guide-grid b{color:#233b46}.ot-guide-grid em{display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;margin-right:8px;border-radius:5px;background:#e4f4f0;color:#19806e;font-style:normal;font-size:11px}.ot-guide-grid p{margin:9px 0 0;color:#71838c;line-height:1.7}.ot-guide-note{grid-column:1/-1}.ot-recap-form-card{scroll-margin-top:20px}.ot-recap-empty{padding:48px!important;text-align:center;color:#778a94}.ot-recap-empty p{margin-bottom:18px}@media(max-width:1000px){.ot-recap-kpis{grid-template-columns:repeat(2,1fr)}.ot-guide-grid{grid-template-columns:1fr}}@media(max-width:600px){.ot-recap-kpis{grid-template-columns:1fr}}
</style>
@endpush

@push('scripts')
<script>
(function(){
    var media=@json($mediaOptions), initial=@json($initialMedia), list=document.getElementById('ot-media-list'), add=document.getElementById('ot-add-media'), card=document.getElementById('ot-recap-form-card'), index=0;
    function escapeHtml(value){var div=document.createElement('div');div.textContent=value==null?'':String(value);return div.innerHTML}
    function row(data){data=data||{};var i=index++,el=document.createElement('div');el.className='ot-media-row';el.innerHTML='<div><select class="form-control media-name" name="media['+i+'][name]" required><option value="">选择媒体</option>'+media.map(function(x){return '<option value="'+escapeHtml(x)+'" '+(x===data.name?'selected':'')+'>'+escapeHtml(x)+'</option>'}).join('')+'</select><input class="form-control other-name" name="media['+i+'][custom_name]" placeholder="填写其他媒体名称" value="'+escapeHtml(data.custom_name||'')+'" style="margin-top:6px;display:'+(data.name==='其他'?'block':'none')+'"></div><input class="form-control" type="number" min="0" step="0.01" name="media['+i+'][spend]" value="'+escapeHtml(data.spend==null?'':data.spend)+'" placeholder="昨日广告费" required><input class="form-control" type="number" min="0" step="1" name="media['+i+'][orders]" value="'+escapeHtml(data.orders==null?'':data.orders)+'" placeholder="昨日单量" required><input class="form-control" type="number" min="0" step="0.01" name="media['+i+'][revenue]" value="'+escapeHtml(data.revenue==null?'':data.revenue)+'" placeholder="昨日营业额" required><button type="button" class="btn btn-default remove-media"><i class="fa fa-trash-o"></i></button>';list.appendChild(el);var other=el.querySelector('.other-name');other.required=data.name==='其他'}
    function open(){card.hidden=false;card.scrollIntoView({behavior:'smooth',block:'start'})}
    document.getElementById('ot-open-recap').addEventListener('click',open);document.querySelectorAll('.ot-open-recap-secondary').forEach(function(button){button.addEventListener('click',open)});document.getElementById('ot-close-recap').addEventListener('click',function(){card.hidden=true});add.addEventListener('click',function(){row({})});list.addEventListener('change',function(e){if(e.target.classList.contains('media-name')){var other=e.target.parentNode.querySelector('.other-name');other.style.display=e.target.value==='其他'?'block':'none';other.required=e.target.value==='其他'}});list.addEventListener('click',function(e){var b=e.target.closest('.remove-media');if(b&&list.children.length>1)b.closest('.ot-media-row').remove()});(initial.length?initial:[{}]).forEach(row);if(!card.hidden)card.scrollIntoView({block:'start'});
})();
</script>
@endpush
