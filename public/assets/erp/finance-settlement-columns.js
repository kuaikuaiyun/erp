(function () {
    'use strict';
    const dialog = document.getElementById('settlement-column-settings');
    const form = document.getElementById('settlement-filter');
    if (!dialog || !form) return;

    const displayInputs = Array.from(dialog.querySelectorAll('[data-display-field]'));
    const exportInputs = Array.from(dialog.querySelectorAll('[data-export-field]'));
    const displayKeys = displayInputs.map(input => input.value);
    const exportKeys = exportInputs.map(input => input.value);
    const exportVisible = dialog.hasAttribute('data-export-visible-columns');
    const labels = new Map(exportInputs.map(input => [input.value, input.dataset.label]));
    const orderList = dialog.querySelector('[data-export-field-order]');
    const templateSelect = dialog.querySelector('[data-export-template]');
    const activeSelect = form.querySelector('[data-active-export-template]');
    const templateName = dialog.querySelector('[data-template-name]');
    const message = dialog.querySelector('[data-column-message]');
    const key = dialog.dataset.preferenceKey;
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(key)) || {}; } catch (error) {}

    function validKeys(value, available) {
        return Array.isArray(value) ? [...new Set(value.filter(field => available.includes(field)))] : [];
    }
    function withDefault(fields, defaults) {
        return fields.length ? fields : defaults.slice();
    }
    let display = withDefault(validKeys(saved.display, displayKeys), displayKeys);
    let fields = withDefault(validKeys(saved.fields, exportKeys), exportKeys);
    let templates = (Array.isArray(saved.templates) ? saved.templates : [])
        .filter(template => template && typeof template.name === 'string')
        .map(template => ({name: template.name, fields: validKeys(template.fields, exportKeys)}))
        .filter(template => template.fields.length).slice(0, 20);
    let activeTemplate = !exportVisible && templates.some(template => template.name === saved.activeTemplate) ? saved.activeTemplate : '';
    let draft = fields.slice();
    let initialDraft = draft.slice();
    let dragging = null;
    let importRequest = null;

    function persist(nextDisplay, nextFields, nextTemplates, nextActive = activeTemplate) {
        try {
            localStorage.setItem(key, JSON.stringify({
                display: nextDisplay, fields: nextFields, templates: nextTemplates, activeTemplate: nextActive,
            }));
            return true;
        } catch (error) {
            message.textContent = '浏览器无法保存设置。请检查站点存储权限后重试。';
            return false;
        }
    }

    function applyDisplay() {
        document.querySelectorAll('[data-settlement-column]').forEach(cell => {
            cell.hidden = !display.includes(cell.dataset.settlementColumn);
        });
        document.querySelectorAll('[data-settlement-colspan]').forEach(cell => {
            cell.colSpan = Number(cell.dataset.settlementColspan) + display.length;
        });
    }

    function renderFields() {
        const scroll = orderList.scrollTop;
        orderList.replaceChildren();
        exportInputs.forEach(input => {
            input.checked = draft.includes(input.value);
        });
        draft.forEach((field, index) => {
            const row = document.createElement('li');
            row.dataset.field = field;
            row.draggable = true;
            const label = document.createElement('span');
            label.textContent = (index + 1) + '. ' + labels.get(field);
            row.append(label);
            for (const [offset, text] of [[-1, '上移'], [1, '下移']]) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-default btn-xs';
                button.dataset.offset = offset;
                button.textContent = text;
                button.setAttribute('aria-label', text + labels.get(field));
                button.disabled = index + offset < 0 || index + offset >= draft.length;
                row.append(button);
            }
            orderList.append(row);
        });
        orderList.scrollTop = scroll;
        dialog.querySelector('[data-export-field-count]').textContent = '（' + draft.length + '）';
        const search = dialog.querySelector('[data-field-search]').value.trim().toLowerCase();
        exportInputs.forEach(input => {
            input.closest('label').hidden = !labels.get(input.value).toLowerCase().includes(search);
        });
    }

    function renderTemplates(name = '') {
        templateSelect.replaceChildren(new Option(templates.length ? '选择导出模板' : '暂无已保存模板', ''));
        templates.forEach(template => templateSelect.add(new Option(template.name, template.name)));
        templateSelect.value = name;
        const unavailable = !templateSelect.value;
        dialog.querySelector('[data-load-export-template]').disabled = unavailable;
        dialog.querySelector('[data-delete-export-template]').disabled = unavailable;
    }

    function renderActiveTemplate() {
        activeSelect.replaceChildren(new Option(exportVisible ? '按当前列表导出' : '默认字段设置', ''));
        templates.forEach(template => activeSelect.add(new Option(template.name, template.name)));
        activeSelect.value = activeTemplate;
    }

    function currentExportFields() {
        return templates.find(template => template.name === activeTemplate)?.fields
            ?? (exportVisible ? displayKeys.filter(field => display.includes(field)) : fields);
    }

    function selectTab(tab) {
        dialog.querySelectorAll('[data-column-tab]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.columnTab === tab));
        });
        dialog.querySelectorAll('[data-column-panel]').forEach(panel => {
            panel.hidden = panel.dataset.columnPanel !== tab;
        });
    }

    document.querySelector('[data-open-column-settings]').addEventListener('click', () => {
        draft = currentExportFields().slice();
        initialDraft = draft.slice();
        displayInputs.forEach(input => { input.checked = display.includes(input.value); });
        templateName.value = activeTemplate;
        message.textContent = '';
        importStatus.textContent = '';
        renderTemplates(activeTemplate);
        renderFields();
        dialog.showModal();
    });
    dialog.querySelectorAll('[data-close-column-settings]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => importRequest?.abort());
    dialog.querySelectorAll('[data-column-tab]').forEach(button => {
        button.addEventListener('click', () => selectTab(button.dataset.columnTab));
    });
    dialog.querySelector('[data-display-all]').addEventListener('click', () => {
        displayInputs.forEach(input => { input.checked = true; });
    });
    dialog.querySelector('[data-export-default]').addEventListener('click', () => {
        draft = exportVisible ? displayInputs.filter(input => input.checked).map(input => input.value) : exportKeys.slice();
        renderFields();
    });
    dialog.querySelector('[data-export-clear]').addEventListener('click', () => {
        draft = [];
        renderFields();
    });
    dialog.querySelector('[data-field-search]').addEventListener('input', renderFields);
    exportInputs.forEach(input => input.addEventListener('change', () => {
        draft = input.checked ? [...draft, input.value] : draft.filter(field => field !== input.value);
        renderFields();
    }));
    orderList.addEventListener('click', event => {
        const button = event.target.closest('[data-offset]');
        if (!button) return;
        const field = button.closest('li').dataset.field;
        const from = draft.indexOf(field);
        const to = from + Number(button.dataset.offset);
        if (to < 0 || to >= draft.length) return;
        draft.splice(from, 1);
        draft.splice(to, 0, field);
        renderFields();
        const moved = orderList.children[to];
        const next = moved.querySelector('[data-offset="' + button.dataset.offset + '"]:not(:disabled)')
            || moved.querySelector('button:not(:disabled)');
        next?.focus();
    });
    orderList.addEventListener('dragstart', event => {
        const row = event.target.closest('li');
        if (!row) return;
        dragging = row.dataset.field;
        event.dataTransfer.setData('text/plain', dragging);
        event.dataTransfer.effectAllowed = 'move';
    });
    orderList.addEventListener('dragover', event => {
        const row = event.target.closest('li');
        if (!dragging || !row) return;
        event.preventDefault();
        orderList.querySelector('[data-drag-over]')?.removeAttribute('data-drag-over');
        row.setAttribute('data-drag-over', '');
    });
    orderList.addEventListener('drop', event => {
        const row = event.target.closest('li');
        if (!dragging || !row) return;
        event.preventDefault();
        const to = draft.indexOf(row.dataset.field);
        draft.splice(draft.indexOf(dragging), 1);
        draft.splice(to, 0, dragging);
        dragging = null;
        renderFields();
    });
    orderList.addEventListener('dragend', () => {
        dragging = null;
        orderList.querySelector('[data-drag-over]')?.removeAttribute('data-drag-over');
    });

    function requireExportFields() {
        if (draft.length) return true;
        selectTab('export');
        message.textContent = '请至少选择一个导出字段。';
        return false;
    }

    templateSelect.addEventListener('change', () => renderTemplates(templateSelect.value));
    activeSelect.addEventListener('change', () => {
        activeTemplate = activeSelect.value;
        try {
            localStorage.setItem(key, JSON.stringify({display, fields, templates, activeTemplate}));
        } catch (error) {
            // The selected template can still be used for this export.
        }
    });

    const importFile = dialog.querySelector('[data-import-template-file]');
    const importRow = dialog.querySelector('[data-import-template-row]');
    const importStatus = dialog.querySelector('[data-template-import-status]');
    const importButton = dialog.querySelector('[data-import-export-template]');
    importButton.addEventListener('click', async () => {
        const file = importFile.files[0];
        if (!file) {
            importStatus.textContent = '请先选择要导入的表格。';
            importFile.focus();
            return;
        }
        if (!importRow.value || !importRow.reportValidity()) return;
        const body = new FormData();
        body.append('file', file);
        body.append('header_row', importRow.value);
        importRequest = new AbortController();
        importButton.disabled = true;
        importStatus.textContent = '正在读取表头…';
        try {
            const response = await fetch(dialog.dataset.templatePreviewUrl, {
                method: 'POST', body, signal: importRequest.signal,
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': dialog.dataset.csrfToken},
            });
            const result = await response.json();
            if (!response.ok) {
                importStatus.textContent = result.errors?.file?.[0] || result.errors?.header_row?.[0] || '读取失败，请检查文件后重试。';
                return;
            }
            const matched = validKeys(result.fields, exportKeys);
            const notes = [
                result.unmatched?.length ? '未匹配：' + result.unmatched.join('、') : '',
                result.duplicates?.length ? '重复列：' + result.duplicates.join('、') : '',
            ].filter(Boolean);
            if (!matched.length) {
                importStatus.textContent = ['这一行没有匹配的字段，请检查表头行号或列名。', ...notes].join(' ');
                return;
            }
            draft = matched;
            renderFields();
            templateName.value = file.name.replace(/\.[^.]+$/, '').slice(0, 40);
            importStatus.textContent = ['已按原顺序匹配 ' + matched.length + ' 个字段，核对后点击「保存为模板」。', ...notes].join(' ');
        } catch (error) {
            if (error.name !== 'AbortError') importStatus.textContent = '读取失败，请重试。';
        } finally {
            importRequest = null;
            importButton.disabled = false;
        }
    });

    dialog.querySelector('[data-load-export-template]').addEventListener('click', () => {
        const template = templates.find(item => item.name === templateSelect.value);
        if (!template) return;
        draft = template.fields.slice();
        templateName.value = template.name;
        renderFields();
        message.textContent = '已载入字段。修改后可保存为模板，或在导出按钮旁直接选用。';
    });
    dialog.querySelector('[data-save-export-template]').addEventListener('click', () => {
        if (!requireExportFields()) return;
        const name = templateName.value.trim();
        if (!name) {
            message.textContent = '请填写模板名称。';
            templateName.focus();
            return;
        }
        const existing = templates.findIndex(template => template.name === name);
        if (existing !== -1 && !window.confirm('覆盖同名导出模板？')) return;
        const next = templates.slice();
        if (existing !== -1) next[existing] = {name, fields: draft.slice()};
        else if (next.length < 20) next.push({name, fields: draft.slice()});
        else {
            message.textContent = '最多保存 20 个模板。请删除一个模板后再试。';
            return;
        }
        if (!persist(display, fields, next, name)) return;
        templates = next;
        activeTemplate = name;
        initialDraft = draft.slice();
        renderActiveTemplate();
        renderTemplates(name);
        message.textContent = '模板已保存并选为当前导出模板。';
    });
    dialog.querySelector('[data-delete-export-template]').addEventListener('click', () => {
        if (!templateSelect.value || !window.confirm('删除所选导出模板？')) return;
        const next = templates.filter(template => template.name !== templateSelect.value);
        const nextActive = activeTemplate === templateSelect.value ? '' : activeTemplate;
        if (!persist(display, fields, next, nextActive)) return;
        templates = next;
        activeTemplate = nextActive;
        renderActiveTemplate();
        renderTemplates();
        templateName.value = '';
        message.textContent = '模板已删除。';
    });
    dialog.querySelector('[data-save-column-settings]').addEventListener('click', () => {
        const nextDisplay = displayInputs.filter(input => input.checked).map(input => input.value);
        if (!nextDisplay.length) {
            selectTab('display');
            message.textContent = '请至少保留一个列表显示字段。';
            return;
        }
        const changed = draft.length !== initialDraft.length || draft.some((field, index) => field !== initialDraft[index]);
        if (exportVisible && changed) {
            selectTab('export');
            message.textContent = '请将调整后的导出字段保存为模板。';
            return;
        }
        const nextFields = changed ? draft : fields;
        const nextActive = changed ? '' : activeTemplate;
        if (!requireExportFields() || !persist(nextDisplay, nextFields, templates, nextActive)) return;
        display = nextDisplay;
        fields = nextFields.slice();
        activeTemplate = nextActive;
        renderActiveTemplate();
        applyDisplay();
        dialog.close();
    });
    dialog.querySelector('[data-download-export-template]').addEventListener('click', () => {
        if (!requireExportFields()) return;
        const url = new URL(form.action, window.location.href);
        url.search = '';
        url.searchParams.set('download', '1');
        url.searchParams.set('export_template', '1');
        url.searchParams.set('format', form.elements.format.value);
        draft.forEach(field => url.searchParams.append('export_columns[]', field));
        window.location.assign(url.toString());
    });
    form.addEventListener('finance:prepare-export', event => {
        const selected = currentExportFields();
        const defaults = exportVisible ? displayKeys : exportKeys;
        if (selected.length === defaults.length && selected.every((field, index) => field === defaults[index])) return;
        selected.forEach(field => event.detail.append('export_columns[]', field));
    });
    renderActiveTemplate();
    applyDisplay();
})();
