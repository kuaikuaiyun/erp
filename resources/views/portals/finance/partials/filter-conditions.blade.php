@php
    $enhancedConditions = $enhancedConditions ?? false;
@endphp
<div @class(['settlement-conditions-section', 'settlement-conditions-enhanced' => $enhancedConditions])>
    @if($enhancedConditions)
        <div class="settlement-conditions-heading">
            <div><strong>筛选条件</strong><span>同时满足以下条件</span></div>
            <button type="button" class="btn btn-default" id="settlement-add-condition"><i class="fa fa-plus" aria-hidden="true"></i> 添加筛选条件</button>
        </div>
        <div data-condition-headings class="settlement-condition-row settlement-condition-labels" hidden>
            <span>筛选字段</span><span>比较方式</span><span>筛选值</span><span></span>
        </div>
    @endif
    <div id="settlement-conditions">
        @foreach($filters['conditions'] ?? [] as $condition)
            @include('portals.finance.partials.filter-condition-row')
        @endforeach
    </div>
    <template id="settlement-condition-template">
        @include('portals.finance.partials.filter-condition-row', ['condition' => []])
    </template>
</div>
@push('styles')
<style>
.settlement-conditions-section{grid-column:1/-1}
#settlement-conditions{display:grid;gap:8px}
.settlement-condition-row{display:flex;gap:8px;flex-wrap:wrap}
.settlement-conditions-section:not(.settlement-conditions-enhanced) .settlement-condition-row [data-condition-field]{width:180px}
.settlement-conditions-section:not(.settlement-conditions-enhanced) .settlement-condition-row [data-condition-value]{width:240px}
.settlement-conditions-enhanced{margin-top:16px;padding:12px 14px;background:#f7f9fc;border:1px solid #dfe8ef;border-radius:6px}
.settlement-conditions-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.settlement-conditions-heading span{margin-left:10px;color:#64788a;font-size:12px}
.settlement-conditions-enhanced .settlement-condition-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(95px,.65fr) minmax(180px,2fr) auto;gap:10px;align-items:center}
.settlement-conditions-enhanced .settlement-condition-row[hidden]{display:none}
.settlement-conditions-enhanced .settlement-condition-row .form-control{width:100%;min-width:0}
.settlement-condition-labels{margin:12px 0 5px;color:#526d83;font-size:12px}
@media(max-width:640px){.settlement-conditions-enhanced .settlement-condition-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.settlement-conditions-enhanced .settlement-condition-labels{display:none}}
</style>
@endpush
@push('scripts')
<script>
(function () {
    var conditions = document.getElementById('settlement-conditions');
    var add = document.getElementById('settlement-add-condition');
    var headings = conditions.parentElement.querySelector('[data-condition-headings]');
    var comparisons = ['gt', 'gte', 'lt', 'lte'];
    var timers = new WeakMap();

    function updateConditions() {
        Array.from(conditions.children).forEach(function (row, index) {
            var field = row.querySelector('[data-condition-field]');
            var op = row.querySelector('[data-condition-operator]');
            var value = row.querySelector('[data-condition-value]');
            var changed = row.dataset.field !== undefined && row.dataset.field !== field.value;
            var numeric = field.selectedOptions[0]?.hasAttribute('data-condition-numeric');
            if (changed) value.value = '';
            row.dataset.field = field.value;
            if (op.options) {
                Array.from(op.options).forEach(function (option) {
                    option.disabled = comparisons.includes(option.value) && !numeric;
                });
                if (!op.value || op.selectedOptions[0].disabled) op.value = 'equals';
            }
            var noValue = ['empty', 'not_empty'].includes(op.value);
            var prefix = 'conditions[' + index + ']';
            field.name = prefix + '[field]';
            op.name = prefix + '[operator]';
            value.name = prefix + '[value]';
            value.disabled = noValue;
            value.required = Boolean(field.value) && !noValue;
            value.inputMode = comparisons.includes(op.value) ? 'decimal' : 'text';
            value.placeholder = noValue ? '此条件无需输入值' : '输入内容或选择建议';
            var list = row.querySelector('datalist');
            if (!list) {
                list = document.createElement('datalist');
                row.appendChild(list);
            }
            if (changed || noValue) list.replaceChildren();
            list.id = 'settlement-options-' + index;
            value.setAttribute('list', list.id);
        });
        if (headings) headings.hidden = !conditions.children.length;
        add.disabled = conditions.children.length >= 10;
    }

    function suggest(event) {
        var value = event.target;
        if (!value.matches('[data-condition-value]') || value.disabled) return;
        var row = value.closest('[data-settlement-condition]');
        var field = row.dataset.field;
        if (!field) return;
        clearTimeout(timers.get(value));
        timers.set(value, setTimeout(async function () {
            var text = value.value;
            var form = conditions.closest('form');
            var url = new URL(form.action);
            url.search = new URLSearchParams(new FormData(form));
            Array.from(url.searchParams.keys()).forEach(function (key) {
                if (key.startsWith('conditions[') || ['download', 'template', 'page'].includes(key)) url.searchParams.delete(key);
            });
            url.searchParams.set('suggestion_field', field);
            url.searchParams.set('suggestion_q', text);
            try {
                var response = await fetch(url, {headers: {Accept: 'application/json'}});
                if (!response.ok) return;
                var items = await response.json();
                if (!row.isConnected || value.disabled || value.value !== text || row.dataset.field !== field) return;
                var list = row.querySelector('datalist');
                list.replaceChildren();
                items.forEach(function (item) {
                    list.append(new Option(item.label, item.value));
                });
            } catch (error) {
                // The value can still be entered directly if suggestions are unavailable.
            }
        }, 250));
    }

    conditions.addEventListener('input', suggest);
    conditions.addEventListener('focusin', suggest);
    conditions.addEventListener('change', updateConditions);
    conditions.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-remove-condition]');
        if (!remove) return;
        remove.closest('[data-settlement-condition]').remove();
        updateConditions();
        add.focus();
    });
    add.addEventListener('click', function () {
        if (conditions.children.length >= 10) return;
        conditions.appendChild(document.getElementById('settlement-condition-template').content.cloneNode(true));
        updateConditions();
        conditions.lastElementChild.querySelector('[data-condition-field]').focus();
    });
    updateConditions();
})();
</script>
@endpush
