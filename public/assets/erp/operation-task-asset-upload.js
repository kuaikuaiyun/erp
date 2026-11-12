(function () {
    'use strict';
    var config = window.OperationTaskAssetUploads || {};
    var labels = config.labels || {};
    var form = document.getElementById('ot-create-test-form');
    var list = document.getElementById('ot-assets');
    if (!form || !list) return;
    var csrf = form.querySelector('input[name="_token"]').value;
    var type = document.getElementById('ot-page-type');
    var submit = form.querySelector('.modal-footer button:not([type="button"])');
    var queue = Promise.resolve();
    var states = [];
    var submitting = false;

    function t(key) { return labels[key] || ''; }
    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function url(template, token, index) {
        return String(template).replace('__TOKEN__', encodeURIComponent(token)).replace('__INDEX__', String(index));
    }
    function notify(message) {
        if (window.ErpAdminNotifier) window.ErpAdminNotifier.notify({ type: 'error', title: t('notice'), message: message });
        else window.alert(message);
    }
    function refresh() {
        if (!submitting) submit.disabled = states.some(function (state) {
            return ['queued', 'uploading', 'checking', 'failed'].indexOf(state.status) !== -1;
        });
    }
    function render(state, status, message) {
        state.status = status;
        state.message.textContent = message;
        state.message.style.color = status === 'failed' ? '#c9302c' : '#28745f';
        state.retry.hidden = status !== 'failed' || !state.file;
        state.clear.hidden = status === 'empty';
        state.retry.style.display = state.retry.hidden ? 'none' : '';
        state.clear.style.display = state.clear.hidden ? 'none' : '';
        refresh();
    }
    function request(method, endpoint, data, progress, state) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            if (state) state.xhr = xhr;
            xhr.open(method, endpoint, true);
            xhr.timeout = 60000;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
            if (progress) xhr.upload.onprogress = function (event) { if (event.lengthComputable) progress(event.loaded / event.total); };
            xhr.onload = function () {
                var payload;
                try { payload = JSON.parse(xhr.responseText); } catch (_) { payload = {}; }
                if (xhr.status >= 200 && xhr.status < 300 && !xhr.responseURL.includes('/auth/login')) resolve(payload);
                else {
                    var errors = payload.errors ? Object.keys(payload.errors).map(function (key) { return payload.errors[key][0]; }) : [];
                    var error = new Error(errors[0] || ([401, 419].indexOf(xhr.status) !== -1 ? t('login_expired') : t('failed')));
                    error.status = xhr.status;
                    reject(error);
                }
            };
            xhr.onerror = xhr.ontimeout = xhr.onabort = function () { reject(new Error(t('network_error'))); };
            if (data && !(data instanceof FormData)) {
                xhr.setRequestHeader('Content-Type', 'application/json');
                data = JSON.stringify(data);
            }
            xhr.send(data || null);
        });
    }
    function schedule(state) {
        var generation = state.generation;
        render(state, 'queued', t('queued'));
        queue = queue.catch(function () {}).then(async function () {
            if (generation !== state.generation) return;
            try {
                render(state, 'uploading', t('uploading').replace(':percent', '0'));
                if (!state.session) {
                    var session = await request('POST', config.beginUrl, { name: state.file.name, size: state.file.size }, null, state);
                    if (generation !== state.generation) return;
                    if (!session.token || !session.chunk_size) throw new Error(t('failed'));
                    state.session = session;
                }
                var chunkSize = state.session.chunk_size;
                var count = Math.ceil(state.file.size / chunkSize);
                for (; state.index < count; state.index++) {
                    if (generation !== state.generation) return;
                    var start = state.index * chunkSize;
                    var end = Math.min(state.file.size, start + chunkSize);
                    var body = new FormData();
                    body.append('file', state.file.slice(start, end), 'part');
                    await request('POST', url(config.partUrl, state.session.token, state.index), body, function (ratio) {
                        if (generation === state.generation) render(state, 'uploading', t('uploading').replace(':percent', String(Math.min(100, Math.floor((start + (end - start) * ratio) / state.file.size * 100)))));
                    }, state);
                    if (generation !== state.generation) return;
                }
                render(state, 'uploading', t('verifying'));
                var completed = await request('POST', url(config.completeUrl, state.session.token), {}, null, state);
                if (generation !== state.generation) return;
                if (completed.status !== 'ready' || completed.token !== state.session.token) throw new Error(t('failed'));
                state.token.value = completed.token;
                render(state, 'ready', t('ready'));
            } catch (error) {
                if (generation !== state.generation) return;
                state.token.value = '';
                if (error.status === 422) { state.session = null; state.index = 0; }
                render(state, 'failed', error.message || t('failed'));
            }
        });
    }
    function reset(state, keepInput) {
        state.generation++;
        if (state.xhr) state.xhr.abort();
        state.session = null;
        state.index = 0;
        state.file = null;
        state.token.value = '';
        if (!keepInput) state.input.value = '';
        state.row.querySelector('[data-file-name]').textContent = t('empty');
        state.row.querySelector('.ot-file-drop').classList.remove('is-filled');
        render(state, 'empty', '');
    }
    function addRow(initial) {
        initial = initial || {};
        var index = states.length;
        var prefix = 'assets[' + index + ']';
        var row = document.createElement('div');
        row.className = 'ot-asset-input';
        row.innerHTML = '<div class="form-group"><label>' + escapeHtml(t('selling_point')) + ' *</label><input class="form-control" name="' + prefix + '[selling_point]" required></div>'
            + '<div class="ot-source-choice"><b>' + escapeHtml(t('source_type')) + ' *</b><label><input type="radio" name="' + prefix + '[source_type]" value="ai_created" required> ' + escapeHtml(t('ai_created')) + '</label><label><input type="radio" name="' + prefix + '[source_type]" value="peer_copied" required> ' + escapeHtml(t('peer_copied')) + '</label></div>'
            + '<div class="ot-grid"><div><label>' + escapeHtml(t('file_label')) + '</label><label class="ot-file-drop"><input type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.webm"><span class="ot-file-drop-icon"><i class="fa fa-cloud-upload"></i></span><span class="ot-file-drop-copy"><strong>' + escapeHtml(t('choose_hint')) + '</strong><small>' + escapeHtml(t('file_hint')) + '</small><em data-file-name>' + escapeHtml(t('empty')) + '</em></span><span class="btn btn-default btn-sm">' + escapeHtml(t('choose')) + '</span></label>'
            + '<input type="hidden" name="' + prefix + '[upload_token]"><div aria-live="polite" data-upload-message></div><button type="button" class="btn btn-default btn-xs" data-upload-retry hidden>' + escapeHtml(t('retry')) + '</button> <button type="button" class="btn btn-default btn-xs" data-upload-clear hidden>' + escapeHtml(t('clear')) + '</button></div>'
            + '<div><label>' + escapeHtml(t('url')) + '</label><input class="form-control" type="url" name="' + prefix + '[url]" placeholder="https://..."></div></div>';
        list.appendChild(row);
        var state = { row: row, input: row.querySelector('input[type="file"]'), token: row.querySelector('input[type="hidden"]'), message: row.querySelector('[data-upload-message]'), retry: row.querySelector('[data-upload-retry]'), clear: row.querySelector('[data-upload-clear]'), generation: 0, status: 'empty', file: null, session: null, index: 0 };
        states.push(state);
        render(state, 'empty', '');
        row.querySelector('input[name$="[selling_point]"]').value = initial.selling_point || '';
        row.querySelector('input[name$="[url]"]').value = initial.url || '';
        if (['ai_created', 'peer_copied'].indexOf(initial.source_type) !== -1) row.querySelector('input[value="' + initial.source_type + '"]').checked = true;
        state.input.addEventListener('change', function () {
            var file = this.files && this.files[0];
            reset(state, true);
            if (!file) return;
            state.file = file;
            row.querySelector('[data-file-name]').textContent = file.name;
            row.querySelector('.ot-file-drop').classList.add('is-filled');
            if (file.size <= 0 || file.size > 50 * 1024 * 1024) { state.file = null; render(state, 'failed', t('too_large')); return; }
            schedule(state);
        });
        state.retry.addEventListener('click', function () { if (state.status === 'failed' && state.file) schedule(state); });
        state.clear.addEventListener('click', function () { reset(state); });
        if (initial.upload_token) {
            var generation = state.generation;
            render(state, 'checking', t('checking'));
            request('GET', url(config.statusUrl, initial.upload_token), null, null, state).then(function (payload) {
                if (generation !== state.generation) return;
                if (payload.status !== 'ready') throw new Error(t('invalid_token'));
                state.token.value = payload.token;
                row.querySelector('[data-file-name]').textContent = payload.name;
                row.querySelector('.ot-file-drop').classList.add('is-filled');
                render(state, 'ready', t('ready'));
            }).catch(function (error) { if (generation === state.generation) render(state, 'failed', error.message); });
        }
        refresh();
    }
    document.getElementById('ot-add-asset').addEventListener('click', function () { addRow(); });
    function hint() { document.getElementById('ot-asset-hint').textContent = t(type.value === 'category' ? 'category_hint' : 'single_hint'); }
    type.addEventListener('change', hint);
    form.addEventListener('submit', function (event) {
        if (submitting) { event.preventDefault(); return; }
        var incomplete = states.some(function (state) { return ['empty', 'ready'].indexOf(state.status) === -1; });
        var invalid = states.some(function (state) { return !state.token.value && !state.row.querySelector('input[name$="[url]"]').value.trim(); });
        if (incomplete || invalid) { event.preventDefault(); notify(t(incomplete ? 'wait' : 'required')); return; }
        submitting = true;
        submit.disabled = true;
    });
    var initial = config.initialAssets || [];
    Object.keys(initial).forEach(function (key) { addRow(initial[key]); });
    while (states.length < 2) addRow();
    hint();
})();
