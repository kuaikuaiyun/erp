@extends('portals.finance.layout', ['title' => __('finance.menus.'.$report)])
@include('portals.finance.partials.settlement-dialog-styles')
@push('styles')
<style>
#credit-merchant-empty[hidden], #credit-add-merchant[hidden] { display: none !important; }
.settlement-page .box{border-top:0;border-radius:7px}.settlement-page .box-body{padding:18px 20px}.settlement-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 20px}.settlement-filter label{display:block;font-weight:400;margin-bottom:7px}.settlement-filter .form-control{width:100%;height:35px;background:#f3f6f9}.settlement-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:18px;padding-top:14px;border-top:1px solid #e5edf3}.settlement-actions .form-control{width:120px}.settlement-page .finance-table-wrap{max-height:650px;overflow:auto}.settlement-page .finance-table{width:max-content;min-width:100%;margin:0;border-collapse:separate;border-spacing:0}.settlement-page .finance-table th,.settlement-page .finance-table td{border:1px solid #e1eaf1;padding:11px 14px;text-align:center;white-space:nowrap;min-width:125px;max-width:240px}.settlement-page .finance-table th{position:sticky;top:0;z-index:2}.settlement-page .finance-table td{background:white}.settlement-page .finance-table tr:nth-child(even) td{background:#f8fafc}.settlement-page .finance-table th:first-child,.settlement-page .finance-table td:first-child{position:sticky;left:0;z-index:3;min-width:80px}.settlement-page .finance-table th:first-child{z-index:4}.settlement-edit-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:20px;max-height:58vh;overflow:auto}.settlement-edit-grid label{display:block;font-weight:400}.settlement-edit-grid .form-control{width:100%}.settlement-note{font-size:12px;color:#8192a2;margin:12px 0}.settlement-pending{color:#8e9ba9}@media(max-width:1000px){.settlement-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.settlement-edit-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.settlement-filter,.settlement-edit-grid{grid-template-columns:1fr}}
.paypal-editor{width:1080px;max-height:90vh;overflow:hidden;border-radius:12px;box-shadow:0 24px 80px #102c4833}
.paypal-editor[open]{display:flex;flex-direction:column}.paypal-editor>header{flex-shrink:0;padding:22px 26px;background:#fff}.paypal-editor h3{font-size:19px;font-weight:600;color:#244861}.paypal-editor #settlement-edit-reference{display:block;margin-top:8px;font-size:12px;color:#7b8d9d;overflow-wrap:anywhere}.paypal-editor>form{display:flex;flex-direction:column;min-height:0;margin:0;overflow:hidden}.paypal-editor-body{overflow:auto;min-height:0;padding:20px 26px;background:#f4f7fa;scrollbar-gutter:stable}.paypal-editor-section{margin:0 0 16px;padding:20px;background:#fff;border:1px solid #e3eaf0;border-radius:8px;min-width:0}.paypal-editor-section h4{margin:0 0 20px;font-size:15px;font-weight:600;color:#244861;border-left:3px solid #3498db;padding-left:10px}.paypal-editor-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px 20px}.paypal-editor-field{min-width:0}.paypal-editor-field label{display:block;font-size:13px;font-weight:400;margin:0 0 7px;color:#536b7e}.paypal-editor-field .form-control{width:100%;height:38px;border:1px solid #d6e1ea;border-radius:5px;box-shadow:none;color:#304f66;background:#fff;padding:8px 10px}.paypal-editor-field .form-control:focus{border-color:#3c9bd1;box-shadow:0 0 0 3px #3498db15;outline:0}.paypal-editor-field-wide{grid-column:span 2}.paypal-editor-field-note{grid-column:1/-1}.paypal-editor-field textarea.form-control{height:82px;resize:vertical;min-height:70px}.paypal-editor-help{margin:14px 0 0;font-size:12px;line-height:1.8;color:#7b8d9d}.paypal-editor footer{flex-shrink:0;padding:16px 26px;background:#fff}.paypal-editor footer .btn{border-radius:5px;min-height:36px;padding:7px 16px}
@media(max-width:900px){.paypal-editor-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.paypal-editor{max-width:calc(100vw - 16px);max-height:94vh}.paypal-editor>header{padding:16px}.paypal-editor-body{padding:12px}.paypal-editor-section{padding:15px}.paypal-editor-fields{grid-template-columns:1fr;gap:14px}.paypal-editor-field-wide{grid-column:auto}.paypal-editor footer{padding:12px;gap:8px;flex-wrap:wrap}.paypal-editor footer .btn{padding:7px 10px}}
.settlement-page .finance-table th.settlement-operation,.settlement-page .finance-table td.settlement-operation{position:sticky;right:0;min-width:90px;width:90px;max-width:90px;z-index:3;box-shadow:-4px 0 8px #173f6110;border-left:1px solid #cddde9}
.settlement-page .finance-table th.settlement-operation{z-index:5;background:#e8f2f8}
.settlement-page .finance-table :is(th,td).settlement-paypal-operation{min-width:125px;width:125px;max-width:125px}
.settlement-paypal-detail{position:fixed;inset:0;margin:auto;width:1120px;box-shadow:0 20px 64px #102c4833}
.settlement-paypal-detail[open]{display:flex;flex-direction:column}
.settlement-paypal-detail>header,.settlement-paypal-detail>footer{flex-shrink:0}
.settlement-paypal-detail h3 .fa{margin-right:8px;color:#2985b6}
.settlement-paypal-detail [hidden]{display:none!important}
.settlement-detail-body{min-height:min(240px,45vh);padding:20px;overflow:auto;background:#f4f7fa}
.settlement-detail-state{min-height:200px;display:grid;justify-items:center;align-content:center;gap:10px;color:#526c81;text-align:center}
.settlement-detail-state .fa{font-size:26px;color:#2985b6}
.settlement-detail-state--error .fa{color:#bd4a3e}
.settlement-events-panel{display:grid;gap:16px}
.settlement-detail-section{min-width:0;padding:16px;background:#fff;border:1px solid #dfe8ef;border-radius:7px}
.settlement-detail-section h4{margin:0 0 12px;color:#244861;font-size:15px;font-weight:600}
.settlement-detail-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0}
.settlement-detail-summary>div{min-width:0;padding:10px 12px;background:#f4f8fc;border-radius:5px}
.settlement-detail-section dt{color:#526c81;font-size:12px;font-weight:400}
.settlement-detail-section dd{margin:4px 0 0;overflow-wrap:anywhere}
.settlement-detail-summary dd{color:#244861;font-size:16px;font-weight:600}
.settlement-detail-identifiers{display:flex;flex-wrap:wrap;gap:10px 24px;margin:12px 0 0}
.settlement-detail-identifiers dd:empty::after{content:"—";color:#687d8e}
.settlement-detail-review{margin:12px 0 0;padding:8px 12px;background:#fff7e5;border-radius:5px;color:#765921;overflow-wrap:anywhere}
.settlement-event-list{display:grid;gap:10px;list-style:none;margin:0;padding:0}
.settlement-event{min-width:0;border:1px solid #dce7ef;border-radius:6px;overflow:hidden}
.settlement-event-heading{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:6px 16px;padding:10px 14px;background:#eef4f8;border-bottom:1px solid #dce7ef}
.settlement-event-title,.settlement-event-context{display:flex;align-items:center;flex-wrap:wrap;gap:5px 8px}
.settlement-event-title strong{color:#244861;font-size:14px;overflow-wrap:anywhere}
.settlement-event-code,.settlement-event-category,.settlement-event-context{color:#526c81;font-size:12px}
.settlement-event-code{font-family:monospace}
.settlement-event-context{gap:5px 14px;overflow-wrap:anywhere}
.settlement-event-context small{margin-left:3px}
.settlement-event-grid{display:grid;grid-template-columns:1.45fr 1fr 1fr;gap:0 16px;padding:12px 14px}
.settlement-event-fields{display:grid;gap:4px;margin:0}
.settlement-event-fields>div{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:8px;align-items:baseline}
.settlement-event-fields dd{margin:0}
.settlement-event-money{align-content:start;padding-left:16px;border-left:1px solid #e5edf3;font-variant-numeric:tabular-nums}
.settlement-event-money dd{text-align:right}
.settlement-event-usd-value{color:#22634c;font-size:16px;font-weight:700}
.settlement-event-usd-value.is-negative{color:#a23834}
.settlement-event-more{padding:8px 14px;border-top:1px solid #e5edf3;background:#f8fafc}
.settlement-event-more summary{width:fit-content;cursor:pointer;color:#275d80;font-size:13px}
.settlement-event-field-group{margin:10px 0;padding:12px;background:#fff;border:1px solid #e5edf3;border-radius:5px}
.settlement-event-field-group h5{margin:0 0 10px;color:#244861;font-size:13px;font-weight:600}
.settlement-event-field-group dl{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 16px;margin:0}
.settlement-event-field-group dd{white-space:pre-wrap}
.settlement-event-empty{padding:16px;text-align:center;color:#526c81;background:#f4f8fc;border-radius:5px}
.settlement-detail-status{display:inline-block;padding:2px 6px;background:#fff;border-radius:4px;font-size:12px}
.settlement-detail-status.is-complete{background:#eaf6ef;color:#286747}
.settlement-detail-status.is-pending{background:#fff3d8;color:#765921}
.settlement-detail-status.is-closed{background:#fceceb;color:#923a33}
@media(max-width:600px){.settlement-detail-body{padding:12px}.settlement-detail-section{padding:12px}.settlement-detail-summary{grid-template-columns:1fr}}
@media(max-width:720px){.settlement-event-grid{grid-template-columns:minmax(0,1fr);gap:10px}.settlement-event-money{padding:10px 0 0;border-left:0;border-top:1px solid #e5edf3}.settlement-event-field-group dl{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-reduced-motion:reduce){.settlement-detail-state .fa-spin{animation:none}}
.settlement-page td[data-settlement-column=order_status]{font-weight:600;white-space:normal;min-width:165px}

.finance-delete-dialog{box-sizing:border-box;width:calc(100% - 32px);max-width:460px;max-height:calc(100vh - 32px);overflow:auto;padding:26px;border:1px solid #e4eaf0;border-radius:14px;background:#fff;color:#273849;box-shadow:0 20px 64px rgba(18,39,57,.22);font-family:inherit;font-size:14px;line-height:1.7}
.finance-delete-dialog::backdrop{background:rgba(17,35,51,.42)}
.finance-delete-heading{display:flex;align-items:center;gap:14px;margin-bottom:22px}
.finance-delete-icon{display:flex;align-items:center;justify-content:center;flex-shrink:0;width:46px;height:46px;border-radius:12px;background:#fff0ee;color:#cc493c;font-size:22px}
.finance-delete-heading h3{margin:0 0 3px;font-size:19px;font-weight:600;color:#223647;line-height:1.5}
.finance-delete-heading p{margin:0;color:#b8493e;font-size:12px}
.finance-delete-description{margin:0 0 12px;color:#536576}
.finance-delete-scope{display:grid;gap:7px;padding:14px 16px;border:1px solid #e9eef3;border-radius:8px;background:#f7f9fc;color:#42576a;font-size:13px}
.finance-delete-scope span::before{content:'•';margin-right:9px;color:#8fa0b1}
.finance-delete-hint{margin:12px 0 22px;color:#788897;font-size:12px}
.finance-delete-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:18px;border-top:1px solid #edf1f5}
.finance-delete-actions .btn{min-width:88px;padding:8px 16px;border-radius:6px;font-size:13px}
.finance-delete-actions .btn-danger{background:#d64c40;border-color:#d64c40}
.finance-delete-actions .btn-danger:hover{background:#bd3d32;border-color:#bd3d32}
.finance-delete-actions .btn:focus-visible{outline:3px solid #98bce4;outline-offset:3px}
#settlement-delete-confirm{margin:auto;position:fixed;inset:0}
</style>
@endpush
@section('finance_content')
@php
    $hasOperations = $orderView || $canManage;
    $operationClass = $orderView ? 'settlement-operation settlement-paypal-operation' : 'settlement-operation';
    $cardPlatformOptions = \App\Portals\Finance\FinanceCreditSettlementLayout::CHANNELS;
    if ($fixedCardChannel) {
        $cardPlatformOptions = [$fixedCardChannel => $cardPlatformOptions[$fixedCardChannel]];
    }
    $cardChannel = $channel === 'credit_card' ? ($filters['card_channel'] ?? null) : null;
@endphp
<div class="settlement-page">
<div class="box"><div class="box-body"><form method="get" action="{{ route('finance.report', $report) }}" id="settlement-filter">
<input type="hidden" name="per_page" value="{{ $rows->perPage() }}">
<div class="settlement-filter">
@if($channel === 'credit_card')
<div>
    <label for="settlement-card-group">归属主体</label>
    <select id="settlement-card-group" name="card_group" class="form-control" onchange="this.form.elements.account.value='';this.form.requestSubmit()">
        <option value="">全部主体</option>
        @foreach($cardGroups as $groupId => $groupName)
            <option value="{{ $groupId }}" @selected((string) ($filters['card_group'] ?? '') === (string) $groupId)>{{ $groupName }}</option>
        @endforeach
    </select>
</div>
<div>
    <label for="settlement-card-channel">支付平台</label>
    <select id="settlement-card-channel" name="card_channel" class="form-control" onchange="this.form.elements.account.value='';this.form.requestSubmit()">
        @unless($fixedCardChannel)<option value="">全部平台</option>@endunless
        @foreach($cardPlatformOptions as $key => $label)
        <option value="{{ $key }}" @selected(($filters['card_channel'] ?? '') === $key)>{{ $label }}</option>
        @endforeach
    </select>
</div>
@endif
<div><label for="settlement-account">{{ $channel === 'credit_card' ? '商户账户' : '支付账户' }}</label><select id="settlement-account" name="account" class="form-control"><option value="">{{ $channel === 'credit_card' ? '全部商户' : '全部账户' }}</option>@foreach($filterAccounts as $account)<option value="{{ $account->activity_account }}" @selected(($filters['account'] ?? '') === $account->activity_account)>{{ \App\Portals\Finance\FinanceCatalog::simplified($account->account_name) }}</option>@endforeach</select></div>
<div><label for="settlement-from">开始日期</label><input id="settlement-from" class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
<div><label for="settlement-to">结束日期</label><input id="settlement-to" class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
<div><label for="settlement-zone">时区</label><select id="settlement-zone" name="timezone" class="form-control">@include('portals.finance.partials.timezone-options', ['selectedTimezone' => $filters['timezone']])</select></div>
<div><label for="settlement-reference">交易ID</label><input id="settlement-reference" class="form-control" name="reference" value="{{ $filters['reference'] ?? '' }}"></div>
@if($orderView)
<div><label for="settlement-order-status">当前订单状态</label><select id="settlement-order-status" name="order_status" class="form-control">
<option value="">全部状态</option>@foreach(['已付款', '付款失败', '付款待确认', '全额退款', '部分退款', '退款待确认', '仍有冻结', '冻结已解除', '返还待核对', '待关联交易'] as $status)
<option @selected(($filters['order_status'] ?? '') === $status)>{{ $status }}</option>@endforeach
</select></div>
@endif
<div><label for="settlement-team">{{ $orderView ? '运营所属部门' : '团队' }}</label><select id="settlement-team" name="team" class="form-control"><option value="">{{ $orderView ? '全部运营部门' : '全部团队' }}</option>@foreach($teams as $team)<option @selected(($filters['team'] ?? '') === $team)>{{ $team }}</option>@endforeach</select></div>
<div><label for="settlement-subscription">订阅类型</label><input id="settlement-subscription" class="form-control" name="subscription" list="settlement-subscriptions" value="{{ $filters['subscription'] ?? '' }}"></div>
<div><label for="settlement-domain">域名</label><input id="settlement-domain" class="form-control" name="domain" value="{{ $filters['domain'] ?? '' }}"></div>
@if($channel === 'credit_card')<div><label for="settlement-source">数据来源</label><select id="settlement-source" name="source" class="form-control"><option value="">合并去重</option>@foreach(['xuzong2-orders' => '信用卡订单', 'xuzong2-details' => '信用卡导入明细', 'xuzong1-details' => '历史信用卡明细'] as $source => $label)<option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $label }}</option>@endforeach</select></div>@endif
@if($cardChannel === 'ye')
<div><label for="settlement-match">匹配状态</label><select id="settlement-match" name="card_match" class="form-control">
    <option value="">全部状态</option><option value="matched" @selected(($filters['card_match'] ?? '') === 'matched')>已匹配账单</option><option value="waiting" @selected(($filters['card_match'] ?? '') === 'waiting')>待匹配账单</option>
</select></div>
<div><label for="settlement-exceptions">账单筛选</label><select id="settlement-exceptions" name="card_exceptions" class="form-control">
    <option value="">全部交易</option><option value="1" @selected(! empty($filters['card_exceptions']))>仅看结算异常</option>
</select></div>
@endif
@if($channel === 'credit_card')
<div><label for="settlement-card-transaction-status">交易状态</label><select id="settlement-card-transaction-status" name="card_transaction_status" class="form-control">
    <option value="">全部状态</option>
    @foreach(\App\Portals\Finance\FinanceSettlementService::CARD_TRANSACTION_STATUSES as $value => $label)
        <option value="{{ $value }}" @selected(($filters['card_transaction_status'] ?? '') === $value)>{{ $label }}</option>
    @endforeach
</select></div>
@endif
</div>
@include('portals.finance.partials.filter-conditions', ['enhancedConditions' => true, 'hideConditionOperator' => $channel === 'paypal'])
<div class="settlement-actions"><button class="btn btn-primary">查询</button><a class="btn btn-default" href="{{ route('finance.report', $report) }}">重置</a><select class="form-control" name="format" aria-label="导出格式"><option value="xlsx" @selected(($filters['format'] ?? 'xlsx') === 'xlsx')>Excel (.xlsx)</option><option value="xls" @selected(($filters['format'] ?? 'xlsx') === 'xls')>Excel (.xls)</option></select><label class="settlement-template-toolbar">导出模板 <select class="form-control" data-active-export-template aria-label="本次使用的导出模板"><option value="">{{ $orderView ? '按当前列表导出' : '默认字段设置' }}</option></select></label><button type="button" data-finance-download  class="btn btn-default" >导出筛选结果</button>@if($canManage)<button type="button" class="btn btn-default" data-open-settlement-import>导入数据</button> <button type="button" class="btn btn-danger" data-delete-selected-settlements>删除所选</button> @endif</div>
</form></div></div>
<div class="box"><div class="box-body"><div style="display:flex;align-items:center;gap:12px;margin-bottom:14px"><span>共 {{ $rows->total() }} 条</span><div class="settlement-column-settings-trigger">@include('portals.finance.partials.settlement-column-settings', ['columnCardChannel' => $cardChannel])</div></div>
<div class="finance-table-wrap"><table class="table finance-table"><thead><tr><th class="finance-selection"><input type="checkbox" data-export-all aria-label="全选当前页"></th>@foreach($columns as $field => $label)<th data-settlement-column="{{ $field }}">{{ $label }}</th>@endforeach @if($hasOperations)<th class="{{ $operationClass }}">操作</th>@endif</tr></thead><tbody>
@forelse($rows as $row)
@php($editable = $orderView ? ($row['payments'][0] ?? null) : $row)
<tr><td class="finance-selection"><input type="checkbox" data-record-id="{{ $row['id'] }}" @if($orderView) data-payment-ids="{{ json_encode(array_column($row['payments'], 'id')) }}" @endif data-locked="{{ $row['locked'] ? '1' : '0' }}" data-export-row value="{{ \App\Portals\Finance\FinanceExportSelection::key($row) }}" aria-label="选择此行"></td>
@foreach($columns as $field => $label)
@php($splitRefundNote = $orderView ? \App\Portals\Finance\FinancePayPalSettlementOrders::splitRefundNote($row, $field) : null)
@php($cellValue = ($row['card_layout'] ?? $row)[$field] ?? null)
<td data-settlement-column="{{ $field }}" title="{{ $splitRefundNote ?? $cellValue ?? '' }}" @class(['settlement-pending' => $cellValue === null])>
@if($splitRefundNote !== null)
{{ $splitRefundNote }}
@elseif($field === 'ledger_status')
<span @class(['label', 'label-success' => $row['ledger_matched'] ?? false, 'label-default' => empty($row['ledger_matched'])])>{{ $cellValue }}</span>
@elseif(isset($moneyFields[$field]) || in_array($field, ['gross','settlement','source_settlement_usd','converted_usd','paypal_7','paypal_8','paypal_9','payable','open_hold'], true))
{{ $cellValue === null ? ($orderView && in_array($field, ['payable','open_hold'], true) ? '待核对' : '') : \App\Portals\Finance\FinanceCatalog::reportAmount($cellValue) }}
@else{{ \Illuminate\Support\Str::limit(\App\Portals\Finance\FinanceCatalog::simplified((string) ($cellValue ?? '')), 40) }}@endif
</td>
@endforeach
@if($hasOperations)<td class="{{ $operationClass }}">
@if($orderView)
<button type="button" class="btn btn-default btn-xs" aria-haspopup="dialog" data-view-settlement="{{ route('finance.settlement.paypal.detail', ['record' => $row['id'], 'timezone' => $filters['timezone']]) }}">查看</button>
@endif
@if($canManage && $editable && (! $orderView || count($row['payments']) === 1))
    @if($editable['locked'])<span class="settlement-pending">已锁定</span>
    @else @include('portals.finance.partials.settlement-edit-button', ['editable' => $editable])
    @endif
@endif
</td>@endif
</tr>
@empty<tr><td data-settlement-colspan="{{ $hasOperations ? 2 : 1 }}" colspan="{{ count($columns) + ($hasOperations ? 2 : 1) }}">无符合条件的记录</td></tr>@endforelse
</tbody></table></div>@include('portals.finance.pagination', ['paginator' => $rows])
<p class="settlement-note">@if($orderView)每笔付款单独列示。应结算为美元收款金额减去手续费至争议费各列，再加上三项返还。缺少换汇或返还依据的金额待核对。@endif
@if($cardChannel === 'ye')保证金和交易手续费按每笔结算金额及费率计算，处理费按账单核对后填入。费用和返还使用结算币种。应结算按明细公式计算，平台净结算以结算统计为准。
@else 费用和返还金额以美元填写。@endif 物流和采购以人民币填写。金额可按需补充。@if($orderView)使用人优先显示财务补录，其次按下单时间匹配归属历史。已停用店铺的初始归属为空时，可用订单快照或该店紧接着登记的归属补齐；明确清空归属的时段仍留空。查不到历史时，依次取订单归属快照和绑定店铺的归属人。运营所属部门取使用人在助理系统对应的运营部门。@else 使用人和团队优先显示财务补录，未补充时按域名匹配。@endif 已锁定月份不可修改。</p>
</div></div>
@if($orderView)
<dialog class="settlement-dialog settlement-paypal-detail" id="settlement-detail" aria-labelledby="settlement-detail-title">
    <header><h3 id="settlement-detail-title"><i class="fa fa-file-text-o" aria-hidden="true"></i>PayPal 交易明细</h3><button type="button" data-settlement-close aria-label="关闭" autofocus>×</button></header>
    <div class="settlement-detail-body">
        <div class="settlement-detail-state" role="status" data-detail-loading hidden>
            <i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i><span>正在加载交易明细…</span>
        </div>
        <div class="settlement-detail-state settlement-detail-state--error" data-detail-error hidden>
            <i class="fa fa-exclamation-circle" aria-hidden="true"></i><span role="alert">交易明细加载失败</span>
            <button type="button" class="btn btn-default" data-detail-retry>重试</button>
        </div>
        <div data-detail-content hidden></div>
    </div>
    <footer><button type="button" class="btn btn-default" data-settlement-close>关闭</button></footer>
</dialog>
@endif
@if($canManage)
<dialog class="finance-delete-dialog" id="settlement-delete-confirm" aria-labelledby="settlement-delete-title" aria-describedby="settlement-delete-description">
<div class="finance-delete-heading"><span class="finance-delete-icon" aria-hidden="true"><i class="fa fa-trash-o"></i></span><div><h3 id="settlement-delete-title">确认删除结算明细？</h3><p>请核对删除范围后继续</p></div></div>
<p class="finance-delete-description" id="settlement-delete-description">将删除所选的 <strong id="settlement-delete-count"></strong> 条结算明细。</p>
<div class="finance-delete-scope"><span>从结算明细列表及汇总中移除</span><span>原始订单保留，不受影响</span></div>
<div class="finance-delete-actions"><button type="button" class="btn btn-default" data-settlement-delete-cancel autofocus>取消</button><button type="button" class="btn btn-danger" data-settlement-delete-confirm>确认删除</button></div>
</dialog>
<form id="settlement-delete-form" method="post" action="{{ route('finance.settlement.delete', $report) }}" hidden>@csrf @method('DELETE')<div data-delete-ids></div></form>
<dialog @class(['settlement-dialog', 'paypal-editor' => $channel === 'paypal']) id="settlement-editor" aria-label="编辑结算明细"><header><h3>编辑结算明细 <small id="settlement-edit-reference"></small></h3><button type="button" data-settlement-close aria-label="关闭">×</button></header>
<form method="post" action="{{ route('finance.settlement.save', ['report' => $report, ...$filters, 'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null]) }}">@csrf<input type="hidden" name="id">
@if($channel === 'paypal')
@include('portals.finance.partials.paypal-settlement-editor')
@else
<div class="settlement-edit-grid">
@foreach($editorFields as $field => $label)<div><label for="edit-{{ $field }}">{{ $label }}</label>
@if($field === 'timezone')
<select id="edit-timezone" name="timezone" class="form-control">
    <option value="">请选择时区</option>
    @include('portals.finance.partials.timezone-options', ['selectedTimezone' => null])
</select>
@else
<input id="edit-{{ $field }}" name="{{ $field }}" class="form-control" @if(in_array($field, $editorNumericFields, true)) type="number" step="0.00000001" @if(isset($moneyFields[$field]) && $field !== 'usd_rate') data-settlement-cost @endif @else maxlength="{{ $field === 'note' ? 2000 : 191 }}" @endif @if($field === 'subscription') list="settlement-subscriptions" @elseif($field === 'account') list="settlement-accounts" @endif>
@endif
</div>@endforeach
<div style="grid-column:1/-1" class="settlement-note">记录ID由系统生成。交易ID及支付记录须保持唯一；日期时间填写 YYYY-MM-DD HH:mm:ss，月份填写 YYYY-MM。已锁定月份不可修改。</div>
</div>
@endif
<footer><button class="btn btn-primary">保存</button><button type="button" class="btn btn-danger" id="settlement-editor-delete">删除</button><button type="button" class="btn btn-default" data-settlement-close>取消</button></footer></form></dialog>
<dialog class="settlement-dialog" id="settlement-import"><header><h3>导入结算明细</h3><button type="button" data-settlement-close aria-label="关闭">×</button></header><form method="post" action="{{ route('finance.settlement.import', ['report' => $report, 'account' => $filters['account'] ?? null]) }}" enctype="multipart/form-data">@csrf
@if($channel === 'credit_card')
<div class="settlement-edit-grid">
    <div><label for="credit-import-group">归属主体</label><select id="credit-import-group" name="card_group" class="form-control">
        <option value="">全部主体</option>
        @foreach($cardGroups as $groupId => $groupName)
            <option value="{{ $groupId }}" @selected((string) old('card_group', $filters['card_group'] ?? '') === (string) $groupId)>{{ $groupName }}</option>
        @endforeach
    </select></div>
    <div><label for="credit-import-platform">支付平台</label><select id="credit-import-platform" name="card_channel" class="form-control">
        @unless($fixedCardChannel)<option value="">通用信用卡模板</option>@endunless
        @foreach($cardPlatformOptions as $key => $label)
            <option value="{{ $key }}" @selected(old('card_channel', $cardChannel) === $key)>{{ $label }}</option>
        @endforeach
    </select></div>
    <div><label for="credit-manual-account">商户账户</label><select id="credit-manual-account" name="account" class="form-control" required>
        <option value="">请选择商户</option>
        @foreach($accounts as $account)
            <option value="{{ $account->activity_account }}" data-group="{{ $account->card_group_id }}" data-platform="{{ $account->card_platform }}" data-merchant="{{ $account->card_merchant_key }}" @selected(old('account', $filters['account'] ?? '') === $account->activity_account)>{{ $account->account_name }}{{ $account->card_merchant_key ? '（'.$account->card_merchant_key.'）' : '' }}</option>
        @endforeach
    </select>
    <p class="help-block" id="credit-merchant-empty" hidden>当前平台暂无可用商户。</p>
    <a id="credit-add-merchant" href="{{ route('finance.home', ['balance_tab' => 'credit-card']) }}" hidden>到工作台新增商户</a>
    </div>
    <div id="credit-import-timezone-field"><label for="credit-import-timezone">文件时区</label><select id="credit-import-timezone" name="timezone" class="form-control">@include('portals.finance.partials.timezone-options', ['selectedTimezone' => old('timezone', $cardChannel === 'ye' ? 'Asia/Shanghai' : $filters['timezone'])])</select></div>
</div>
@endif
@if($channel === 'paypal')
<div class="settlement-edit-grid">
    <div><label for="paypal-import-kind">文件类型</label><select id="paypal-import-kind" name="import_kind" class="form-control">
        <option value="">新增结算明细</option>
        <option value="tongtu_costs" @selected(old('import_kind') === 'tongtu_costs')>按通途订单导入成本</option>
    </select></div>
</div>
<p class="settlement-note" style="padding:0 20px">新增结算明细时自动跳过重复交易。按通途订单导入成本时，物流费和采购成本只写入匹配到的主付款。</p>
@endif
<label class="settlement-import-drop"><i class="fa fa-cloud-upload" aria-hidden="true"></i><span data-upload-label>拖拽文件到这里，或点击选择文件</span><input type="file" name="file" accept=".xlsx,.csv" required></label>
<p style="padding:0 20px" data-order-template><a href="{{ route('finance.report', ['report' => $report, 'template' => 1, 'card_channel' => $cardChannel]) }}">下载导入模板（XLSX）</a></p>
@if($channel === 'paypal')
<p style="padding:0 20px"><a href="{{ route('finance.report', ['report' => $report, 'template' => 1, 'import_kind' => 'tongtu_costs']) }}">下载通途成本模板（XLSX）</a></p>
@endif
<p class="settlement-note" style="padding:0 20px">
@if($channel === 'credit_card')
    ConfluxAPI 可一次选择最多 5 个文件，系统自动识别订单明细和结算报表。同批文件应属于同一个单包账户，结算报表会核对商户标识。
@endif
支持 XLSX、CSV，最大 10 MB。
</p>
<footer><button type="button" class="btn btn-default" data-settlement-close>取消</button><button class="btn btn-success">确认导入</button></footer></form></dialog>
@endif


<datalist id="settlement-accounts">@foreach($accounts as $account)<option value="{{ $account->account_name }}">{{ $account->activity_account }}</option>@endforeach</datalist>
<datalist id="settlement-subscriptions">@foreach($subscriptionOptions as $type)<option value="{{ $type }}">@endforeach</datalist>
</div>
@endsection
@push('scripts')
<script>
(function(){

var editor=document.getElementById('settlement-editor'),importer=document.getElementById('settlement-import');
var deleteForm=document.getElementById('settlement-delete-form');
var deleteDialog=document.getElementById('settlement-delete-confirm'),pendingDeleteIds=[];
function deleteSettlements(ids){if(!ids.length){alert('请先勾选要删除的记录。');return;}pendingDeleteIds=ids.slice();document.getElementById('settlement-delete-count').textContent=ids.length;deleteDialog.querySelector('[data-settlement-delete-confirm]').disabled=false;deleteDialog.showModal();}
if(deleteDialog){deleteDialog.querySelector('[data-settlement-delete-cancel]').addEventListener('click',function(){deleteDialog.close();});deleteDialog.addEventListener('close',function(){pendingDeleteIds=[];});deleteDialog.querySelector('[data-settlement-delete-confirm]').addEventListener('click',function(){if(!pendingDeleteIds.length)return;this.disabled=true;var container=deleteForm.querySelector('[data-delete-ids]');container.replaceChildren();pendingDeleteIds.forEach(function(id){var input=document.createElement('input');input.type='hidden';input.name='ids[]';input.value=id;container.appendChild(input);});deleteForm.submit();});}
if(deleteForm){document.querySelector('[data-delete-selected-settlements]').addEventListener('click',function(){var selected=Array.from(document.querySelectorAll('[data-export-row]:checked'));if(selected.some(function(input){return input.dataset.locked==='1';})){alert('所选记录包含不可删除的明细或已锁定月份，请取消勾选后重试。');return;}var ids=selected.flatMap(function(input){return input.dataset.paymentIds ? JSON.parse(input.dataset.paymentIds) : [input.dataset.recordId];});if(ids.length>100){alert('每次最多删除100笔付款明细，请减少所选订单。');return;}deleteSettlements(ids);});}
var detailDialog=document.getElementById('settlement-detail');
if(detailDialog){
    var detailLoading=detailDialog.querySelector('[data-detail-loading]');
    var detailError=detailDialog.querySelector('[data-detail-error]');
    var detailRetry=detailDialog.querySelector('[data-detail-retry]');
    var detailContent=detailDialog.querySelector('[data-detail-content]');
    var detailUrl, activeRequest;
    function setDetailState(state){
        detailLoading.hidden=state!=='loading';
        detailError.hidden=state!=='error';
        detailContent.hidden=state!=='content';
    }
    async function loadDetail(){
        activeRequest?.abort();
        var request=new AbortController();
        activeRequest=request;
        setDetailState('loading');
        detailContent.replaceChildren();
        try{
            var response=await fetch(detailUrl,{headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'},signal:request.signal});
            if(!response.ok||response.redirected)throw new Error('Detail request failed');
            var content=await response.text();
            if(request.signal.aborted||!detailDialog.open)return;
            detailContent.innerHTML=content;
            setDetailState('content');
        }catch(error){
            if(request.signal.aborted||!detailDialog.open)return;
            setDetailState('error');
        }
    }
    document.querySelectorAll('[data-view-settlement]').forEach(function(button){
        button.addEventListener('click',function(){
            detailUrl=button.dataset.viewSettlement;
            detailDialog.showModal();
            loadDetail();
        });
    });
    detailRetry.addEventListener('click',loadDetail);
    detailDialog.addEventListener('close',function(){
        if(detailDialog.open)return;
        activeRequest?.abort();
        detailContent.replaceChildren();
    });
}
document.querySelectorAll('[data-settlement-close]').forEach(function(button){
    button.addEventListener('click',function(){button.closest('dialog').close();});
});
if(editor){
    document.addEventListener('click',function(event){
        var button=event.target.closest('[data-edit-settlement]');
        if(!button)return;
        var form=editor.querySelector('form');
        form.reset();
        var row=JSON.parse(button.dataset.row);
        Object.keys(row).forEach(function(key){
            var input=form.elements[key];
            if(!input)return;
            var value=row[key]===null?'':row[key];
            if(input.tagName==='SELECT'&&value!==''&&!Array.from(input.options).some(function(option){return option.value===String(value);}))input.add(new Option(value,value));
            input.value=value;
        });
        document.getElementById('settlement-edit-reference').textContent=row.reference;
        if(detailDialog?.open)detailDialog.close();
        editor.showModal();
    });
    document.querySelector('[data-open-settlement-import]').addEventListener('click',function(){importer.showModal();});
}
var importPlatform=document.getElementById('credit-import-platform');
if(importPlatform){
    var importForm=importPlatform.form, merchant=importForm.elements.account, group=importForm.elements.card_group;
    var upload=importForm.querySelector('input[type=file]'), uploadLabel=importForm.querySelector('[data-upload-label]');
    var template=importer.querySelector('[data-order-template]');
    var addMerchant=document.getElementById('credit-add-merchant'), merchantEmpty=document.getElementById('credit-merchant-empty');
    function updateImportScope(){
        var platform=importPlatform.value, isConflux=platform==='ye';
        if(upload.multiple!==isConflux){
            upload.value='';
            upload.multiple=isConflux;
            upload.name=isConflux?'files[]':'file';
            upload.dispatchEvent(new Event('change'));
        }
        uploadLabel.textContent=isConflux?'拖拽或同时选择订单明细和结算报表':'拖拽文件到这里，或点击选择文件';
        Array.from(merchant.options).forEach(function(option){
            var available=!option.value||((!group.value||option.dataset.group===group.value)&&(!platform||option.dataset.platform===platform));
            option.hidden=option.disabled=!available;
        });
        if(merchant.selectedOptions[0]?.disabled)merchant.value='';
        var merchantOptions=Array.from(merchant.options);
        merchantEmpty.hidden=merchantOptions.some(function(option){return option.value&&!option.disabled;});
        addMerchant.hidden=!platform;
        var accountUrl=new URL(addMerchant.href);
        if(platform)accountUrl.searchParams.set('add_credit_merchant',platform);else accountUrl.searchParams.delete('add_credit_merchant');
        if(group.value)accountUrl.searchParams.set('card_group',group.value);else accountUrl.searchParams.delete('card_group');
        addMerchant.href=accountUrl;
        importForm.elements.timezone.disabled=!platform;
        importForm.elements.timezone.closest('div').hidden=!platform;
        var url=new URL(template.querySelector('a').href);
        if(platform)url.searchParams.set('card_channel',platform);else url.searchParams.delete('card_channel');
        template.querySelector('a').href=url;
    }
    importPlatform.addEventListener('change',function(){merchant.value='';if(importPlatform.value==='ye')importForm.elements.timezone.value='Asia/Shanghai';updateImportScope();});
    group.addEventListener('change',updateImportScope);
    updateImportScope();
}
if(editor&&deleteForm){document.getElementById('settlement-editor-delete').addEventListener('click',function(){var id=editor.querySelector('form').elements['id'].value;if(id)deleteSettlements([id]);});}
document.querySelectorAll('.settlement-dialog input[type=file]').forEach(function(input){input.addEventListener('change',function(){
    var files=Array.from(input.files), invalid=files.some(function(file){return !/\.(xlsx|csv)$/i.test(file.name)||file.size>10*1024*1024;});
    input.setCustomValidity(files.length>5?'一次最多选择 5 个文件。':invalid?'请选择不超过 10 MB 的 XLSX 或 CSV 文件。':'');
    var label=input.closest('label'),status=label.nextElementSibling;
    if(!status||!status.hasAttribute('data-file-status')){status=document.createElement('p');status.setAttribute('data-file-status','');status.setAttribute('role','status');status.style.cssText='padding:0 20px;color:#52748d;overflow-wrap:anywhere';label.after(status);}
    status.textContent=files.length?'已选择：'+files.map(function(file){return file.name;}).join('、'):'';
    if(files.length)input.reportValidity();
});});
@if(old('id'))
if(editor){var failedValues={!! \Illuminate\Support\Js::from(\Illuminate\Support\Arr::only(session()->getOldInput(), ['id', ...array_keys($editorFields)])) !!};Object.keys(failedValues).forEach(function(key){if(editor.querySelector('form').elements[key])editor.querySelector('form').elements[key].value=failedValues[key]??'';});editor.showModal();}
@endif
})();
</script>
@endpush
