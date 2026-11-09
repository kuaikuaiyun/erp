@php($columnExceptionScope = ! empty($filters['card_exceptions']) && ($columnCardChannel ?? null) === 'ye' ? ['card_exceptions' => 1] : [])
<button type="button" class="btn btn-default" data-open-column-settings>
    <i class="fa fa-columns" aria-hidden="true"></i> 列与导出设置
</button>
<dialog id="settlement-column-settings" class="settlement-dialog" aria-labelledby="settlement-column-title"
    data-preference-key="finance-settlement-columns:{{ $report }}:{{ $user->getAuthIdentifier() }}{{ isset($columnCardChannel) ? ':'.$columnCardChannel : '' }}{{ $columnExceptionScope ? ':exceptions' : '' }}"
    @if($orderView) data-export-visible-columns @endif
    @if($report === 'paypal-settlement')
    data-new-display-fields="{{ implode(' ', array_keys(\App\Portals\Finance\FinancePayPalSettlementOrders::REFUND_SPLIT_COLUMNS)) }}"
    @endif
    @if($report === 'paypal-orders')
    data-filter-form="finance-filters"
    data-export-column-param="report_columns[]"
    data-template-format-param="export_format"
    data-template-url="{{ route('finance.records.export', ['dataset' => $report, 'export_layout' => 'page', 'export_scope' => 'filtered']) }}"
    data-legacy-preference-key="finance:paypal-orders:page-export-columns:v2"
    @endif
    @isset($columnCardChannel)
    data-template-url="{{ route('finance.report', [$report, 'download' => 1, 'card_channel' => $columnCardChannel, ...$columnExceptionScope]) }}"
    @endisset
    data-template-preview-url="{{ route($report === 'paypal-orders' ? 'finance.records.export-template.preview' : 'finance.settlement.export-template.preview', isset($columnCardChannel) ? [$report, 'card_channel' => $columnCardChannel, ...$columnExceptionScope] : $report) }}"
    data-csrf-token="{{ csrf_token() }}">
    <header>
        <h3 id="settlement-column-title">列与导出设置</h3>
        <button type="button" data-close-column-settings aria-label="关闭">×</button>
    </header>
    <div class="settlement-column-body">
        <div class="settlement-column-tabs" role="group" aria-label="设置分类">
            <button type="button" class="btn btn-default" data-column-tab="export" aria-pressed="true">导出模板</button>
            <button type="button" class="btn btn-default" data-column-tab="display" aria-pressed="false">列表显示</button>
        </div>
        <section data-column-panel="display" hidden>
            <p>{{ $orderView ? '默认按列表可见列和顺序导出。' : '选择要在列表显示的列。' }}</p>
            <button type="button" class="btn btn-default btn-xs" data-display-all>显示全部</button>
            <div class="settlement-display-fields">
                @foreach($columns as $field => $label)
                    <label><input type="checkbox" data-display-field value="{{ $field }}" checked> {{ $label }}</label>
                @endforeach
            </div>
        </section>
        <section data-column-panel="export">
            <div class="settlement-template-import">
                <strong><i class="fa fa-upload" aria-hidden="true"></i> 从表格导入模板</strong>
                <p>仅读取首个工作表指定行的表头和顺序。支持 XLSX、XLS、CSV{{ $report === 'paypal-orders' ? '、TSV' : '' }}。</p>
                <div class="settlement-template-file">
                    <input type="file" data-import-template-file accept=".xlsx,.xls,.csv{{ $report === 'paypal-orders' ? ',.tsv' : '' }}" aria-label="导出模板文件">
                    <label>表头行 <input class="form-control" type="number" data-import-template-row min="1" max="1000" step="1" value="1"></label>
                    <button type="button" class="btn btn-default" data-import-export-template>读取表头</button>
                </div>
                <p data-template-import-status class="settlement-column-message" role="status"></p>
            </div>
            <div class="settlement-template-tools">
                <select class="form-control" data-export-template aria-label="已保存的导出模板"></select>
                <button type="button" class="btn btn-default" data-load-export-template>载入编辑</button>
                <button type="button" class="btn btn-default" data-delete-export-template>删除</button>
                <input type="text" class="form-control" data-template-name maxlength="40" placeholder="输入模板名称" aria-label="模板名称">
                <button type="button" class="btn btn-primary" data-save-export-template>保存为模板</button>
            </div>
            <div class="settlement-export-grid">
                <div>
                    <strong>可选字段</strong>
                    <input type="search" class="form-control" data-field-search placeholder="搜索字段" aria-label="搜索导出字段">
                    <div class="settlement-export-tools">
                        <button type="button" class="btn btn-default btn-xs" data-export-default>恢复默认顺序</button>
                        <button type="button" class="btn btn-default btn-xs" data-export-clear>清空选择</button>
                    </div>
                    <div class="settlement-export-fields">
                        @foreach($exportColumns as $field => $label)
                            <label><input type="checkbox" data-export-field value="{{ $field }}" data-label="{{ $label }}" checked>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <strong>已选字段与导出顺序 <span data-export-field-count></span></strong>
                    <p class="settlement-column-hint">拖动字段或使用上下按钮调整顺序。</p>
                    <ol data-export-field-order class="settlement-export-order"></ol>
                </div>
            </div>
            <button type="button" class="btn btn-default btn-sm" data-download-export-template>下载空白模板</button>
        </section>
        <p data-column-message class="settlement-column-message" role="status"></p>
    </div>
    <footer>
        <button type="button" class="btn btn-default" data-close-column-settings>取消</button>
        <button type="button" class="btn btn-primary" data-save-column-settings>保存设置</button>
    </footer>
</dialog>
@push('styles')
<style>
.settlement-column-settings-trigger{margin-left:auto}
#settlement-column-settings{width:900px;max-width:calc(100vw - 32px);max-height:85vh;margin:auto;position:fixed;inset:0;border:0;border-radius:10px;padding:0;color:#3b5870}
#settlement-column-settings::backdrop{background:#102c4866}
#settlement-column-settings[open]{display:flex;flex-direction:column}
#settlement-column-settings>header,#settlement-column-settings>footer{flex-shrink:0;display:flex;align-items:center;padding:16px 20px}
#settlement-column-settings>header{justify-content:space-between;border-bottom:1px solid #e2eaf2}
#settlement-column-settings>header h3{font-size:17px;margin:0}
#settlement-column-settings>header button{border:0;background:none;font-size:24px}
#settlement-column-settings>footer{justify-content:flex-end;gap:8px;border-top:1px solid #e2eaf2}
.settlement-column-body{padding:16px 20px;overflow:auto}
.settlement-column-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid #dfe8f0}
.settlement-column-tabs .btn{border:0;border-radius:0;background:transparent;padding:9px 16px}
.settlement-column-tabs [aria-pressed=true]{color:#23618d;border-bottom:2px solid #3484b5;font-weight:600}
.settlement-template-import{padding:12px 14px;margin-bottom:14px;border:1px solid #d6e6f1;border-radius:6px;background:#f4f9fc}
.settlement-template-import>p{color:#58738a;margin:5px 0 9px}
.settlement-template-file{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.settlement-template-file input[type=file]{max-width:100%}
.settlement-template-file label{align-items:center}
.settlement-template-file .form-control{width:80px}
.settlement-template-toolbar{display:inline-flex;align-items:center;gap:7px;margin:0;font-weight:400}
.settlement-template-toolbar .form-control{width:auto;min-width:140px;max-width:220px}
.settlement-display-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;margin-top:12px;max-height:45vh;overflow:auto}
.settlement-column-body label{font-weight:400;margin:0;display:flex;gap:7px;align-items:baseline}
.settlement-template-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:15px;padding-bottom:12px;border-bottom:1px solid #e2eaf2}
.settlement-template-tools .form-control{width:auto;min-width:140px;max-width:100%;flex:1}
.settlement-export-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;margin:0 0 12px}
.settlement-export-grid>div{min-width:0;padding:12px;border:1px solid #e2eaf2;border-radius:6px}
.settlement-export-grid input[type=search]{margin:8px 0}
.settlement-export-tools{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:8px}
.settlement-export-fields,.settlement-export-order{height:clamp(135px,30vh,310px);overflow:auto;overscroll-behavior:contain}
.settlement-export-fields label,.settlement-export-order li{padding:6px 8px}
.settlement-export-fields label[hidden]{display:none}
.settlement-export-order{margin:0;padding:0;list-style:none}
.settlement-export-order li{display:flex;align-items:center;gap:6px;border-bottom:1px solid #edf2f6;cursor:grab}
.settlement-export-order li span{flex:1;min-width:0;overflow-wrap:anywhere}
.settlement-export-order li[data-drag-over]{background:#eaf4fb}
.settlement-column-hint,.settlement-column-message{margin:8px 0;color:#597187;font-size:12px}
.settlement-column-message:empty{display:none}
@media(max-width:620px){.settlement-export-grid{grid-template-columns:1fr}.settlement-export-fields,.settlement-export-order{height:140px}}
</style>
@endpush
@push('scripts')
<script src="{{ asset('assets/erp/finance-settlement-columns.js') }}?v=7"></script>
@endpush
