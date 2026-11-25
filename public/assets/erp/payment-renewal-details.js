(function () {
    'use strict';
    var page = document.querySelector('.payment-renewal-page');
    var pageFeedback = page ? page.querySelector('[data-renewal-export-feedback]') : null;
    var pageMessages = page ? JSON.parse(page.dataset.renewalExportMessages || '{}') : {};
    var pageExporting = false;
    var savedButtons = new WeakMap();

    function pageButtons() {
        return Array.from(document.querySelectorAll('[data-renewal-export-url]'));
    }

    function busyButtons(buttons, busy, label) {
        buttons.filter(Boolean).forEach(function (button) {
            if (busy) {
                if (!savedButtons.has(button)) savedButtons.set(button, button.innerHTML);
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.replaceChildren();
                var icon = document.createElement('i');
                icon.className = 'fa fa-spinner fa-spin';
                icon.setAttribute('aria-hidden', 'true');
                button.append(icon, document.createTextNode(' ' + label));
            } else {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (savedButtons.has(button)) {
                    button.innerHTML = savedButtons.get(button);
                    savedButtons.delete(button);
                }
            }
        });
    }

    async function downloadWorkbook(url, config) {
        var response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
        } catch (networkError) {
            throw new Error(config.error);
        }
        if (!response.ok || response.redirected) {
            var problem = await response.json().catch(function () { return {}; });
            var errors = problem.errors ? Object.values(problem.errors).flat() : [];
            throw new Error(errors.length ? errors.join('；') : config.error);
        }
        if (!(response.headers.get('Content-Type') || '').includes('spreadsheetml')) throw new Error(config.error);
        var blob;
        try { blob = await response.blob(); } catch (downloadError) { throw new Error(config.error); }
        var disposition = response.headers.get('Content-Disposition') || '';
        var encoded = /filename\*=utf-8''([^;]+)/i.exec(disposition);
        var plain = /filename="?([^";]+)"?/i.exec(disposition);
        var filename = config.filename;
        if (encoded) {
            try { filename = decodeURIComponent(encoded[1]); } catch (ignore) {}
        } else if (plain) filename = plain[1];
        var objectUrl = URL.createObjectURL(blob);
        var link = document.createElement('a');
        try {
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
        } finally {
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
        }
    }

    function bindPageExport(button) {
        if (!button) return;
        if (pageExporting || exporting) busyButtons([button], true, pageMessages.exporting);
        button.addEventListener('click', async function () {
            if (pageExporting || exporting) return;
            pageExporting = true;
            busyButtons(pageButtons().concat(content ? [content.querySelector('[data-renewal-detail-export]')] : []), true, pageMessages.exporting);
            if (pageFeedback) {
                pageFeedback.hidden = false;
                pageFeedback.className = 'text-muted';
                pageFeedback.textContent = pageMessages.exporting;
            }
            try {
                await downloadWorkbook(button.dataset.renewalExportUrl, pageMessages);
                if (pageFeedback) {
                    pageFeedback.className = 'text-success';
                    pageFeedback.textContent = pageMessages.done;
                }
            } catch (error) {
                if (pageFeedback) {
                    pageFeedback.className = 'text-danger';
                    pageFeedback.textContent = error.message || pageMessages.error;
                }
            } finally {
                pageExporting = false;
                busyButtons(pageButtons().concat(content ? [content.querySelector('[data-renewal-detail-export]')] : []), false);
            }
        });
    }
    pageButtons().forEach(bindPageExport);
    var modal = document.getElementById('payment-renewal-detail-modal');
    if (page && page.dataset.renewalUrl) {
        initSections();
    }

    if (!modal || !window.jQuery) return;
    var content = modal.querySelector('[data-renewal-detail-content]');
    var feedback = modal.querySelector('[data-renewal-detail-feedback]');
    var title = modal.querySelector('.modal-title');
    var messages = JSON.parse(modal.dataset.messages);
    var parent;
    var base = null, current = {}, controller = null, sequence = 0, returnFocus = null, exporting = false;

    function filtersFromForm() {
        var form = content.querySelector('[data-renewal-detail-filter]');
        if (!form) return Object.assign({}, current);
        var values = Object.fromEntries(new FormData(form).entries());
        var size = content.querySelector('[data-renewal-detail-per-page]');
        values.detail_per_page = size ? size.value : '20';
        return values;
    }

    function showError(message) {
        feedback.classList.add('payment-renewal-detail-error');
        feedback.textContent = message;
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-default btn-sm';
        retry.textContent = messages.retry;
        retry.addEventListener('click', function () { load(current); });
        feedback.appendChild(retry);
    }

    function load(values) {
        if (!base) return;
        if (controller) controller.abort();
        controller = new AbortController();
        var request = ++sequence;
        current = Object.assign({}, values);
        var params = new URLSearchParams(Object.assign({}, parent, base, current));
        feedback.classList.remove('payment-renewal-detail-error');
        feedback.textContent = messages.loading;
        content.setAttribute('aria-busy', 'true');
        content.inert = true;
        fetch(modal.dataset.endpoint + '?' + params.toString(), {
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(async function (response) {
            if (response.redirected) throw new Error(messages.error);
            if (!response.ok) {
                var problem = await response.json().catch(function () { return {}; });
                var errors = problem.errors ? Object.values(problem.errors).flat() : [];
                throw new Error(errors.length ? errors.join('；') : messages.error);
            }
            var html = await response.text();
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fragment = doc.querySelector('[data-renewal-detail-fragment]');
            if (!fragment) throw new Error(messages.error);
            if (request !== sequence) return;
            content.replaceChildren(document.importNode(fragment, true));
            feedback.textContent = '';
            if (exporting || pageExporting) busyButtons([content.querySelector('[data-renewal-detail-export]')], true, pageMessages.exporting || messages.exporting);
        }).catch(function (error) {
            if (error.name === 'AbortError' || request !== sequence) return;
            showError(error.message || messages.error);
        }).finally(function () {
            if (request !== sequence) return;
            content.removeAttribute('aria-busy');
            content.inert = false;
        });
    }

    async function exportDetails(button) {
        if (!base || exporting || pageExporting) return;
        var form = content.querySelector('[data-renewal-detail-filter]');
        if (form && !form.reportValidity()) return;
        var params = new URLSearchParams(Object.assign({}, parent, base, filtersFromForm()));
        params.delete('detail_page');
        params.delete('detail_per_page');
        var request = sequence;
        exporting = true;
        busyButtons([button].concat(pageButtons()), true, pageMessages.exporting || messages.exporting);
        feedback.classList.remove('payment-renewal-detail-error');
        feedback.textContent = messages.exporting;
        try {
            await downloadWorkbook(modal.dataset.exportEndpoint + '?' + params.toString(), {
                error: messages.exportError, filename: messages.exportFilename
            });
            if (request === sequence) feedback.textContent = '';
        } catch (error) {
            if (request === sequence) {
                feedback.classList.add('payment-renewal-detail-error');
                feedback.textContent = error.message || messages.exportError;
            }
        } finally {
            exporting = false;
            busyButtons([button, content.querySelector('[data-renewal-detail-export]')].concat(pageButtons()), false);
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-renewal-detail-open]');
        if (!trigger) return;
        returnFocus = trigger;
        title.textContent = trigger.dataset.title;
        parent = JSON.parse(modal.dataset.parentFilters);
        base = { detail_group: trigger.dataset.group, detail_purchase: trigger.dataset.purchase, detail_kind: trigger.dataset.kind };
        content.replaceChildren();
        window.jQuery(modal).modal('show');
        load({ detail_per_page: '20', detail_page: '1' });
    });
    content.addEventListener('submit', function (event) {
        if (event.target.matches('[data-renewal-detail-filter]')) {
            event.preventDefault();
            load(Object.assign(filtersFromForm(), { detail_page: '1' }));
        } else if (event.target.matches('[data-renewal-detail-jump]')) {
            event.preventDefault();
            load(Object.assign(filtersFromForm(), { detail_page: event.target.querySelector('input').value }));
        }
    });
    content.addEventListener('click', function (event) {
        var exportButton = event.target.closest('[data-renewal-detail-export]');
        if (exportButton) { exportDetails(exportButton); return; }
        var page = event.target.closest('[data-renewal-detail-page]');
        if (page && !page.disabled) load(Object.assign(filtersFromForm(), { detail_page: page.dataset.renewalDetailPage }));
        if (event.target.closest('[data-renewal-detail-reset]')) load({ detail_per_page: '20', detail_page: '1' });
    });
    content.addEventListener('change', function (event) {
        if (event.target.matches('[data-renewal-detail-per-page]')) load(Object.assign(filtersFromForm(), { detail_page: '1' }));
    });
    window.jQuery(modal).on('hidden.bs.modal', function () {
        ++sequence;
        if (controller) controller.abort();
        content.inert = false;
        content.removeAttribute('aria-busy');
        if (returnFocus && returnFocus.isConnected) returnFocus.focus();
    });

    function initSections() {
        var applied = new URLSearchParams(JSON.parse(page.dataset.renewalFilters));
        applied.delete('risk_date_mode');
        var sectionMessages = JSON.parse(page.dataset.renewalSectionMessages);
        var renewalStageKeys = ['stage', 'stage_page'];
        var pending = new Map();
        var refreshIcon = document.querySelector('.main-header .navbar .fa-refresh');
        var refreshLink = refreshIcon && refreshIcon.closest('a');

        function belongsTo(module, name) {
            return name.startsWith(module + '_') || (module === 'renewal' && renewalStageKeys.includes(name));
        }

        function withSection(module, source) {
            var params = new URLSearchParams(applied);
            Array.from(applied.keys()).forEach(function (name) {
                if (belongsTo(module, name)) params.delete(name);
            });
            source.forEach(function (value, name) {
                if (belongsTo(module, name)) params.set(name, value);
            });
            return params;
        }

        function syncControls() {
            page.querySelectorAll('[data-renewal-navigation]').forEach(function (link) {
                var module = link.closest('[data-renewal-region]').dataset.renewalRegion;
                var url = new URL(link.href);
                var params = withSection(module, url.searchParams);
                if (url.searchParams.has('load_initial')) params.set('load_initial', '1');
                if (url.searchParams.has('load_renewal')) params.set('load_renewal', '1');
                url.search = params;
                link.href = url.href;
            });
            page.querySelectorAll('[data-renewal-module] input[type="hidden"]').forEach(function (input) {
                if (applied.has(input.name) && !belongsTo(input.form.dataset.renewalModule, input.name)) {
                    input.value = applied.get(input.name);
                }
            });
            pageButtons().forEach(function (button) {
                var url = new URL(button.dataset.renewalExportUrl, window.location.href);
                url.search = new URLSearchParams(applied);
                url.searchParams.delete('stage_page');
                button.dataset.renewalExportUrl = url.href;
            });
            if (modal) {
                modal.dataset.parentFilters = JSON.stringify(Object.fromEntries(
                    Array.from(applied.entries()).filter(function (entry) { return belongsTo('initial', entry[0]); })
                ));
            }
        }

        async function refresh(region, params, updateUrl) {
            var module = region.dataset.renewalRegion;
            var article = region.querySelector('article');
            var status = article.querySelector('[data-renewal-section-status]');
            var message = status.querySelector('[data-renewal-status-message]');
            var retry = status.querySelector('[data-renewal-section-retry]');
            var previous = pending.get(module);
            if (previous && article.hasAttribute('data-renewal-placeholder')) return;
            if (previous) previous.abort();
            var request = new AbortController();
            pending.set(module, request);
            var url = new URL(page.dataset.renewalUrl, window.location.href);
            url.search = params;
            url.searchParams.set('section', module);
            article.dataset.renewalState = 'loading';
            retry.hidden = true;
            status.hidden = false;
            message.textContent = sectionMessages.loading;
            try {
                var response = await fetch(url, {
                    credentials: 'same-origin',
                    signal: request.signal,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (response.redirected) throw new Error(sectionMessages.error);
                if (!response.ok) {
                    var problem = await response.json().catch(function () { return {}; });
                    var errors = problem.errors ? Object.values(problem.errors).flat() : [];
                    throw new Error(errors.length ? errors.join('；') : sectionMessages.error);
                }
                var html = await response.text();
                if (pending.get(module) !== request) return;
                var fragment = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-renewal-section="' + module + '"]');
                if (!fragment) throw new Error(sectionMessages.error);
                var replacement = document.importNode(fragment, true);
                var restoreFocus = article.contains(document.activeElement);
                var focusMode = restoreFocus && document.activeElement.name === 'renewal_mode';
                var focusMonth = restoreFocus && document.activeElement.dataset.renewalMonth;
                applied = withSection(module, new URLSearchParams(JSON.parse(replacement.dataset.renewalApplied)));
                article.replaceWith(replacement);
                syncControls();
                replacement.querySelectorAll('[data-logistics-filters]').forEach(function (form) {
                    form.dispatchEvent(new Event('logistics:initialize-filters', { bubbles: true }));
                });
                bindPageExport(replacement.querySelector('[data-renewal-export-url]'));
                if (restoreFocus) {
                    var focusSelector = '.payment-renewal-filter button[type="submit"]';
                    if (focusMode) focusSelector = 'input[type="radio"][name="renewal_mode"]:checked';
                    if (focusMonth) focusSelector = '[data-renewal-month="' + focusMonth + '"]';
                    replacement.querySelector(focusSelector).focus({ preventScroll: true });
                }
                if (updateUrl) {
                    var nextUrl = new URL(window.location.href);
                    nextUrl.search = applied;
                    if (nextUrl.href !== window.location.href) window.history.pushState(null, '', nextUrl);
                    if (refreshLink) refreshLink.href = nextUrl.href;
                }
            } catch (error) {
                if (request.signal.aborted || pending.get(module) !== request) return;
                article.dataset.renewalState = 'error';
                var appliedMode = applied.get('renewal_mode') || 'normal';
                article.querySelectorAll('input[type="radio"][name="renewal_mode"]').forEach(function (mode) {
                    mode.checked = mode.value === appliedMode;
                });
                message.textContent = error instanceof TypeError ? sectionMessages.error : (error.message || sectionMessages.error);
                retry.onclick = function () {
                    refresh(region, withSection(module, params), updateUrl);
                };
                retry.hidden = false;
            } finally {
                if (pending.get(module) === request) {
                    pending.delete(module);
                }
            }
        }

        page.addEventListener('input', function (event) {
            if (!event.target.matches('.payment-renewal-filter input[type="date"]')) return;
            var dates = event.target.form.querySelectorAll('input[type="date"]');
            dates[1].setCustomValidity(dates[0].value && dates[1].value && dates[1].value < dates[0].value ? sectionMessages.invalidDate : '');
        });
        page.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches('[data-renewal-module]')) return;
            event.preventDefault();
            refresh(form.closest('[data-renewal-region]'), withSection(form.dataset.renewalModule, new FormData(form)), true);
        });
        page.addEventListener('change', function (event) {
            if (event.target.matches('input[type="radio"][name="renewal_mode"]')) event.target.form.requestSubmit();
        });
        page.addEventListener('click', function (event) {
            var shortcut = event.target.closest('[data-renewal-month]');
            if (shortcut) {
                var form = shortcut.form;
                var offset = form.elements[form.dataset.renewalModule + '_timezone'].value;
                var offsetMinutes = (+offset.slice(1, 3) * 60 + +offset.slice(4)) * (offset[0] === '-' ? -1 : 1);
                var year = new Date(Date.now() + offsetMinutes * 60000).getUTCFullYear();
                var month = Number(shortcut.dataset.renewalMonth);
                var monthPrefix = year + '-' + String(month).padStart(2, '0');
                var lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
                var dates = form.querySelectorAll('input[type="date"]');
                dates[0].value = monthPrefix + '-01';
                dates[1].value = monthPrefix + '-' + lastDay;
                dates[1].setCustomValidity('');
                form.requestSubmit();
                return;
            }
            var info = event.target.closest('[data-renewal-mode-info]');
            if (info) info.focus({ preventScroll: true });
            var link = event.target.closest('[data-renewal-navigation]');
            if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (link.classList.contains('disabled')) return;
            var region = link.closest('[data-renewal-region]');
            refresh(region, withSection(region.dataset.renewalRegion, new URL(link.href).searchParams), !link.closest('[data-renewal-placeholder]'));
        });
        window.addEventListener('popstate', function () { window.location.reload(); });
        page.querySelectorAll('[data-renewal-region] > [data-renewal-placeholder]').forEach(function (placeholder) {
            refresh(placeholder.closest('[data-renewal-region]'), applied, false);
        });
    }
}());
