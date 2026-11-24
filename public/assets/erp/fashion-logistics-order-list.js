(function () {
    'use strict';

    var list = document.querySelector('.fashion-order-list');
    if (!list) return;

    var size = list.querySelector('[data-fashion-page-size-select]');
    if (size) {
        size.addEventListener('change', function () {
            size.form.requestSubmit();
        });
    }

    var pickers = [];
    var filter = list.querySelector('.fashion-order-filter');
    var pickerFields = new Set();

    function closePickers(except) {
        pickers.forEach(function (picker) {
            if (picker !== except) picker.open = false;
        });
    }

    filter.addEventListener('formdata', function (event) {
        pickerFields.forEach(function (name) { event.formData.delete(name); });
    });

    filter.querySelectorAll('select').forEach(function (select) {
        var label = select.labels[0];
        var choices = Array.from(select.options);
        var details = document.createElement('details');
        var summary = document.createElement('summary');
        var panel = document.createElement('div');
        var options = document.createElement('div');
        var radioName = 'fashion-option-' + select.name;
        var search;

        pickerFields.add(radioName);
        pickers.push(details);
        details.className = 'flt-picker';
        summary.id = select.id + '-summary';
        label.id = select.id + '-label';
        summary.setAttribute('aria-labelledby', label.id + ' ' + summary.id);
        panel.className = 'flt-picker-panel';
        options.className = 'flt-picker-options';

        function updateSummary() {
            summary.textContent = select.selectedOptions[0]?.label || '';
            summary.title = summary.textContent;
        }

        function renderOptions() {
            var term = search ? search.value.trim().toLocaleLowerCase() : '';
            var matches = choices.filter(function (choice) {
                return choice.label.toLocaleLowerCase().includes(term);
            });
            options.replaceChildren();
            if (matches.length === 0) {
                var empty = document.createElement('p');
                empty.textContent = list.dataset.noOptions;
                options.append(empty);
            }
            matches.forEach(function (choice) {
                var row = document.createElement('label');
                var radio = document.createElement('input');
                var name = document.createElement('span');
                radio.type = 'radio';
                radio.name = radioName;
                radio.checked = choice.selected;
                radio.disabled = choice.disabled;
                name.textContent = choice.label;
                radio.addEventListener('change', function () {
                    select.selectedIndex = choice.index;
                    updateSummary();
                });
                row.append(radio, name);
                options.append(row);
            });
        }

        if (select.hasAttribute('data-fashion-searchable')) {
            search = document.createElement('input');
            search.type = 'search';
            search.placeholder = list.dataset.searchOptions;
            search.setAttribute('aria-label', list.dataset.searchOptions);
            search.addEventListener('input', renderOptions);
            panel.append(search);
        }
        panel.append(options);
        details.append(summary, panel);
        select.after(details);
        select.hidden = true;
        updateSummary();

        details.addEventListener('toggle', function () {
            if (!details.open) return;
            closePickers(details);
            renderOptions();
            if (search) search.focus();
        });
        details.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !details.open) return;
            event.preventDefault();
            details.open = false;
            summary.focus();
        });
        label.addEventListener('click', function (event) {
            event.preventDefault();
            summary.focus();
            details.open = !details.open;
        });
        window.addEventListener('pageshow', updateSummary);
    });

    document.addEventListener('click', function (event) {
        closePickers(event.target.closest('.fashion-order-filter .flt-picker'));
    });

    var selectAll = list.querySelector('[data-fashion-select-all]');
    var rows = Array.from(list.querySelectorAll('[data-fashion-select-order]'));
    var count = list.querySelector('[data-fashion-selection-count]');
    var clear = list.querySelector('[data-fashion-clear-selection]');

    function updateSelection() {
        var selected = 0;
        rows.forEach(function (checkbox) {
            checkbox.closest('tr').dataset.selected = checkbox.checked ? '1' : '0';
            if (checkbox.checked) selected++;
        });
        selectAll.checked = rows.length > 0 && selected === rows.length;
        selectAll.indeterminate = selected > 0 && selected < rows.length;
        count.textContent = list.dataset.selectedOrders.replace(':count', selected);
        clear.disabled = selected === 0;
    }

    function selectPage(checked) {
        rows.forEach(function (checkbox) { checkbox.checked = checked; });
        updateSelection();
    }

    selectAll.addEventListener('change', function () { selectPage(selectAll.checked); });
    list.querySelector('.fashion-order-table').addEventListener('change', function (event) {
        if (event.target.matches('[data-fashion-select-order]')) updateSelection();
    });
    clear.addEventListener('click', function () { selectPage(false); });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) selectPage(false);
    });
    selectPage(false);

    var modal = document.getElementById('fashion-order-detail-modal');
    if (!modal || !window.jQuery) return;

    var detailModal = window.jQuery(modal);
    var detailBody = modal.querySelector('[data-fashion-detail-body]');
    var detailLoading = modal.querySelector('[data-fashion-detail-loading]');
    var detailError = modal.querySelector('[data-fashion-detail-error]');
    var detailContent = modal.querySelector('[data-fashion-detail-content]');
    var translations = JSON.parse(modal.querySelector('[data-fashion-detail-labels]').textContent);
    var text = translations.common;
    var nbcText = translations.nbc;
    var platform = modal.dataset.platform;
    var isXShoppy = platform === 'xshoppy';
    var isNbc = platform === 'nbc';
    var activeDetail = null;
    var detailTrigger = null;

    function node(tag, className, value) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (value != null) element.textContent = value;
        return element;
    }

    function display(value) {
        return value == null || value === '' ? '—' : String(value);
    }

    function platformLabel(labels, value, fallback) {
        return Object.prototype.hasOwnProperty.call(labels, value) ? labels[value] : fallback;
    }

    function fields(entries, grid) {
        var group = node('dl', 'fashion-detail-fields ' + (grid ? 'is-grid' : 'is-stacked'));
        entries.forEach(function (entry) {
            var field = node('div');
            field.append(node('dt', null, entry[0]), node('dd', null, display(entry[1])));
            group.append(field);
        });
        return group;
    }

    function detailCard(title, iconClass, meta) {
        var card = node('section', 'fashion-detail-card');
        var heading = node('div', 'fashion-detail-card-heading');
        var titleNode = node('h4');
        var icon = node('i', 'fa ' + iconClass);
        icon.setAttribute('aria-hidden', 'true');
        titleNode.append(icon, document.createTextNode(title));
        heading.append(titleNode);
        if (meta) heading.append(node('span', null, meta));
        card.append(heading);
        return card;
    }

    function productCard(item) {
        var card = node('article', 'fashion-detail-item');
        var info = node('div', 'fashion-detail-item-info');
        if (typeof item.image_url === 'string' && /^https?:\/\//i.test(item.image_url)) {
            var image = node('img');
            image.src = item.image_url;
            image.alt = '';
            image.loading = 'lazy';
            image.referrerPolicy = 'no-referrer';
            info.append(image);
        }
        var copy = node('div', 'fashion-detail-item-copy');
        copy.append(
            node('strong', null, item.product_name || text.unnamed_product),
            node('p', null, text.sku + ' · ' + display(item.sku))
        );
        info.append(copy);
        card.append(info, fields([
            [text.quantity, item.quantity],
            [isNbc ? nbcText.subtotal : text.subtotal, item.subtotal],
            [isNbc ? nbcText.total : text.total, item.total]
        ], true));
        return card;
    }

    function renderDetail(order, items) {
        var platformName = isNbc
            ? platformLabel(nbcText.platforms, order.source_platform, display(order.source_platform))
            : (isXShoppy ? 'XShoppy' : 'Shopline');
        var orderStatus = isNbc ? platformLabel(nbcText.orders, order.status, nbcText.unknown)
            : platformLabel(isXShoppy ? text.xshoppy_statuses : text.shopline_statuses, order.status, display(order.status));
        var statusTags = [[orderStatus, '']];
        if (!isXShoppy) {
            var fallback = isNbc ? nbcText.unknown : '—';
            var paymentStatus = platformLabel(
                isNbc ? nbcText.payments : text.shopline_payments,
                order.payment_status,
                isNbc ? fallback : (order.payment_status || fallback)
            );
            var fulfillmentStatus = platformLabel(
                isNbc ? nbcText.fulfillments : text.shopline_fulfillments,
                order.fulfillment_status,
                isNbc ? fallback : (order.fulfillment_status || fallback)
            );
            statusTags.push(
                [paymentStatus, ['paid', '230'].includes(order.payment_status) ? ' is-paid' : ''],
                [fulfillmentStatus, ' is-neutral']
            );
        }
        var createdLabel = isXShoppy ? text.stored_created_time : text.created_at;
        var hero = node('header', 'fashion-detail-hero');
        var identity = node('div');
        identity.append(
            node('p', null, [platformName, order.store_name].filter(Boolean).join(' · ')),
            node('h3', null, display(order.order_number || order.external_order_id)),
            node('p', null, createdLabel + ' · ' + display(order.order_created_at))
        );
        var tags = node('div', 'fashion-detail-tags');
        statusTags.forEach(function (status) {
            if (status[0] !== '—') tags.append(node('span', 'fashion-detail-tag' + status[1], status[0]));
        });
        hero.append(identity, tags);

        var layout = node('div', 'fashion-detail-layout');
        var primary = node('div', 'fashion-detail-column');
        var side = node('aside', 'fashion-detail-column');
        var products = detailCard(text.detail_products, 'fa-cubes', text.detail_item_count.replace(':count', items.length));
        var itemList = node('div', 'fashion-detail-items');
        items.forEach(function (item) { itemList.append(productCard(item)); });
        if (items.length === 0) itemList.append(node('p', 'text-muted', text.no_items));
        products.append(itemList);

        var customer = detailCard(text.detail_customer, 'fa-user-o');
        var customerFields = [
            [text.customer, order.customer_name],
            [text.detail_email, order.customer_email],
            [text.detail_phone, order.customer_phone],
            [text.address, order.shipping_address]
        ];
        if (isNbc) customerFields.push([text.detail_note, order.customer_note]);
        customer.append(fields(customerFields, true));
        var rawCard = detailCard(text.raw_title, 'fa-code', text.raw_hint);
        var raw = node('details', 'fashion-detail-raw');
        raw.append(
            node('summary', null, isNbc ? nbcText.raw : text.raw.replace(':platform', platformName)),
            node('pre', null, JSON.stringify(order.source_payload ?? (isXShoppy ? [] : {}), null, 2))
        );
        rawCard.append(raw);
        primary.append(products, customer, rawCard);

        var payment = detailCard(text.detail_payment, 'fa-credit-card');
        var amount = node('div', 'fashion-detail-amount');
        amount.append(
            node('span', null, text.amount),
            node('strong', null, display(order.currency) + ' ' + display(order.total_amount))
        );
        var paymentFields = [
            [text.payment_method, order.payment_method_title || order.payment_method],
            [isNbc ? nbcText.transaction_id : text.transaction, order.transaction_id]
        ];
        if (isXShoppy) {
            paymentFields.push([text.shipping_discount_tax, [order.shipping_amount, order.discount_amount, order.tax_amount].map(display).join(' / ')]);
        }
        payment.append(amount, fields(paymentFields));

        var store = detailCard(text.detail_store, 'fa-building-o');
        var storeFields = [[text.store, order.store_name], [text.domain, order.source_domain]];
        if (!isXShoppy) {
            storeFields.push([text.marketing_source, platformLabel(isNbc ? nbcText.sources : text.shopline_sources, order.marketing_source, isNbc ? nbcText.unknown : text.unknown_source)]);
        }
        store.append(fields(storeFields));

        var timeline = detailCard(text.detail_timeline, 'fa-clock-o');
        var dates = [
            [createdLabel, order.order_created_at],
            [text.paid_at, isNbc ? order.paid_at_utc : order.paid_at],
            [text.modified_at, order.order_modified_at],
            [text.synced_at, order.source_synced_at]
        ];
        if (isXShoppy) {
            dates.unshift([text.source_created_time, order.source_order_created_at]);
            dates.splice(4, 0, [text.completed_at, order.completed_at]);
        }
        timeline.append(fields(dates));
        side.append(payment, store, timeline);
        layout.append(primary, side);

        detailContent.replaceChildren(hero, layout);
    }

    detailModal.on('hide.bs.modal', function () {
        if (activeDetail) activeDetail.abort();
        activeDetail = null;
    });
    detailModal.on('hidden.bs.modal', function () {
        if (detailTrigger) detailTrigger.focus();
    });

    list.addEventListener('click', async function (event) {
        var trigger = event.target.closest('[data-fashion-order-detail]');
        if (!trigger) return;

        if (activeDetail) activeDetail.abort();
        var request = new AbortController();
        activeDetail = request;
        detailTrigger = trigger;
        detailError.hidden = true;
        detailContent.hidden = true;
        detailContent.replaceChildren();
        detailLoading.hidden = false;
        detailBody.setAttribute('aria-busy', 'true');
        detailBody.scrollTop = 0;
        detailModal.modal('show', trigger);
        var shownAt = performance.now();
        var timedOut = false;
        var timeout = setTimeout(function () {
            timedOut = true;
            request.abort();
        }, 20000);

        var errorMessage;
        try {
            var response = await fetch(trigger.dataset.url, {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: request.signal
            });
            if (!response.ok) throw new Error(text.detail_load_failed);
            var data = await response.json();
            if (!data?.order || !Array.isArray(data.items)) throw new Error(text.detail_load_failed);
            if (activeDetail === request) renderDetail(data.order, data.items);
        } catch (error) {
            errorMessage = timedOut ? text.timeout : text.detail_load_failed;
        } finally {
            clearTimeout(timeout);
        }
        if (activeDetail !== request) return;
        await new Promise(function (resolve) {
            setTimeout(resolve, Math.max(0, 250 - (performance.now() - shownAt)));
        });
        if (activeDetail !== request) return;

        activeDetail = null;
        detailLoading.hidden = true;
        detailBody.setAttribute('aria-busy', 'false');
        if (errorMessage) {
            detailError.textContent = errorMessage;
            detailError.hidden = false;
        } else {
            detailContent.hidden = false;
        }
    });

    var syncForm = document.querySelector('[data-fashion-sync-form]');
    if (!syncForm) return;

    var syncModal = syncForm.closest('.fashion-sync-modal');
    var syncTableScroll = syncModal.querySelector('.fashion-sync-table-scroll');
    var syncRows = syncModal.querySelector('[data-fashion-sync-rows]');
    var syncSummary = syncModal.querySelector('[data-fashion-sync-summary]');
    var syncRange = syncModal.querySelector('[data-fashion-sync-range]');
    var syncPages = syncModal.querySelector('[data-fashion-sync-pages]');
    var syncSubmit = syncForm.querySelector('button[type="submit"]');
    var syncColumnCount = syncModal.querySelector('thead tr').cells.length;
    var syncStatusLabels = isNbc ? nbcText.sync_states : text.sync_states;
    var syncNumber = new Intl.NumberFormat();
    var activeSync = null;

    function interpolate(template, values) {
        return template.replace(/:([a-z_]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match;
        });
    }

    function syncMessage(message, state) {
        var row = node('tr');
        var cell = node('td', 'fashion-sync-message');
        var status = node('div', 'fashion-sync-feedback');
        status.setAttribute('role', state === 'error' ? 'alert' : 'status');
        status.dataset.state = state;
        if (state === 'loading') {
            var spinner = node('span', 'flt-spinner');
            spinner.setAttribute('aria-hidden', 'true');
            status.append(spinner);
        }
        status.append(node('span', null, message));
        cell.colSpan = syncColumnCount;
        cell.append(status);
        row.append(cell);
        syncRows.replaceChildren(row);
    }

    function readoutBadge(label, state) {
        var badge = node('span', 'fashion-sync-badge', label);
        badge.dataset.state = state;
        return badge;
    }

    function syncStoreRow(store) {
        var row = node('tr');
        var shop = node('td');
        shop.append(node('strong', null, display(store.handle)));
        row.append(shop);
        if (isNbc) {
            row.append(
                node('td', null, platformLabel(nbcText.platforms, store.platform, nbcText.unknown)),
                node('td', null, display(store.operator))
            );
        } else {
            shop.append(node('span', 'fashion-sync-subtitle', display(store.operator)));
        }
        var configuration = node('td');
        var missingLabel = isNbc ? nbcText.missing : text.missing_credentials;
        configuration.append(readoutBadge(store.ready ? text.ready : missingLabel, store.ready ? 'success' : 'missing'));
        var syncStatus = node('td');
        var state = Object.prototype.hasOwnProperty.call(syncStatusLabels, store.status) ? store.status : 'pending';
        syncStatus.append(readoutBadge(syncStatusLabels[state], state));
        row.append(
            configuration,
            node('td', null, store.last_succeeded_at || text.never_synced),
            syncStatus,
            node('td', 'fashion-sync-number', syncNumber.format(store.total_count ?? 0)),
            node('td', 'fashion-sync-number', syncNumber.format(store.today_count ?? 0))
        );
        var errorCell = node('td');
        if (store.last_error) {
            var error = node('details', 'fashion-sync-error');
            var summary = node('summary');
            summary.append(node('span', null, store.last_error));
            error.append(summary, node('div', null, store.last_error));
            errorCell.append(error);
        } else {
            errorCell.textContent = '—';
        }
        row.append(errorCell);
        return row;
    }

    function renderSyncSummary(summary) {
        syncSummary.replaceChildren();
        [
            [text.all_stores, summary.total, ''],
            [text.ready, summary.ready, 'success'],
            [text.missing, summary.missing, 'missing'],
            [text.sync_states.failed, summary.failed, 'failed']
        ].forEach(function (metric) {
            var group = node('div');
            group.dataset.state = metric[2];
            group.append(node('dt', null, metric[0]), node('dd', null, syncNumber.format(metric[1] ?? 0)));
            syncSummary.append(group);
        });
        syncSummary.hidden = false;
    }

    function pageButton(label, page, disabled) {
        var button = node('button', 'flt-btn', label);
        button.type = 'button';
        button.disabled = disabled;
        button.addEventListener('click', function () { loadSyncPage(page); });
        return button;
    }

    function renderSyncPagination(pagination) {
        var current = pagination.current_page;
        var last = pagination.last_page;
        if (pagination.from != null && pagination.to != null) {
            syncRange.textContent = interpolate(text.sync_range, pagination);
        } else {
            syncRange.textContent = interpolate(text.sync_total, pagination);
        }
        syncPages.replaceChildren();
        if (last <= 1) return;
        syncPages.append(pageButton(text.previous, current - 1, current <= 1));
        if (isNbc) {
            syncPages.append(node('strong', null, interpolate(text.page_label, {page: current, pages: last})));
        } else {
            var previousPage = 0;
            var pageNumbers = [...new Set([1, current - 2, current - 1, current, current + 1, current + 2, last])]
                .filter(function (page) { return page >= 1 && page <= last; })
                .sort(function (a, b) { return a - b; });
            pageNumbers.forEach(function (page) {
                if (page > previousPage + 1) syncPages.append(node('span', null, '…'));
                if (page === current) {
                    var currentPage = node('strong', null, page);
                    currentPage.setAttribute('aria-current', 'page');
                    syncPages.append(currentPage);
                } else {
                    syncPages.append(pageButton(String(page), page, false));
                }
                previousPage = page;
            });
        }
        syncPages.append(pageButton(text.next, current + 1, current >= last));
    }

    async function loadSyncPage(page) {
        if (activeSync) return;
        var request = new AbortController();
        activeSync = request;
        var params = new URLSearchParams(new FormData(syncForm));
        params.set('page', String(page));
        var url = new URL(syncForm.dataset.url, window.location.href);
        url.search = params.toString();
        syncSubmit.disabled = true;
        syncTableScroll.setAttribute('aria-busy', 'true');
        syncTableScroll.scrollTop = 0;
        syncSummary.hidden = true;
        syncRange.textContent = '';
        syncPages.replaceChildren();
        syncMessage(text.loading_sync, 'loading');
        var timer = setTimeout(function () { request.abort(); }, 20000);

        try {
            var response = await fetch(url, {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: request.signal
            });
            if (!response.ok) throw new Error();
            var data = await response.json();
            if (activeSync !== request) return;
            if (!Array.isArray(data.rows) || !data.summary || !data.pagination) throw new Error();
            renderSyncSummary(data.summary);
            if (data.rows.length) {
                syncRows.replaceChildren(...data.rows.map(syncStoreRow));
            } else {
                syncMessage(isNbc ? nbcText.no_stores : text.no_matching_stores, 'empty');
            }
            renderSyncPagination(data.pagination);
        } catch (error) {
            if (activeSync !== request) return;
            syncMessage(request.signal.aborted ? text.timeout : text.sync_load_failed, 'error');
        } finally {
            clearTimeout(timer);
            if (activeSync === request) {
                activeSync = null;
                syncSubmit.disabled = false;
                syncTableScroll.setAttribute('aria-busy', 'false');
            }
        }
    }

    syncForm.addEventListener('submit', function (event) {
        event.preventDefault();
        loadSyncPage(1);
    });
    window.jQuery(syncModal).on('shown.bs.modal', function () {
        loadSyncPage(1);
    }).on('hide.bs.modal', function () {
        if (activeSync) activeSync.abort();
        activeSync = null;
    });
}());
