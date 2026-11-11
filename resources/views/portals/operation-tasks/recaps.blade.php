@extends('portal.layout')

@section('page_title', '每日复盘')

@section('content')
@include('portals.operation-tasks.partials.head')
@php
    $recapRevenue = (float) ($yesterdayRecap?->total_revenue_usd ?? 0);
    $recapSpend = (float) ($yesterdayRecap?->total_spend_usd ?? 0);
    $recapRoi = $recapSpend > 0 ? $recapRevenue / $recapSpend : null;
    $formRecap = $editingRecap;
    $initialMedia = old('media', collect($formRecap?->media_items ?? [])->map(fn ($item) => ['name' => in_array($item['name'] ?? '', $mediaOptions, true) ? $item['name'] : '其他', 'custom_name' => in_array($item['name'] ?? '', $mediaOptions, true) ? '' : ($item['name'] ?? ''), 'spend' => $item['spend'] ?? 0, 'orders' => $item['orders'] ?? 0, 'revenue' => $item['revenue'] ?? 0])->values()->all());
    $savedAnswers = collect($formRecap?->question_answers ?? [])->keyBy('question_id');
    $legacyAnswer = fn ($question) => match ($question->code) {
        'finding' => $formRecap?->finding_answer,
        'action' => $formRecap?->action_answer,
        'advertising' => $formRecap?->advertising_plan,
        'support' => $formRecap?->support_needed,
        default => '',
    };
@endphp

<section class="content ot-page ot-recap-page">
    <div class="ot-simple-page-head">
        <div><h1>每日复盘</h1><p>填写昨日数据，再明确今天的动作。</p></div>
        <button type="button" class="btn ot-primary" id="ot-open-recap"><i class="fa fa-plus"></i> {{ $yesterdayRecap ? '修改昨日复盘' : '填写昨日复盘' }}</button>
    </div>

    <div class="ot-recap-kpis">
        <div class="ot-recap-kpi"><span>我的昨日复盘</span><strong>{{ $yesterdayRecap ? '已填写' : '待填写' }}</strong><small>数据日期 {{ $yesterday }} · {{ $user->name ?: $user->username }}</small></div>
        <div class="ot-recap-kpi"><span>昨日估算营业额</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapRevenue, 2) : '—' }}</strong><small>统一 USD 填写</small></div>
        <div class="ot-recap-kpi"><span>昨日估算广告费</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapSpend, 2) : '—' }}</strong><small>所选媒体花费自动合计</small></div>
        <div class="ot-recap-kpi"><span>估算 ROI · 营业额 / 广告费</span><strong>{{ $recapRoi !== null ? number_format($recapRoi, 2) : '—' }}</strong><small>自动计算，不等同于净利润</small></div>
    </div>

    <div class="ot-card ot-recap-guide">
        <div class="ot-card-head"><span>复盘内容与今日测试逻辑</span><div>@if($canManageRecapQuestions)<button type="button" class="btn btn-default btn-xs" data-toggle="modal" data-target="#ot-question-modal"><i class="fa fa-cog"></i> 管理问题</button>@endif <span class="ot-badge">简短、具体、有依据</span></div></div>
        <div class="ot-card-body ot-guide-grid">
            @forelse($recapQuestions as $index => $question)
                <div class="ot-guide-question"><b><em>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</em>{{ $question->title }}@if($question->is_required) <span class="text-danger">*</span>@endif</b>@if($question->description)<p>{{ $question->description }}</p>@endif</div>
            @empty
                <p class="ot-guide-note">当前未配置复盘问题。系统管理员可点击“管理问题”新增。</p>
            @endforelse
        </div>
    </div>

    <div class="ot-card"><div class="ot-card-head"><span>复盘记录</span><span class="ot-badge">{{ $recaps->total() }} 条</span></div><div class="table-responsive"><table class="table ot-table ot-recap-table"><thead><tr><th>数据日期</th><th>运营</th><th>媒体</th><th>总营业额</th><th>总单量</th><th>总广告费</th><th>ROI</th><th>版本</th><th>操作</th></tr></thead><tbody>@forelse($recaps as $recap)<tr><td><b>{{ $recap->data_date->format('Y-m-d') }}</b></td><td>{{ $recap->user_name }}</td><td>{{ collect($recap->media_items)->pluck('name')->implode('、') }}</td><td>${{ number_format($recap->total_revenue_usd, 2) }}</td><td>{{ $recap->total_orders }}</td><td>${{ number_format($recap->total_spend_usd, 2) }}</td><td>{{ $recap->total_spend_usd > 0 ? number_format($recap->total_revenue_usd / $recap->total_spend_usd, 2) : '—' }}</td><td>v{{ $recap->version }}</td><td><button type="button" class="btn btn-default btn-xs" data-toggle="modal" data-target="#ot-recap-detail-{{ $recap->id }}">查看详情</button>@if((int) $recap->user_id === (int) $user->id) <a class="btn btn-default btn-xs" href="{{ route('operation-tasks.recaps.index', ['edit' => $recap->id]) }}">编辑</a>@endif</td></tr>@empty<tr><td colspan="9" class="ot-recap-empty"><p>暂无复盘记录。填写后会在这里保留历史。</p><button type="button" class="btn ot-primary ot-open-recap-secondary">填写昨日复盘</button></td></tr>@endforelse</tbody></table></div>@if($recaps->hasPages())<div class="box-footer">{{ $recaps->links() }}</div>@endif</div>

    @foreach($recaps as $recap)
        @php
            $detailAnswers = $recap->question_answers ?: [];
        @endphp
        <div class="modal fade ot-modal ot-recap-detail-modal" id="ot-recap-detail-{{ $recap->id }}" tabindex="-1">
            <div class="modal-dialog modal-lg"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $recap->data_date->format('Y-m-d') }} 复盘 · {{ $recap->user_name }}</h4><p>当前版本 v{{ $recap->version }} · 更新于 {{ optional($recap->updated_at)->format('Y-m-d H:i') }}</p></div>
                <div class="modal-body">
                    <div class="table-responsive ot-recap-detail-media"><table class="table ot-table"><thead><tr><th>媒体</th><th>花费</th><th>单量</th><th>营业额</th><th>ROI</th></tr></thead><tbody>
                        @foreach($recap->media_items as $item)
                            <tr><td>{{ $item['name'] ?? '—' }}</td><td>${{ number_format($item['spend'] ?? 0, 2) }}</td><td>{{ $item['orders'] ?? 0 }}</td><td>${{ number_format($item['revenue'] ?? 0, 2) }}</td><td>{{ isset($item['roi']) ? number_format($item['roi'], 2) : '—' }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="ot-recap-answer ot-recap-detail-answers">
                        @if(count($detailAnswers))
                            @foreach($detailAnswers as $answer)
                                <section class="ot-recap-answer-card"><b>{{ $answer['title'] ?? '复盘问题' }}</b><p>{{ $answer['answer'] ?? '—' }}</p></section>
                            @endforeach
                        @else
                            <section class="ot-recap-answer-card"><b>重要发现与判断</b><p>{{ $recap->finding_answer }}</p></section>
                            <section class="ot-recap-answer-card"><b>今日重点推进</b><p>{{ $recap->action_answer }}</p></section>
                            <section class="ot-recap-answer-card"><b>广告计划</b><p>{{ $recap->advertising_plan }}</p></section>
                            @if($recap->support_needed)
                                <section class="ot-recap-answer-card"><b>未完成事项与支持</b><p>{{ $recap->support_needed }}</p></section>
                            @endif
                        @endif
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">关闭</button></div>
            </div></div>
        </div>
    @endforeach
</section>

<div class="modal fade ot-modal" id="ot-recap-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form class="ot-form" method="post" action="{{ route('operation-tasks.recaps.store') }}">@csrf<div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $formRecap ? '编辑复盘' : '填写昨日复盘' }}</h4><p>保存时按“当前用户 + 数据日期”更新；历史版本会保留。</p></div><div class="modal-body"><div class="form-group" style="max-width:260px"><label>数据日期 *</label><input class="form-control" type="date" name="data_date" max="{{ now('Asia/Shanghai')->format('Y-m-d') }}" value="{{ old('data_date', $formRecap?->data_date?->format('Y-m-d') ?: $yesterday) }}" required></div><div class="form-group"><label>媒体经营数据 *</label><div id="ot-media-list"></div><button type="button" class="btn btn-default btn-sm" id="ot-add-media"><i class="fa fa-plus"></i> 添加媒体</button></div><div class="ot-grid">@foreach($recapQuestions as $question)@php($saved = $savedAnswers->get($question->id))<div class="ot-full form-group"><label>{{ $question->title }} @if($question->is_required)*@endif</label>@if($question->description)<p class="help-block">{{ $question->description }}</p>@endif<textarea class="form-control" rows="{{ $question->is_required ? 4 : 3 }}" name="answers[{{ $question->id }}]" @if($question->is_required) required @endif>{{ old('answers.'.$question->id, $saved['answer'] ?? $legacyAnswer($question)) }}</textarea></div>@endforeach</div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存复盘</button></div></form></div></div></div>

@if($canManageRecapQuestions)
<div class="modal fade ot-modal" id="ot-question-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form class="ot-form" method="post" action="{{ route('operation-tasks.recaps.questions.store') }}">@csrf<div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">管理复盘问题</h4><p>拖动排序暂未启用；按当前显示顺序保存。删除后不影响历史复盘快照。</p></div><div class="modal-body"><div id="ot-question-list">@foreach($allRecapQuestions as $question)<div class="ot-question-row"><input type="hidden" name="questions[{{ $loop->index }}][id]" value="{{ $question->id }}"><div class="ot-question-number">{{ $loop->iteration }}</div><div><label>问题 *</label><input class="form-control" name="questions[{{ $loop->index }}][title]" value="{{ $question->title }}" required></div><div><label>说明</label><textarea class="form-control" rows="2" name="questions[{{ $loop->index }}][description]">{{ $question->description }}</textarea></div><label class="ot-question-required"><input type="checkbox" name="questions[{{ $loop->index }}][is_required]" value="1" @checked($question->is_required)> 必填</label><button type="button" class="btn btn-default btn-sm ot-remove-question"><i class="fa fa-trash-o"></i></button></div>@endforeach</div><button type="button" class="btn btn-default" id="ot-add-question"><i class="fa fa-plus"></i> 新增问题</button></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存问题模板</button></div></form></div></div></div>
@endif
@endsection

@push('styles')<style>.ot-recap-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.ot-recap-kpi{min-height:126px;padding:21px;background:#fff;border:1px solid #e3e9ec;border-radius:12px;box-shadow:0 8px 24px rgba(27,58,73,.05)}.ot-recap-kpi span,.ot-recap-kpi small{display:block;color:#768994}.ot-recap-kpi strong{display:block;margin:10px 0;font-size:25px;color:#162d38}.ot-guide-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px 34px}.ot-guide-grid em{display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;margin-right:8px;border-radius:5px;background:#e4f4f0;color:#19806e;font-style:normal;font-size:11px}.ot-guide-question p,.ot-recap-answer p{margin:9px 0 16px;color:#71838c;line-height:1.7;white-space:pre-wrap}.ot-recap-empty{padding:48px!important;text-align:center;color:#778a94}.ot-question-row{display:grid;grid-template-columns:30px minmax(180px,1fr) minmax(220px,1.4fr) 52px 36px;gap:10px;align-items:end;padding:12px 0;border-bottom:1px solid #edf1f3}.ot-question-number{padding-bottom:10px;color:#16806f;font-weight:700}.ot-question-required{padding-bottom:10px;white-space:nowrap}.ot-question-row .btn{margin-bottom:0}@media(max-width:1000px){.ot-recap-kpis{grid-template-columns:repeat(2,1fr)}.ot-guide-grid{grid-template-columns:1fr}}@media(max-width:700px){.ot-recap-kpis{grid-template-columns:1fr}.ot-question-row{grid-template-columns:1fr}.ot-question-number{display:none}}</style>@endpush
@push('styles')
<style>
#ot-question-modal .modal-dialog{width:min(1080px,calc(100vw - 40px));margin:32px auto}#ot-question-modal .modal-content{border:0;border-radius:14px;overflow:hidden;box-shadow:0 22px 58px rgba(12,37,48,.28)}#ot-question-modal .modal-header{padding:21px 26px 17px;background:linear-gradient(135deg,#f9fcfc,#f1f8f7);border-bottom:1px solid #dbe9e6}#ot-question-modal .modal-title{font-size:20px;color:#173e4d;font-weight:700}#ot-question-modal .modal-header p{margin:7px 0 0;color:#69808a;font-size:13px}#ot-question-modal .modal-body{max-height:calc(100vh - 250px);padding:8px 26px 20px;overflow:auto}#ot-question-modal .modal-footer{padding:15px 26px;background:#fbfcfc;border-top:1px solid #e7eeef}.ot-question-row{grid-template-columns:42px minmax(260px,1fr) minmax(340px,1.35fr) 68px 42px;gap:14px;padding:18px 0;align-items:center}.ot-question-row>div{min-width:0}.ot-question-row label{margin:0 0 7px;color:#4d6976;font-size:12px;font-weight:700}.ot-question-number{display:flex;align-items:center;justify-content:center;width:30px;height:30px;margin-top:18px;padding:0;border-radius:50%;background:#e6f5f1;color:#14806e;font-size:13px}.ot-question-required{display:flex;align-items:center;gap:4px;margin:22px 0 0!important;padding:0;color:#48636e!important;font-size:13px!important;font-weight:600!important}.ot-question-required input{margin:0}.ot-question-row .ot-remove-question{width:36px;height:34px;margin-top:19px;border-color:#e6dfe0;color:#a55b5b}.ot-question-row .form-control{border-color:#d8e4e7;box-shadow:none}.ot-question-row textarea.form-control{min-height:54px;resize:vertical}#ot-add-question{margin-top:14px;border-style:dashed;color:#197a6c;background:#f7fcfb}#ot-question-modal .modal-footer .btn{min-width:96px}@media(max-width:800px){#ot-question-modal .modal-dialog{width:calc(100vw - 20px);margin:12px auto}.ot-question-row{grid-template-columns:36px 1fr}.ot-question-row>div:nth-of-type(2){grid-column:2}.ot-question-required,.ot-question-row .ot-remove-question{margin-top:0}.ot-question-number{margin-top:0}}
</style>
@endpush
@push('styles')
<style>
.ot-recap-table td:last-child{white-space:nowrap}.ot-recap-table td:last-child .btn{display:inline-flex;align-items:center;justify-content:center;min-width:76px;height:30px;margin:1px 3px 1px 0;padding:0 12px;border-color:#bfd6e1;background:#f7fbfd;color:#236888;font-size:12px;font-weight:700;line-height:1}.ot-recap-table td:last-child .btn:hover,.ot-recap-table td:last-child .btn:focus{border-color:#298abe;background:#eaf6fb;color:#155979}.ot-recap-detail-modal .modal-dialog{width:min(920px,calc(100vw - 40px));margin:32px auto}.ot-recap-detail-modal .modal-content{overflow:hidden;border:0;border-radius:14px;box-shadow:0 22px 60px rgba(12,37,48,.3)}.ot-recap-detail-modal .modal-header{padding:20px 25px 16px;border-bottom:1px solid #d9e7ea;background:linear-gradient(135deg,#f8fcfd,#edf8f7)}.ot-recap-detail-modal .modal-title{color:#153d54;font-size:20px;font-weight:700}.ot-recap-detail-modal .modal-header p{margin:7px 0 0;color:#708894;font-size:13px}.ot-recap-detail-modal .modal-body{max-height:calc(100vh - 230px);padding:20px 25px;overflow:auto}.ot-recap-detail-modal .modal-footer{padding:13px 25px;border-top:1px solid #e4edef;background:#fafcfc}.ot-recap-detail-media{margin-bottom:18px;border:1px solid #deeaed;border-radius:9px;overflow:hidden}.ot-recap-detail-media .table{margin:0}.ot-recap-detail-media th{background:#f3f8f9;color:#55717e}.ot-recap-detail-answers{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ot-recap-answer-card{min-height:112px;padding:14px 15px;border:1px solid #dce9ec;border-radius:9px;background:#fcfefe}.ot-recap-answer-card b{display:block;color:#254d61;font-size:13px}.ot-recap-answer-card p{margin:8px 0 0!important;color:#607884!important;font-size:13px;line-height:1.65!important}.ot-question-row{min-height:88px;align-items:stretch}.ot-question-row>div{display:flex;flex-direction:column}.ot-question-row .form-control{height:54px}.ot-question-row textarea.form-control{height:54px;min-height:54px;resize:vertical}.ot-question-row .ot-question-number{align-self:center;margin-top:19px}.ot-question-row .ot-question-required,.ot-question-row .ot-remove-question{align-self:center;margin-top:19px}@media(max-width:700px){.ot-recap-detail-modal .modal-dialog{width:calc(100vw - 18px);margin:10px auto}.ot-recap-detail-answers{grid-template-columns:1fr}.ot-question-row .form-control{height:auto;min-height:44px}.ot-question-row textarea.form-control{height:64px;min-height:64px}}
</style>
@endpush
@push('scripts')<script>(function(){var media=@json($mediaOptions),initial=@json($initialMedia),list=document.getElementById('ot-media-list'),index=0;function esc(v){var x=document.createElement('div');x.textContent=v==null?'':String(v);return x.innerHTML}function row(data){data=data||{};var i=index++,el=document.createElement('div');el.className='ot-media-row';el.innerHTML='<div><select class="form-control media-name" name="media['+i+'][name]" required><option value="">选择媒体</option>'+media.map(function(x){return '<option value="'+esc(x)+'" '+(x===data.name?'selected':'')+'>'+esc(x)+'</option>'}).join('')+'</select><input class="form-control other-name" name="media['+i+'][custom_name]" placeholder="填写其他媒体名称" value="'+esc(data.custom_name||'')+'" style="margin-top:6px;display:'+(data.name==='其他'?'block':'none')+'"></div><input class="form-control" type="number" min="0" step="0.01" name="media['+i+'][spend]" value="'+esc(data.spend==null?'':data.spend)+'" placeholder="广告费" required><input class="form-control" type="number" min="0" step="1" name="media['+i+'][orders]" value="'+esc(data.orders==null?'':data.orders)+'" placeholder="单量" required><input class="form-control" type="number" min="0" step="0.01" name="media['+i+'][revenue]" value="'+esc(data.revenue==null?'':data.revenue)+'" placeholder="营业额" required><button type="button" class="btn btn-default remove-media"><i class="fa fa-trash-o"></i></button>';list.appendChild(el)}document.getElementById('ot-open-recap').onclick=function(){$('#ot-recap-modal').modal('show')};document.querySelectorAll('.ot-open-recap-secondary').forEach(function(b){b.onclick=function(){$('#ot-recap-modal').modal('show')}});document.getElementById('ot-add-media').onclick=function(){row({})};list.addEventListener('change',function(e){if(e.target.classList.contains('media-name')){var input=e.target.parentNode.querySelector('.other-name'),show=e.target.value==='其他';input.style.display=show?'block':'none';input.required=show}});list.addEventListener('click',function(e){var button=e.target.closest('.remove-media');if(button&&list.children.length>1)button.closest('.ot-media-row').remove()});(initial.length?initial:[{}]).forEach(row);var questionList=document.getElementById('ot-question-list'),questionIndex=questionList?questionList.querySelectorAll('.ot-question-row').length:0,addQuestion=document.getElementById('ot-add-question');if(addQuestion){addQuestion.onclick=function(){var i=questionIndex++,el=document.createElement('div');el.className='ot-question-row';el.innerHTML='<input type="hidden" name="questions['+i+'][id]" value=""><div class="ot-question-number">新</div><div><label>问题 *</label><input class="form-control" name="questions['+i+'][title]" required></div><div><label>说明</label><textarea class="form-control" rows="2" name="questions['+i+'][description]"></textarea></div><label class="ot-question-required"><input type="checkbox" name="questions['+i+'][is_required]" value="1" checked> 必填</label><button type="button" class="btn btn-default btn-sm ot-remove-question"><i class="fa fa-trash-o"></i></button>';questionList.appendChild(el)};questionList.addEventListener('click',function(e){var button=e.target.closest('.ot-remove-question');if(button)button.closest('.ot-question-row').remove()})}@if($errors->any() || $openRecapModal)$('#ot-recap-modal').modal('show');@endif})()</script>@endpush
