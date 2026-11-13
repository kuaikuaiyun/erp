(function () {
    'use strict';
    var config = window.OperationTaskAssetUploads || {};
    window.OperationTaskAssetUploader = function (options) {
        var labels = config.labels || {};
        var form = options.form;
        var list = options.list;
        if (!form || !list) return null;
        var csrf = form.querySelector('input[name="_token"]').value;
        var type = options.type;
        var submit = form.querySelector('.modal-footer button:not([type="button"])');
        var queue = Promise.resolve();
        var states = [];
        var submitting = false;

        function t(key, values) {
            var value = labels[key] || '';
            Object.keys(values || {}).forEach(function (name) { value = value.split(':' + name).join(String(values[name])); });
            return value;
        }
        function validationText(key, values) {
            var text = (config.validation || {})[key] || '';
            Object.keys(values || {}).forEach(function (name) { text = text.split(':' + name).join(String(values[name])); });
            return text;
        }
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
                return ['queued', 'uploading', 'verifying', 'checking', 'failed'].indexOf(state.status) !== -1;
            });
        }
        function render(state, status, message) {
            state.status = status;
            // The existing form validator also recognizes ready replacement uploads.
            state.row.setAttribute('data-has-file', state.retained || status === 'ready' ? '1' : '0');
            state.message.textContent = message;
            state.box.dataset.uploadStatus = status;
            state.percentage.hidden = status === 'empty';
            state.box.setAttribute('aria-busy', ['uploading', 'verifying', 'checking'].indexOf(status) !== -1 ? 'true' : 'false');
            var cancellable = ['queued', 'uploading', 'verifying', 'checking'].indexOf(status) !== -1;
            state.clear.textContent = t(cancellable ? 'cancel' : 'clear');
            state.retry.hidden = status !== 'failed' || !state.file;
            state.clear.hidden = status === 'empty';
            state.retry.style.display = state.retry.hidden ? 'none' : '';
            state.clear.style.display = state.clear.hidden ? 'none' : '';
            refresh();
        }
        function setProgress(state, percent) {
            state.percent = Math.max(0, Math.min(100, Math.floor(percent)));
            state.progress.style.width = state.percent + '%';
            state.progress.setAttribute('aria-valuenow', String(state.percent));
            state.percentage.textContent = state.percent + '%';
            state.percentage.hidden = state.status === 'empty';
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
                    render(state, 'uploading', t('uploading'));
                    setProgress(state, state.session ? state.index * state.session.chunk_size / state.file.size * 100 : 0);
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
                            if (generation === state.generation) setProgress(state, (start + (end - start) * ratio) / state.file.size * 100);
                        }, state);
                        if (generation !== state.generation) return;
                        setProgress(state, end / state.file.size * 100);
                    }
                    render(state, 'verifying', t('verifying'));
                    setProgress(state, 100);
                    var completed = await request('POST', url(config.completeUrl, state.session.token), {}, null, state);
                    if (generation !== state.generation) return;
                    if (completed.status !== 'ready' || completed.token !== state.session.token) throw new Error(t('failed'));
                    state.token.value = completed.token;
                    render(state, 'ready', t('ready'));
                    setProgress(state, 100);
                } catch (error) {
                    if (generation !== state.generation) return;
                    state.token.value = '';
                    if (error.status === 422) { state.session = null; state.index = 0; }
                    render(state, 'failed', error.message || t('failed'));
                }
            });
        }
        function reset(state, keepInput, message) {
            state.generation++;
            if (state.xhr) state.xhr.abort();
            state.session = null;
            state.index = 0;
            state.file = null;
            state.token.value = '';
            if (!keepInput) state.input.value = '';
            if (window.OperationTaskForms) window.OperationTaskForms.clear(state.input);
            state.row.querySelector('[data-file-name]').textContent = t('empty');
            state.row.querySelector('.ot-file-drop').classList.remove('is-filled');
            render(state, 'empty', message || '');
            setProgress(state, 0);
        }
        function addRow(initial) {
            initial = initial || {};
            var index = states.length;
            var prefix = 'assets[' + index + ']';
            var row = document.createElement('div');
            row.className = options.edit ? 'ot-edit-asset-row' : 'ot-asset-input';
            var fileId = form.id + '-upload-file-' + index;
            row.innerHTML = (options.renderHeader ? options.renderHeader(initial, index) : '')
                + (initial.id ? '<input type="hidden" name="' + prefix + '[id]" value="' + Number(initial.id) + '">' : '')
                + '<div class="form-group"><label>' + escapeHtml(t('selling_point')) + ' *</label><input class="form-control" name="' + prefix + '[selling_point]" required></div>'
                + '<div class="ot-source-choice"><b>' + escapeHtml(t('source_type')) + ' *</b><label><input type="radio" name="' + prefix + '[source_type]" value="ai_created" required> ' + escapeHtml(t('ai_created')) + '</label><label><input type="radio" name="' + prefix + '[source_type]" value="peer_copied" required> ' + escapeHtml(t('peer_copied')) + '</label></div>'
                + '<div class="ot-grid"><div><label>' + escapeHtml(t(initial.has_file ? 'replace_file_label' : 'file_label')) + '</label><div class="ot-file-drop ot-upload-box">'
                + '<div class="ot-upload-progress" data-upload-progress role="progressbar" aria-label="' + escapeHtml(t('progress')) + '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>'
                + '<input id="' + fileId + '" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.webm">'
                + '<div class="ot-upload-content"><span class="ot-file-drop-icon"><i class="fa fa-cloud-upload"></i></span><div class="ot-upload-copy"><label for="' + fileId + '" class="ot-file-drop-copy"><strong>' + escapeHtml(t('choose_hint')) + '</strong><small>' + escapeHtml(t('file_hint')) + '</small><em data-file-name>' + escapeHtml(t('empty')) + '</em></label><span class="ot-upload-message" aria-live="polite" data-upload-message></span></div>'
                + '<div class="ot-upload-actions"><strong class="ot-upload-percentage" data-upload-percentage hidden></strong><button type="button" class="btn btn-default btn-sm ot-upload-choose" data-upload-choose>' + escapeHtml(t('choose')) + '</button><button type="button" class="btn btn-default btn-xs" data-upload-retry hidden>' + escapeHtml(t('retry')) + '</button><button type="button" class="btn btn-default btn-xs" data-upload-clear hidden>' + escapeHtml(t('clear')) + '</button></div></div></div>'
                + '<input type="hidden" name="' + prefix + '[upload_token]">'
                + (initial.has_file ? '<div class="ot-edit-retained-file"><i class="fa fa-check-circle"></i><span>' + escapeHtml(t('retained_file', {name:initial.original_name || t('uploaded_file')})) + '</span><small>' + escapeHtml(t('retained_hint')) + '</small></div>' : '') + '</div>'
                + '<div><label>' + escapeHtml(t('url')) + '</label><input class="form-control" type="url" name="' + prefix + '[url]" placeholder="https://..."></div></div>';
            list.appendChild(row);
            var state = { row: row, box: row.querySelector('.ot-upload-box'), progress: row.querySelector('[data-upload-progress]'), percentage: row.querySelector('[data-upload-percentage]'), input: row.querySelector('input[type="file"]'), token: row.querySelector('input[name$="[upload_token]"]'), message: row.querySelector('[data-upload-message]'), retry: row.querySelector('[data-upload-retry]'), clear: row.querySelector('[data-upload-clear]'), retained: !!initial.has_file, generation: 0, status: 'empty', file: null, session: null, index: 0, percent: 0 };
            states.push(state);
            render(state, 'empty', '');
            setProgress(state, 0);
            row.querySelector('input[name$="[selling_point]"]').value = initial.selling_point || '';
            row.querySelector('input[name$="[url]"]').value = initial.url || initial.external_url || '';
            if (['ai_created', 'peer_copied'].indexOf(initial.source_type) !== -1) row.querySelector('input[value="' + initial.source_type + '"]').checked = true;
            state.input.addEventListener('change', function (event) {
                var file = this.files && this.files[0];
                // Files travel through the chunk API, not the edit form's legacy 50MB validator.
                if (options.edit) { this.value = ''; event.stopPropagation(); }
                reset(state, true);
                if (!file) return;
                state.file = file;
                row.querySelector('[data-file-name]').textContent = file.name;
                row.querySelector('.ot-file-drop').classList.add('is-filled');
                if (file.size <= 0 || file.size > 100 * 1024 * 1024) { state.file = null; render(state, 'failed', t('too_large')); setProgress(state, 0); return; }
                if (!/\.(jpe?g|png|webp|gif|mp4|mov|webm)$/i.test(file.name)) { state.file = null; render(state, 'failed', t('invalid_file')); setProgress(state, 0); return; }
                schedule(state);
            });
            state.retry.addEventListener('click', function () { if (state.status === 'failed' && state.file) schedule(state); });
            row.querySelector('[data-upload-choose]').addEventListener('click', function () { state.input.click(); });
            state.clear.addEventListener('click', function () {
                var cancelling = ['queued', 'uploading', 'verifying', 'checking'].indexOf(state.status) !== -1;
                reset(state, false, cancelling ? t(state.retained ? 'cancelled_retained' : 'cancelled') : '');
            });
            if (initial.upload_token) {
                var generation = state.generation;
                render(state, 'checking', t('checking'));
                setProgress(state, 0);
                request('GET', url(config.statusUrl, initial.upload_token), null, null, state).then(function (payload) {
                    if (generation !== state.generation) return;
                    if (payload.status !== 'ready') throw new Error(t('invalid_token'));
                    state.token.value = payload.token;
                    row.querySelector('[data-file-name]').textContent = payload.name;
                    row.querySelector('.ot-file-drop').classList.add('is-filled');
                    render(state, 'ready', t('ready'));
                    setProgress(state, 100);
                }).catch(function (error) { if (generation === state.generation) render(state, 'failed', error.message); });
            }
            refresh();
            hint();
        }
        if (options.addButton) options.addButton.addEventListener('click', function () { addRow(); });
        function hint() {
            var minimum = type.value === 'category' ? 3 : 2;
            options.hint.textContent = states.length < minimum
                ? validationText('asset_count', { type: validationText(type.value === 'category' ? 'category' : 'single'), min: minimum, count: states.length })
                : t(options.edit ? (type.value === 'category' ? 'edit_category_hint' : 'edit_single_hint') : (type.value === 'category' ? 'category_hint' : 'single_hint'));
        }
        type.addEventListener('change', hint);
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            if (submitting) { event.preventDefault(); return; }
            var minimum = type.value === 'category' ? 3 : 2;
            if (states.length < minimum) {
                event.preventDefault();
                var message = validationText('asset_count', { type: validationText(type.value === 'category' ? 'category' : 'single'), min: minimum, count: states.length });
                if (window.OperationTaskForms) window.OperationTaskForms.fail(form, list, message); else notify(message);
                return;
            }
            var incomplete = states.some(function (state) { return ['empty', 'ready'].indexOf(state.status) === -1; });
            var invalid = states.some(function (state) { return !state.retained && !state.token.value && !state.row.querySelector('input[name$="[url]"]').value.trim(); });
            if (incomplete || invalid) { event.preventDefault(); notify(t(incomplete ? 'wait' : 'required')); return; }
            submitting = true;
            submit.disabled = true;
        });
        return {
            addRow: addRow,
            reset: function () {
                states.forEach(function (state) { reset(state, false); });
                states = [];
                list.innerHTML = '';
                queue = Promise.resolve();
                submitting = false;
                refresh();
                hint();
            },
            pause: function () {
                states.forEach(function (state) {
                    if (['queued', 'uploading', 'verifying', 'checking'].indexOf(state.status) !== -1) {
                        reset(state, false, t(state.retained ? 'cancelled_retained' : 'cancelled'));
                    }
                });
            }
        };
    };
    var form = document.getElementById('ot-create-test-form');
    var list = document.getElementById('ot-assets');
    if (form && list) {
        var create = window.OperationTaskAssetUploader({form:form, list:list, type:document.getElementById('ot-page-type'), hint:document.getElementById('ot-asset-hint'), addButton:document.getElementById('ot-add-asset')});
        var initial = config.initialAssets || [];
        Object.keys(initial).forEach(function (key) { create.addRow(initial[key]); });
        for (var count = Object.keys(initial).length; count < 2; count++) create.addRow();
    }
})();
