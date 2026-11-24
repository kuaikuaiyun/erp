(function () {
    'use strict';

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function initialise(select, index) {
        if (select.dataset.filterPickerReady === '1') return;
        select.dataset.filterPickerReady = '1';

        var multiple = select.multiple;
        var field = select.closest('.form-group');
        var fieldLabel = field && field.querySelector('label') ? field.querySelector('label').textContent.trim() : '选项';
        var placeholder = select.dataset.placeholder || (select.options[0] ? select.options[0].text.trim() : '全部');
        var searchPlaceholder = select.dataset.searchPlaceholder || ('搜索' + fieldLabel);
        var noResults = select.dataset.noResults || '没有找到匹配选项';
        var clearText = select.dataset.clearText || '清空选择';
        var selectAllText = select.dataset.selectAllText || '全选';
        var options = Array.prototype.slice.call(select.options);
        var details = element('details', 'flt-picker assistant-filter-multiselect ' + (multiple ? 'is-multiple' : 'is-single'));
        var summary = element('summary', '', placeholder);
        var panel = element('div', 'flt-picker-panel');
        var search = element('input', 'assistant-filter-multiselect-search');
        var list = element('div', 'flt-picker-options');
        var actions = element('div', 'flt-picker-actions');
        var selectAll = multiple ? element('button', 'flt-picker-select-all', selectAllText) : null;
        var clear = element('button', 'flt-picker-clear', clearText);

        summary.id = 'assistant-filter-multiselect-' + index;
        search.type = 'search';
        search.placeholder = searchPlaceholder;
        search.setAttribute('aria-label', searchPlaceholder);
        if (selectAll) {
            selectAll.type = 'button';
            actions.append(selectAll);
        }
        clear.type = 'button';
        actions.append(clear);
        panel.append(search, list, actions);
        details.append(summary, panel);
        select.insertAdjacentElement('afterend', details);
        select.hidden = true;

        function selectedOptions() {
            return options.filter(function (option) { return option.selected; });
        }

        function visibleOptions() {
            var keyword = search.value.trim().toLocaleLowerCase();
            return options.filter(function (option) {
                return !option.disabled && (!keyword || option.text.toLocaleLowerCase().includes(keyword));
            });
        }

        function updateSummary() {
            var selected = selectedOptions();
            if (!selected.length) {
                summary.textContent = placeholder;
                summary.title = placeholder;
                details.classList.remove('has-selection');
                return;
            }
            var labels = selected.map(function (option) { return option.text.trim(); });
            summary.textContent = labels.length === 1 ? labels[0] : '已选择 ' + labels.length + ' 项：' + labels.join('、');
            summary.title = labels.join('、');
            details.classList.toggle('has-selection', multiple || selected.some(function (option) { return option.value !== ''; }));
        }

        function draw() {
            var visible = visibleOptions();
            list.replaceChildren();
            if (!visible.length) {
                list.appendChild(element('p', '', noResults));
                return;
            }
            visible.forEach(function (option) {
                var label = element('label');
                var checkbox = element('input');
                checkbox.type = multiple ? 'checkbox' : 'radio';
                checkbox.name = 'assistant-filter-picker-' + index;
                checkbox.checked = option.selected;
                checkbox.value = option.value;
                checkbox.addEventListener('change', function () {
                    if (multiple) {
                        option.selected = checkbox.checked;
                    } else {
                        options.forEach(function (item) { item.selected = item === option; });
                    }
                    draw();
                    updateSummary();
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    if (!multiple) {
                        search.value = '';
                        details.open = false;
                    }
                });
                label.append(checkbox, element('span', '', option.text.trim()));
                list.appendChild(label);
            });
        }

        search.addEventListener('input', draw);
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                visibleOptions().forEach(function (option) { option.selected = true; });
                draw();
                updateSummary();
                select.dispatchEvent(new Event('change', { bubbles: true }));
                search.focus();
            });
        }
        clear.addEventListener('click', function () {
            if (multiple) {
                options.forEach(function (option) { option.selected = false; });
            } else {
                var fallback = options.find(function (option) { return option.value === ''; }) || options[0];
                options.forEach(function (option) { option.selected = option === fallback; });
            }
            search.value = '';
            draw();
            updateSummary();
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (multiple) search.focus();
            else details.open = false;
        });
        details.addEventListener('toggle', function () {
            if (!details.open) return;
            document.querySelectorAll('.assistant-filter-multiselect[open]').forEach(function (picker) {
                if (picker !== details) picker.open = false;
            });
            draw();
            window.setTimeout(function () { search.focus(); }, 0);
        });
        select.addEventListener('change', function () { draw(); updateSummary(); });
        draw();
        updateSummary();
    }

    function start() {
        document.querySelectorAll('select[data-filter-multiselect], .assistant-review-filter-card select.form-control:not([multiple])').forEach(initialise);
        document.addEventListener('click', function (event) {
            document.querySelectorAll('.assistant-filter-multiselect[open]').forEach(function (picker) {
                if (!picker.contains(event.target)) picker.open = false;
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.assistant-filter-multiselect[open]').forEach(function (picker) { picker.open = false; });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
