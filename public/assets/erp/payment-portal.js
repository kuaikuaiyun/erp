(function ($) {
    'use strict';

    function notify(type, title, message) {
        if (window.ErpAdminNotifier) {
            window.ErpAdminNotifier.notify({ type: type, title: title, message: message });
            return;
        }

        window.alert(message);
    }

    $(function () {
        var page = $('.payment-portal-page');
        var config = window.PaymentPortal || {};

        if (!page.length) {
            return;
        }

        page.on('change', '[data-payment-per-page]', function () {
            $(this).closest('form').find('[name="page"]').val('1');
            this.form.submit();
        });
        page.on('submit', '.payment-pagination-form', function (event) {
            if ($.trim($(this).find('[name="page"]').val()) === '') {
                event.preventDefault();
            }
        });

        $(document).on('click', '[data-payment-placeholder]', function () {
            notify('info', config.actionTitle, config.actionMessage);
        });

        page.on('click', '[data-payment-alert-assignment]', function (event) {
            event.stopPropagation();
            var trigger = $(this);
            var modal = $('#payment-alert-assignment-modal');
            var form = modal.find('[data-payment-assignment-form]');
            var assignment = trigger.data('assignment') || {};
            var code = String(trigger.data('alertCode') || '');
            form.attr('action', String(config.assignmentUrl || '').replace('__ALERT__', encodeURIComponent(code)));
            form[0].reset();
            modal.find('[data-payment-assignment-summary]').text(code + ' · ' + String(trigger.data('alertProduct') || ''));
            modal.find('[name="department_id"]').val(assignment.department_id || '');
            filterAssignmentUsers(assignment.assignee_id || '');
            modal.find('[name="deadline_at"]').val(assignment.deadline_at || defaultAssignmentDeadline());
            modal.find('[name="assignment_note"]').val(assignment.note || '');
            modal.modal('show');
        });

        function defaultAssignmentDeadline() {
            var date = new Date(Date.now() + 24 * 60 * 60 * 1000);
            date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
            return date.toISOString().slice(0, 16);
        }

        function filterAssignmentUsers(selectedUser) {
            var modal = $('#payment-alert-assignment-modal');
            var departmentId = String(modal.find('[data-payment-assignment-department]').val() || '');
            var userSelect = modal.find('[data-payment-assignment-user]');
            userSelect.find('option[data-department-id]').each(function () {
                $(this).prop('hidden', String($(this).data('departmentId')) !== departmentId);
            });
            userSelect.val(selectedUser ? String(selectedUser) : '');
            if (!userSelect.val()) userSelect.val('');
        }

        page.on('change', '[data-payment-assignment-department]', function () {
            filterAssignmentUsers('');
        });

        page.on('click', '[data-payment-alert-rules]', function (event) {
            event.stopPropagation();
            $('#payment-alert-rules-modal').modal('show');
        });
        if (config.openAlertRules && $('#payment-alert-rules-modal').length) {
            $('#payment-alert-rules-modal').modal('show');
        }

        var alertDetailModal = $('#payment-alert-detail-modal');
        var alertDetailRequest = null;
        function openAlertDetail(url) {
            if (!alertDetailModal.length || !url) return;
            alertDetailModal.modal('show');
            alertDetailModal.find('[data-payment-alert-detail-loading]').prop('hidden', false);
            alertDetailModal.find('[data-payment-alert-detail-error]').prop('hidden', true);
            alertDetailModal.find('[data-payment-alert-detail-body]').empty();
            if (alertDetailRequest) alertDetailRequest.abort();
            alertDetailRequest = $.ajax({ url: url, dataType: 'html', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).done(function (html) {
                alertDetailModal.find('[data-payment-alert-detail-body]').html(html);
            }).fail(function (xhr, status) {
                if (status !== 'abort') alertDetailModal.find('[data-payment-alert-detail-error]').text((config.alertDetail || {}).load_failed || '').prop('hidden', false);
            }).always(function () {
                alertDetailModal.find('[data-payment-alert-detail-loading]').prop('hidden', true);
                alertDetailRequest = null;
            });
        }
        page.on('click', '[data-payment-alert-detail]', function (event) {
            if ($(event.target).closest('[data-payment-alert-assignment]').length) return;
            event.preventDefault();
            event.stopPropagation();
            openAlertDetail(String($(this).data('paymentAlertDetail') || ''));
        });
        page.on('keydown', 'tr[data-payment-alert-detail]', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            openAlertDetail(String($(this).data('paymentAlertDetail') || ''));
        });
        alertDetailModal.on('hidden.bs.modal', function () { if (alertDetailRequest) alertDetailRequest.abort(); });

        page.on('submit', '[data-payment-quick-search]', function (event) {
            event.preventDefault();
            var keyword = $.trim($(this).find('[name="keyword"]').val() || '');
            var target = String(config.quickSearchUrl || '/payment-orders');
            window.location.href = target + (keyword ? (target.indexOf('?') === -1 ? '?' : '&') + 'keyword=' + encodeURIComponent(keyword) : '');
        });

        page.on('click', '[data-payment-tab]', function () {
            var tab = String($(this).data('payment-tab'));
            page.find('[data-payment-tab]').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            page.find('[data-payment-tab-panel]').prop('hidden', true);
            page.find('[data-payment-tab-panel="' + tab + '"]').prop('hidden', false);
        });

        page.on('click', '[data-payment-account-create]', function () {
            $('#payment-account-create-modal').modal('show');
        });

        page.find('[data-payment-filter-form]').each(function () {
            var form = $(this);
            var serverFilterForm = form.is('[data-payment-server-filter-form]');
            var rows = page.find('[data-payment-filterable]');
            var empty = page.find('[data-payment-filter-empty]');
            var accountCombobox = form.find('[data-payment-account-combobox]');
            var accountInput = accountCombobox.find('[data-payment-account-input]');
            var accountOptions = accountCombobox.find('[data-payment-account-option]');
            var accountOptionList = accountCombobox.find('[data-payment-account-options]');

            function matches(row, key, value) {
                if (!value) {
                    return true;
                }

                if (key === 'keyword' || key === 'product') {
                    return String(row.data(key) || '').toLowerCase().indexOf(value.toLowerCase()) !== -1;
                }

                if (key === 'period_start') {
                    return String(row.data('periodEnd') || '') >= value;
                }

                if (key === 'period_end') {
                    return String(row.data('periodStart') || '') <= value;
                }

                if (key === 'cutoff') {
                    return String(row.data('cutoff') || '') <= value;
                }

                if (key === 'account') {
                    var accountQuery = value.toLowerCase();
                    var accountValues = [row.data('account'), row.data('accountName')];

                    return accountValues.some(function (accountValue) {
                        return String(accountValue || '').toLowerCase().indexOf(accountQuery) !== -1;
                    });
                }

                return String(row.data(key) || '') === value;
            }

            function filterRows() {
                var filters = {};
                form.find('[data-payment-filter]').each(function () {
                    filters[String($(this).data('payment-filter'))] = String($(this).val() || '').trim();
                });
                var visible = 0;
                rows.each(function () {
                    var row = $(this);
                    var isMatch = Object.keys(filters).every(function (key) { return matches(row, key, filters[key]); });
                    row.toggle(isMatch);
                    if (isMatch) {
                        visible += 1;
                    }
                });
                empty.prop('hidden', visible !== 0);
            }

            function closeAccountOptions() {
                accountOptionList.prop('hidden', true);
                accountCombobox.find('[data-payment-account-toggle]').attr('aria-expanded', 'false');
            }

            function openAccountOptions() {
                var accountQuery = String(accountInput.val() || '').trim().toLowerCase();
                var visibleOptions = 0;

                accountOptions.each(function () {
                    var option = $(this);
                    var optionValues = [option.data('accountValue'), option.text()];
                    var isMatch = optionValues.some(function (optionValue) {
                        return String(optionValue || '').toLowerCase().indexOf(accountQuery) !== -1;
                    });

                    option.toggle(isMatch);
                    if (isMatch) {
                        visibleOptions += 1;
                    }
                });

                accountOptionList.prop('hidden', visibleOptions === 0);
                accountCombobox.find('[data-payment-account-toggle]').attr('aria-expanded', visibleOptions !== 0 ? 'true' : 'false');
            }

            form.on('submit', function (event) {
                if (!serverFilterForm) {
                    event.preventDefault();
                    filterRows();
                }
            });
            form.on('reset', function () {
                window.setTimeout(function () {
                    if (!serverFilterForm) {
                        filterRows();
                    }
                    closeAccountOptions();
                }, 0);
            });
            if (!serverFilterForm) {
                form.on('input change', '[data-payment-filter]', filterRows);
            }

            if (accountCombobox.length) {
                accountInput.on('focus input', openAccountOptions);
                accountInput.on('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeAccountOptions();
                    }
                });
                accountCombobox.on('click', '[data-payment-account-toggle]', function () {
                    if (accountOptionList.prop('hidden')) {
                        accountInput.trigger('focus');
                    } else {
                        closeAccountOptions();
                    }
                });
                accountCombobox.on('click', '[data-payment-account-option]', function () {
                    accountInput.val($(this).text()).trigger('input').trigger('change');
                    closeAccountOptions();
                });
                $(document).on('mousedown.payment-account-combobox', function (event) {
                    if (!$(event.target).closest('[data-payment-account-combobox]').length) {
                        closeAccountOptions();
                    }
                });
            }
        });

        var paymentOrderModal = $('#payment-order-detail-modal');
        if (paymentOrderModal.length && config.paymentOrderDetailUrl) {
            var paymentOrderRequest = null;
            var paymentOrderText = config.paymentOrderDetail || {};
            function orderEscape(value) { return $('<div>').text(value === null || typeof value === 'undefined' || value === '' ? '-' : String(value)).html(); }
            function orderMoney(value) { return '$' + Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function orderPair(label, value) { return '<div class="payment-order-fact"><span>' + orderEscape(label) + '</span><strong>' + orderEscape(value) + '</strong></div>'; }
            function renderPaymentOrderDetail(payload) {
                var payment = payload.payment || {};
                var order = payload.order || null;
                var summary = payload.summary || {};
                var fields = paymentOrderText.fields || {};
                var items = order && order.items ? order.items : [];
                var products = items.length ? items.map(function (item) { return item.name + (item.sku ? ' / ' + item.sku : '') + ' × ' + item.quantity; }).join('、') : paymentOrderText.unmatched;
                var disputes = payload.disputes || [];
                var refunds = payload.refunds || [];
                var firstDispute = disputes[0] || {};
                var left = '<article class="payment-panel payment-order-detail-card"><h2>' + orderEscape(paymentOrderText.payment_product_title) + '</h2>' + orderPair(fields.payment, payment.transaction_id) + orderPair(fields.amount_time, payment.currency_code + ' ' + Number(payment.original_amount || 0).toFixed(2) + ' / ' + payment.paid_at) + orderPair(fields.account_channel, payment.account_name + ' / ' + ((paymentOrderText.channels || {})[payment.channel] || payment.channel)) + orderPair(fields.store_order, order ? ((order.store_name || '-') + ' / ' + (order.shopify_order_number || order.tongtu_order_number || '-')) : paymentOrderText.unmatched) + orderPair(fields.products, products) + orderPair(fields.deduction_original, order ? (((paymentOrderText.deduction_types || {})[order.deduction_type] || order.deduction_type) + ' / ' + (order.subscription_origin_order_id || '-')) : paymentOrderText.unmatched) + '</article>';
                var right = '<article class="payment-panel payment-order-detail-card"><h2>' + orderEscape(paymentOrderText.case_money_title) + '</h2>' + orderPair(fields.related_case, firstDispute.id || paymentOrderText.no_case) + orderPair(fields.risk_amount, orderMoney(summary.dispute_amount)) + orderPair(fields.refunded, orderMoney(summary.refund_amount)) + orderPair(fields.case_status, firstDispute.status || paymentOrderText.no_case) + orderPair(fields.case_outcome, firstDispute.outcome || paymentOrderText.no_case) + orderPair(fields.fulfillment, order ? ((order.logistics_status || '-') + ' / ' + (order.tracking_number || '-')) : paymentOrderText.unmatched) + '</article>';
                var events = [{ type: 'payment', title: paymentOrderText.event_payment, id: payment.transaction_id, amount: payment.amount_usd, status: payment.status, time: payment.paid_at }];
                refunds.forEach(function (refund) { events.push({ type: 'refund', title: paymentOrderText.event_refund, id: refund.id, amount: refund.amount, status: refund.status, reason: refund.reason, time: refund.happened_at }); });
                disputes.forEach(function (dispute) { events.push({ type: 'dispute', title: paymentOrderText.event_dispute, id: dispute.id, amount: dispute.amount, status: dispute.status, reason: dispute.reason, time: dispute.happened_at || dispute.deadline }); });
                var timeline = '<article class="payment-panel payment-order-event-panel"><h2>' + orderEscape(paymentOrderText.events_title) + '</h2><div class="payment-order-timeline">' + events.map(function (event) { return '<section class="is-' + event.type + '"><i class="fa ' + (event.type === 'payment' ? 'fa-check' : (event.type === 'refund' ? 'fa-reply' : 'fa-gavel')) + '"></i><div><strong>' + orderEscape(event.title) + ' · ' + orderEscape(event.id) + '</strong><p>' + orderEscape(orderMoney(event.amount) + ' · ' + (event.status || '-')) + '</p>' + (event.reason ? '<small>' + orderEscape(event.reason) + '</small>' : '') + '</div><time>' + orderEscape(event.time) + '</time></section>'; }).join('') + '</div><p class="payment-order-loss-note">' + orderEscape(paymentOrderText.loss_note) + '</p></article>';
                var actions = '<article class="payment-panel payment-order-detail-actions"><h2>' + orderEscape(paymentOrderText.actions_title) + '</h2><a class="btn btn-primary" href="' + orderEscape(config.paymentAlertsUrl) + '">' + orderEscape(paymentOrderText.view_alerts) + '</a><a class="btn btn-default" href="' + orderEscape(config.paymentProductRiskUrl) + '">' + orderEscape(paymentOrderText.view_product) + '</a>' + (order ? '<a class="btn btn-default" href="' + orderEscape(order.detail_url) + '">' + orderEscape(paymentOrderText.view_order) + '</a>' : '<button class="btn btn-default" type="button" disabled>' + orderEscape(paymentOrderText.unmatched_order) + '</button>') + '<p>' + orderEscape(paymentOrderText.actions_note) + '</p></article>';
                return '<div class="payment-modal-summary-grid">' + left + right + '</div><div class="payment-modal-summary-grid payment-modal-bottom-grid">' + timeline + actions + '</div>';
            }
            function openOrder(recordKey) {
                paymentOrderModal.modal('show');
                paymentOrderModal.find('[data-payment-order-loading]').prop('hidden', false);
                paymentOrderModal.find('[data-payment-order-error]').prop('hidden', true);
                paymentOrderModal.find('[data-payment-order-detail-body]').empty();
                paymentOrderModal.find('[data-payment-order-subtitle]').text(recordKey);
                if (paymentOrderRequest) paymentOrderRequest.abort();
                paymentOrderRequest = $.getJSON(config.paymentOrderDetailUrl, { record_key: recordKey }).done(function (payload) {
                    paymentOrderModal.find('[data-payment-order-subtitle]').text((payload.payment || {}).transaction_id || recordKey);
                    paymentOrderModal.find('[data-payment-order-detail-body]').html(renderPaymentOrderDetail(payload));
                }).fail(function (xhr, status) {
                    if (status !== 'abort') paymentOrderModal.find('[data-payment-order-error]').text(paymentOrderText.load_failed || '').prop('hidden', false);
                }).always(function () { paymentOrderModal.find('[data-payment-order-loading]').prop('hidden', true); paymentOrderRequest = null; });
            }
            page.on('click', '[data-payment-order-open]', function (event) { event.preventDefault(); event.stopPropagation(); openOrder(String($(this).data('paymentOrderOpen'))); });
            page.on('keydown', '.payment-order-row', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openOrder(String($(this).data('paymentOrderOpen'))); } });
            paymentOrderModal.on('hidden.bs.modal', function () { if (paymentOrderRequest) paymentOrderRequest.abort(); });
        }

        page.on('click', '[data-payment-order-sort]', function () {
            var form = page.find('[data-payment-order-filter]');
            var field = String($(this).data('paymentOrderSort'));
            var current = String(form.find('[name="sort"]').val() || '');
            var direction = String(form.find('[name="direction"]').val() || 'desc');
            form.find('[name="sort"]').val(field);
            form.find('[name="direction"]').val(current === field && direction === 'desc' ? 'asc' : 'desc');
            form.trigger('submit');
        });

        var detailModal = $('#payment-account-detail-modal');
        if (detailModal.length && config.accountDetailUrl) {
            var detailText = config.accountDetail || {};
            var detailState = { accountId: '', accountName: '', tab: 'overview', page: 1, perPage: 50, request: null, disputeRows: [] };
            var paginationText = config.pagination || {};
            var detailColumns = {
                overview: ['happened_at', 'type', 'order_number', 'store_name', 'amount', 'reason', 'status'],
                orders: ['paid_at', 'payment_transaction_id', 'order_number', 'store_name', 'products', 'paid_amount', 'refund_status', 'dispute_status', 'match_status'],
                products: ['product_name', 'sku', 'quantity', 'paid_amount', 'refund_orders', 'refund_rate', 'dispute_orders', 'dispute_rate'],
                refunds: ['happened_at', 'refund_id', 'order_number', 'store_name', 'amount', 'source_reason', 'status'],
                disputes: ['happened_at', 'dispute_id', 'order_number', 'store_name', 'amount', 'reason', 'stage', 'status', 'outcome']
            };

            function escapeHtml(value) {
                return $('<div>').text(value === null || typeof value === 'undefined' || value === '' ? '-' : String(value)).html();
            }

            function displayValue(value) {
                if (Array.isArray(value)) {
                    return value.map(function (item) {
                        return typeof item === 'object' && item !== null ? (item.name || item.product_name || JSON.stringify(item)) : item;
                    }).join('、');
                }
                if (typeof value === 'object' && value !== null) {
                    return Object.keys(value).map(function (key) { return value[key]; }).join(' / ');
                }
                return value;
            }

            function formatNumber(value, decimals) {
                var number = Number(value || 0);
                return number.toLocaleString('zh-CN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
            }

            function renderSummary(summary) {
                var labels = detailText.summary || {};
                var items = [
                    ['orders', formatNumber(summary.order_count, 0)],
                    ['paid_amount', '$' + formatNumber(summary.paid_amount, 2)],
                    ['refund_rate', formatNumber(summary.refund_rate, 2) + '%'],
                    ['refund_amount_rate', formatNumber(summary.refund_amount_rate, 2) + '%'],
                    ['dispute_rate', formatNumber(summary.dispute_rate, 2) + '%'],
                    ['unmatched_count', formatNumber(summary.unmatched_count, 0)]
                ];
                detailModal.find('[data-payment-detail-summary]').html(items.map(function (item) {
                    return '<div><span>' + escapeHtml(labels[item[0]] || item[0]) + '</span><strong>' + escapeHtml(item[1]) + '</strong></div>';
                }).join(''));
            }

            function replaceTokens(template, values) {
                var result = String(template || '');
                Object.keys(values).forEach(function (key) {
                    result = result.replace(new RegExp(':' + key, 'g'), values[key]);
                });
                return result;
            }

            function overviewKpis(summary, exceptions) {
                var text = detailText.overview || {};
                var kpis = [
                    ['disputes_recent', formatNumber(summary.dispute_order_count, 0), text.disputes_recent_note],
                    ['disputes_period', formatNumber(summary.overall_dispute_order_count, 0) + ' / ' + formatNumber(summary.overall_dispute_rate, 2) + '%', text.disputes_period_note],
                    ['refunds', '$' + formatNumber(summary.refund_amount, 2) + ' / ' + formatNumber(summary.refund_amount_rate, 2) + '%', text.refunds_note],
                    ['urgent', formatNumber(exceptions.length, 0), text.urgent_note]
                ];
                return '<div class="payment-detail-kpis">' + kpis.map(function (item, index) {
                    return '<article class="payment-detail-kpi payment-detail-kpi-' + index + '"><span>' + escapeHtml((text.kpis || {})[item[0]]) + '</span><strong>' + escapeHtml(item[1]) + '</strong><small>' + escapeHtml(item[2]) + '</small></article>';
                }).join('') + '</div>';
            }

            function overviewFunds(summary) {
                var text = (detailText.overview || {}).funds || {};
                var rows = [
                    [text.receipts, '$' + formatNumber(summary.paid_amount, 2)],
                    [text.refunds, '$' + formatNumber(summary.refund_amount, 2)],
                    [text.disputes, '$' + formatNumber(summary.dispute_amount, 2)],
                    [text.available, text.pending],
                    [text.reserve, text.pending]
                ];
                return '<article class="payment-detail-panel"><h5>' + escapeHtml(text.title) + '</h5><div class="payment-detail-fund-list">' + rows.map(function (row) {
                    return '<div><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
                }).join('') + '</div><p class="payment-detail-panel-note"><i class="fa fa-info-circle" aria-hidden="true"></i> ' + escapeHtml(text.note) + '</p></article>';
            }

            function overviewRisk(summary, exceptions) {
                var text = (detailText.overview || {}).risk || {};
                var refunds = exceptions.filter(function (row) { return String(row.type || '').indexOf(String(text.refund_keyword || '')) !== -1; }).length;
                var disputes = Math.max(Number(summary.dispute_order_count || 0), exceptions.length - refunds);
                var unmatched = Number(summary.unmatched_count || 0);
                var values = [
                    [text.disputes, disputes, 'danger'],
                    [text.refunds, refunds, 'warning'],
                    [text.unmatched, unmatched, 'primary']
                ];
                var max = Math.max(1, disputes, refunds, unmatched);
                return '<article class="payment-detail-panel"><h5>' + escapeHtml(text.title) + '</h5><div class="payment-detail-risk-list">' + values.map(function (row) {
                    return '<div><div><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(formatNumber(row[1], 0)) + '</strong></div><div class="payment-detail-risk-bar"><i class="' + row[2] + '" style="width:' + Math.max(row[1] ? 3 : 0, Math.round(row[1] / max * 100)) + '%"></i></div></div>';
                }).join('') + '</div></article>';
            }

            function trendChart(trend) {
                var text = (detailText.overview || {}).trend || {};
                if (!trend.length) {
                    return '<article class="payment-detail-panel payment-detail-trend"><h5>' + escapeHtml(text.title) + '</h5><div class="payment-detail-empty">' + escapeHtml(detailText.empty) + '</div></article>';
                }
                var width = 640;
                var height = 150;
                var max = Math.max.apply(null, trend.map(function (item) { return Math.max(Number(item.orders || 0), Number(item.refunds || 0), Number(item.disputes || 0)); }).concat([1]));
                function points(key) {
                    return trend.map(function (item, index) {
                        var x = trend.length === 1 ? width / 2 : index * width / (trend.length - 1);
                        var y = height - Number(item[key] || 0) / max * (height - 18) - 8;
                        return x.toFixed(1) + ',' + y.toFixed(1);
                    }).join(' ');
                }
                var labels = trend.filter(function (item, index) { return index === 0 || index === trend.length - 1 || index % Math.max(1, Math.ceil(trend.length / 6)) === 0; }).map(function (item) { return '<span>' + escapeHtml(item.label) + '</span>'; }).join('');
                return '<article class="payment-detail-panel payment-detail-trend"><div class="payment-detail-panel-heading"><div><h5>' + escapeHtml(text.title) + '</h5><small>' + escapeHtml(text.note) + '</small></div><div class="payment-detail-legend"><span class="orders">' + escapeHtml(text.orders) + '</span><span class="refunds">' + escapeHtml(text.refunds) + '</span><span class="disputes">' + escapeHtml(text.disputes) + '</span></div></div><div class="payment-detail-chart"><svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" aria-hidden="true"><line x1="0" y1="35" x2="640" y2="35"></line><line x1="0" y1="80" x2="640" y2="80"></line><line x1="0" y1="125" x2="640" y2="125"></line><polyline class="orders" points="' + points('orders') + '"></polyline><polyline class="refunds" points="' + points('refunds') + '"></polyline><polyline class="disputes" points="' + points('disputes') + '"></polyline></svg><div class="payment-detail-chart-labels">' + labels + '</div></div></article>';
            }

            function overviewTasks(exceptions) {
                var text = (detailText.overview || {}).tasks || {};
                var rows = exceptions.slice(0, 2);
                return '<article class="payment-detail-panel payment-detail-tasks"><h5>' + escapeHtml(text.title) + '</h5>' + (rows.length ? rows.map(function (row, index) {
                    return '<div class="payment-detail-task task-' + index + '"><strong>' + escapeHtml(row.type || text.exception) + '</strong><span>' + escapeHtml([row.order_number, row.status].filter(Boolean).join(' · ')) + '</span></div>';
                }).join('') : '<div class="payment-detail-task task-empty"><strong>' + escapeHtml(text.no_tasks) + '</strong><span>' + escapeHtml(text.no_tasks_note) + '</span></div>') + '</article>';
            }

            function renderOverview(payload) {
                var summary = payload.summary || {};
                var exceptions = payload.exceptions || [];
                return overviewKpis(summary, exceptions) + '<div class="payment-detail-overview-two">' + overviewFunds(summary) + overviewRisk(summary, exceptions) + '</div><div class="payment-detail-overview-bottom">' + trendChart(payload.trend || []) + overviewTasks(exceptions) + '</div>';
            }

            function renderFunding(summary) {
                var text = detailText.funding || {};
                var balance = summary.official_balance || null;
                var rows = [
                    [text.receipts, '$' + formatNumber(summary.paid_amount, 2), text.receipts_note],
                    [text.refunds, '$' + formatNumber(summary.refund_amount, 2), text.refunds_note],
                    [text.disputes, '$' + formatNumber(summary.dispute_amount, 2), text.disputes_note],
                    [text.available_balance, balance ? '$' + formatNumber(balance.available, 2) : text.pending, balance ? text.balance_time.replace(':time', balance.as_of_time || '-') : text.balance_pending],
                    [text.withheld_balance, balance ? '$' + formatNumber(balance.withheld, 2) : text.pending, balance ? text.balance_time.replace(':time', balance.as_of_time || '-') : text.balance_pending],
                    [text.reconciliation, text.pending, text.reconciliation_note]
                ];
                return '<div class="payment-funding-intro"><h5>' + escapeHtml(text.title) + '</h5><p>' + escapeHtml(text.note) + '</p></div><div class="payment-funding-grid">' + rows.map(function (row) {
                    return '<article><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong><small>' + escapeHtml(row[2]) + '</small></article>';
                }).join('') + '</div>';
            }

            function renderTable(rows, tab) {
                var columns = detailColumns[tab] || [];
                var labels = detailText.columns || {};
                if (!rows.length) {
                    return '<div class="payment-detail-empty">' + escapeHtml(detailText.empty) + '</div>';
                }
                return '<div class="table-responsive"><table class="table payment-detail-table"><thead><tr>' + columns.map(function (column) {
                    return '<th>' + escapeHtml(labels[column] || column) + '</th>';
                }).join('') + '</tr></thead><tbody>' + rows.map(function (row) {
                    return '<tr>' + columns.map(function (column) {
                        var value = displayValue(row[column]);
                        if ((column === 'amount' || column === 'paid_amount') && value !== '-' && value !== '') {
                            value = (row.currency ? row.currency + ' ' : '$') + formatNumber(value, 2);
                        }
                        if ((column === 'refund_rate' || column === 'dispute_rate') && value !== '-' && value !== '') {
                            value = formatNumber(value, 2) + '%';
                        }
                        return '<td>' + escapeHtml(value) + '</td>';
                    }).join('') + '</tr>';
                }).join('') + '</tbody></table></div>';
            }

            function disputePair(primary, secondary) {
                return '<strong>' + escapeHtml(primary) + '</strong><small>' + escapeHtml(secondary) + '</small>';
            }

            function disputeRefunds(row) {
                var refunds = row.linked_refunds || [];
                if (!refunds.length) {
                    return '-';
                }
                return refunds.map(function (refund) {
                    return String(refund.refund_id || '-') + ' / ' + (row.currency || '') + ' ' + formatNumber(refund.amount, 2);
                }).join('、');
            }

            function renderDisputeSelection(row) {
                var text = detailText.dispute_panel || {};
                if (!row) {
                    return '<div class="payment-detail-empty">' + escapeHtml(detailText.empty) + '</div>';
                }
                var fields = [
                    [text.amount, (row.currency || '') + ' ' + formatNumber(row.amount, 2)],
                    [text.refund_links, disputeRefunds(row)],
                    [text.order, row.order_number],
                    [text.response_due, row.seller_response_due_at],
                    [text.reason_stage, [row.reason, row.stage].filter(Boolean).join(' / ')],
                    [text.outcome, row.outcome || row.status]
                ];
                var steps = text.steps || [];
                return '<div class="payment-dispute-bottom"><article class="payment-dispute-expanded"><h5>' + escapeHtml(replaceTokens(text.expanded_title, { id: row.dispute_id })) + '</h5><p>' + escapeHtml(replaceTokens(text.source_line, { payment: row.payment_transaction_id, order: row.order_number, dispute: row.dispute_id })) + '</p><div class="payment-dispute-fields">' + fields.map(function (field) {
                    return '<div><span>' + escapeHtml(field[0]) + '</span><strong>' + escapeHtml(field[1]) + '</strong></div>';
                }).join('') + '</div></article><article class="payment-dispute-workflow"><h5>' + escapeHtml(text.workflow_title) + '</h5>' + steps.map(function (step, index) {
                    return '<div><b>' + (index + 1) + '</b><p><strong>' + escapeHtml(step.title) + '</strong><span>' + escapeHtml(step.note) + '</span></p></div>';
                }).join('') + '</article></div>';
            }

            function renderDisputes(rows, meta, selectedIndex) {
                var text = detailText.dispute_panel || {};
                if (!rows.length) {
                    return '<div class="payment-detail-empty">' + escapeHtml(detailText.empty) + '</div>';
                }
                var headers = text.headers || [];
                return '<section class="payment-dispute-list"><div class="payment-dispute-list-heading"><h5>' + escapeHtml(text.list_title) + '</h5><span>' + escapeHtml(replaceTokens(text.record_count, { count: meta ? meta.total : rows.length })) + '</span></div><div class="table-responsive"><table class="table payment-dispute-table"><thead><tr>' + headers.map(function (header) { return '<th>' + escapeHtml(header) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (row, index) {
                    var refundText = disputeRefunds(row);
                    return '<tr tabindex="0" class="' + (index === selectedIndex ? 'selected' : '') + '" data-payment-dispute-row="' + index + '"><td>' + disputePair(row.dispute_id, row.payment_transaction_id) + '</td><td>' + disputePair(row.order_number, row.store_name) + '</td><td>' + disputePair(row.reason, row.stage) + '</td><td>' + escapeHtml((row.currency || '') + ' ' + formatNumber(row.amount, 2)) + '</td><td>' + disputePair(row.status, row.outcome) + '</td><td>' + escapeHtml(row.seller_response_due_at) + '</td><td>' + escapeHtml(refundText) + '</td><td>' + escapeHtml(text.owner_pending) + '</td><td>' + escapeHtml(text.next_step_pending) + '</td></tr>';
                }).join('') + '</tbody></table></div></section><div data-payment-dispute-selection>' + renderDisputeSelection(rows[selectedIndex] || rows[0]) + '</div>';
            }

            function renderPagination(meta) {
                var target = detailModal.find('[data-payment-detail-pagination]');
                if (!meta) {
                    target.empty();
                    return;
                }
                var status = String(detailText.page_status || '')
                    .replace(':current', meta.current_page).replace(':last', meta.last_page).replace(':total', meta.total);
                target.html('<label><span>' + escapeHtml(paginationText.per_page) + '</span><select class="form-control input-sm" data-payment-detail-per-page><option value="50" ' + (detailState.perPage === 50 ? 'selected' : '') + '>50</option><option value="100" ' + (detailState.perPage === 100 ? 'selected' : '') + '>100</option></select></label><button type="button" class="btn btn-default btn-sm" data-payment-detail-page="' + (meta.current_page - 1) + '" ' + (meta.current_page <= 1 ? 'disabled' : '') + '>' + escapeHtml(detailText.previous) + '</button><span>' + escapeHtml(status) + '</span><button type="button" class="btn btn-default btn-sm" data-payment-detail-page="' + (meta.current_page + 1) + '" ' + (meta.current_page >= meta.last_page ? 'disabled' : '') + '>' + escapeHtml(detailText.next) + '</button><label><span>' + escapeHtml(paginationText.jump_to) + '</span><input class="form-control input-sm payment-page-jump-input" type="number" min="1" max="' + meta.last_page + '" placeholder="' + escapeHtml(paginationText.page_placeholder) + '" autocomplete="off" data-payment-detail-jump-input></label><button type="button" class="btn btn-primary btn-sm" data-payment-detail-jump>' + escapeHtml(paginationText.jump) + '</button>');
            }

            function loadDetail() {
                var requestTab = detailState.tab === 'funding' ? 'overview' : detailState.tab;
                var query = {
                    tab: requestTab,
                    page: detailState.page,
                    per_page: detailState.perPage,
                    period_start: page.find('[name="period_start"]').val(),
                    period_end: page.find('[name="period_end"]').val(),
                    keyword: detailModal.find('[name="keyword"]').val()
                };
                var url = String(config.accountDetailUrl).replace('__ACCOUNT__', encodeURIComponent(detailState.accountId));
                detailModal.find('[data-payment-detail-loading]').prop('hidden', false);
                detailModal.find('[data-payment-detail-error]').prop('hidden', true);
                detailModal.find('[data-payment-detail-body], [data-payment-detail-pagination]').empty();
                if (detailState.request) {
                    detailState.request.abort();
                }
                detailState.request = $.getJSON(url, query).done(function (payload) {
                    var isDashboard = detailState.tab === 'overview' || detailState.tab === 'funding';
                    var rows = isDashboard ? [] : ((payload.rows || {}).data || []);
                    detailState.disputeRows = detailState.tab === 'disputes' ? rows : [];
                    detailModal.find('[data-payment-detail-summary]').prop('hidden', isDashboard);
                    renderSummary(payload.summary || {});
                    detailModal.find('[data-payment-detail-body]').toggleClass('payment-detail-dashboard-body', isDashboard).toggleClass('payment-detail-dispute-body', detailState.tab === 'disputes').html(
                        detailState.tab === 'overview' ? renderOverview(payload) : (detailState.tab === 'funding' ? renderFunding(payload.summary || {}) : (detailState.tab === 'disputes' ? renderDisputes(rows, (payload.rows || {}).meta, 0) : renderTable(rows, detailState.tab)))
                    );
                    renderPagination(isDashboard ? null : (payload.rows || {}).meta);
                }).fail(function (xhr, status) {
                    if (status !== 'abort') {
                        detailModal.find('[data-payment-detail-error]').text(detailText.load_failed || '').prop('hidden', false);
                    }
                }).always(function () {
                    detailModal.find('[data-payment-detail-loading]').prop('hidden', true);
                    detailState.request = null;
                });
            }

            page.on('click', '[data-payment-account-detail]', function () {
                detailState.accountId = String($(this).data('accountId'));
                detailState.accountName = String($(this).data('accountName'));
                detailState.tab = 'overview';
                detailState.page = 1;
                detailModal.find('[data-payment-detail-tab]').removeClass('active').attr('aria-selected', 'false').filter('[data-payment-detail-tab="overview"]').addClass('active').attr('aria-selected', 'true');
                detailModal.find('[data-payment-detail-filter]').prop('hidden', true).get(0).reset();
                detailModal.find('[data-payment-detail-subtitle]').text(String(detailText.subtitle || '').replace(':account', detailState.accountName));
                detailModal.modal('show');
                loadDetail();
            });
            detailModal.on('click', '[data-payment-detail-tab]', function () {
                detailState.tab = String($(this).data('paymentDetailTab'));
                detailState.page = 1;
                detailModal.find('[data-payment-detail-tab]').removeClass('active').attr('aria-selected', 'false');
                $(this).addClass('active').attr('aria-selected', 'true');
                detailModal.find('[data-payment-detail-filter]').prop('hidden', detailState.tab === 'overview' || detailState.tab === 'funding');
                loadDetail();
            });
            detailModal.on('submit', '[data-payment-detail-filter]', function (event) {
                event.preventDefault();
                detailState.page = 1;
                loadDetail();
            });
            detailModal.on('reset', '[data-payment-detail-filter]', function () {
                window.setTimeout(function () { detailState.page = 1; loadDetail(); }, 0);
            });
            detailModal.on('click', '[data-payment-detail-page]', function () {
                detailState.page = Number($(this).data('paymentDetailPage')) || 1;
                loadDetail();
            });
            detailModal.on('change', '[data-payment-detail-per-page]', function () { detailState.perPage = Number($(this).val()) === 100 ? 100 : 50; detailState.page = 1; loadDetail(); });
            detailModal.on('click', '[data-payment-detail-jump]', function () { var input = detailModal.find('[data-payment-detail-jump-input]'); if ($.trim(input.val()) === '') return; var max = Number(input.attr('max')) || 1; detailState.page = Math.max(1, Math.min(max, Number(input.val()) || 1)); loadDetail(); });
            detailModal.on('click keydown', '[data-payment-dispute-row]', function (event) {
                if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                var index = Number($(this).data('paymentDisputeRow')) || 0;
                detailModal.find('[data-payment-dispute-row]').removeClass('selected');
                $(this).addClass('selected');
                detailModal.find('[data-payment-dispute-selection]').html(renderDisputeSelection(detailState.disputeRows[index]));
            });
            detailModal.on('hidden.bs.modal', function () {
                if (detailState.request) {
                    detailState.request.abort();
                }
            });
        }

        var productModal = $('#payment-product-detail-modal');
        page.on('click', '[data-payment-product-sort]', function () {
            var form = page.find('[data-payment-product-filter]');
            var field = String($(this).data('paymentProductSort'));
            var current = String(form.find('[name="sort"]').val() || '');
            var direction = String(form.find('[name="direction"]').val() || '');
            form.find('[name="sort"]').val(field);
            form.find('[name="direction"]').val(current === field && direction === 'desc' ? 'asc' : 'desc');
            form.trigger('submit');
        });
        if (productModal.length && config.productDetailUrl) {
            var productText = config.productDetail || {};
            var productState = { id: '', name: '', tab: 'overview', page: 1, perPage: 50, request: null, refundRows: [] };
            var productPaginationText = config.pagination || {};
            var productColumns = {
                paypal_orders: ['shopify_order_number', 'store_name', 'bill_number', 'quantity', 'revenue', 'paypal_refund', 'paypal_dispute_count', 'logistics_status'],
                credit_card_orders: ['shopify_order_number', 'store_name', 'bill_number', 'quantity', 'revenue', 'credit_card_refund', 'logistics_status'],
                orders: ['shopify_order_number', 'store_name', 'bill_number', 'payment_method_label', 'quantity', 'revenue', 'actual_refund', 'logistics_status'],
                refunds: ['payment_method_label', 'shopify_order_number', 'refund_number', 'amount', 'source_reason', 'manual_reason', 'status', 'imported_at'],
                disputes: ['dispute_id', 'shopify_order_number', 'amount', 'reason', 'stage', 'status', 'outcome', 'seller_response_due_at', 'updated_at']
            };

            function pEscape(value) {
                return $('<div>').text(value === null || typeof value === 'undefined' || value === '' ? '-' : String(value)).html();
            }
            function pNumber(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 2 }); }
            function pMoney(value) { return '$' + Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function pRate(value) { return Number(value || 0).toFixed(2) + '%'; }

            function renderProductOverview(product) {
                var modules = product.overview_modules || {};
                var labels = productText.overview || {};
                return '<div class="payment-product-detail-identity">' + (product.image_url ? '<img src="' + pEscape(product.image_url) + '" alt="">' : '<span><i class="fa fa-cube"></i></span>') + '<div><h3>' + pEscape(product.name) + '</h3><p>' + pEscape(product.product_code || product.sku) + '</p><small>' + pEscape((product.stores || []).join('、')) + '</small></div></div><div class="payment-product-module-grid">' + ['overall', 'paypal', 'credit_card'].map(function (key) {
                    var module = modules[key] || {};
                    var values = [
                        [labels.sales, pNumber(module.unit_count)], [labels.orders, pNumber(module.order_count)],
                        [labels.receipts, pMoney(module.received_amount)], [labels.refunds, pMoney(module.refund_amount) + ' / ' + pRate(module.refund_rate)],
                        [labels.shipping, pRate(module.shipping_rate)], [labels.delivery, pRate(module.delivery_rate)]
                    ];
                    if (key === 'paypal') values.push([labels.disputes, pNumber(module.dispute_count) + ' / ' + pRate(module.dispute_rate)]);
                    var reasons = module.dispute_reason_breakdown || (module.alert_record_summary || {}).reason_breakdown || [];
                    return '<article class="payment-product-channel-panel payment-product-channel-' + key + '"><h4>' + pEscape(labels[key]) + '</h4><dl>' + values.map(function (value) { return '<div><dt>' + pEscape(value[0]) + '</dt><dd>' + pEscape(value[1]) + '</dd></div>'; }).join('') + '</dl><div class="payment-product-risk-summary"><strong>' + pEscape(labels.reason_summary) + '</strong>' + (reasons.length ? reasons.slice(0, 4).map(function (reason) { return '<p><span>' + pEscape(reason.reason || reason.name) + '</span><b>' + pEscape(pNumber(reason.count || reason.record_count)) + '</b></p>'; }).join('') : '<small>' + pEscape(labels.no_risk_record) + '</small>') + '</div></article>';
                }).join('') + '</div><div class="payment-product-store-distribution"><h4>' + pEscape(labels.store_distribution) + '</h4>' + (product.store_order_distribution || []).map(function (store) { return '<div><span>' + pEscape(store.name) + '</span><strong>' + pEscape(pNumber(store.order_count)) + '</strong></div>'; }).join('') + '</div>';
            }

            function renderFirstRenewal(detail) {
                var text = productText.first_renewal || {};
                var groups = detail.groups || {};
                var order = ['one_time', 'first_charge', 'first_renewal', 'later_renewal'];
                var cards = order.map(function (key) {
                    var group = groups[key] || {};
                    return '<article class="payment-lifecycle-card"><h4>' + pEscape((text.groups || {})[key]) + '</h4><strong>' + pEscape(pNumber(group.order_count)) + ' ' + pEscape((productText.overview || {}).record) + ' / ' + pEscape(pMoney(group.received_amount)) + '</strong><dl><div><dt>' + pEscape(text.refund_rate) + '</dt><dd>' + pEscape(pRate(group.refund_rate)) + '</dd></div><div><dt>' + pEscape(text.dispute_rate) + '</dt><dd>' + pEscape(pNumber(group.dispute_count)) + ' / ' + pEscape(pRate(group.dispute_rate)) + '</dd></div><div><dt>' + pEscape(text.realised_loss) + '</dt><dd>' + pEscape(pMoney(group.realised_loss)) + '</dd></div><div><dt>' + pEscape(text.expected_loss) + '</dt><dd class="is-muted">' + pEscape(text.unavailable) + '</dd></div><div><dt>' + pEscape(text.contribution) + '</dt><dd class="is-muted">' + pEscape(text.unavailable) + '</dd></div></dl></article>';
                }).join('');
                var metrics = detail.metrics || {};
                var metricKeys = ['renewal_success_rate', 'first_renewal_retention', 'later_renewal_rate', 'link_coverage_rate'];
                var metricPanel = '<article class="payment-lifecycle-panel"><h4>' + pEscape(text.metrics_title) + '</h4><div class="payment-lifecycle-metrics">' + metricKeys.map(function (key) { return '<div><span>' + pEscape(text[key]) + '</span><strong>' + pEscape(pRate(metrics[key])) + '</strong></div>'; }).join('') + '</div></article>';
                var chains = detail.chains || [];
                var chainPanel = '<article class="payment-lifecycle-panel"><h4>' + pEscape(text.chain_title) + '</h4>' + (chains.length ? '<div class="payment-lifecycle-chains">' + chains.slice(0, 8).map(function (chain) { return '<div><b>' + pEscape(text.origin) + ' ' + pEscape(chain.origin) + '</b><span>' + (chain.orders || []).map(function (item) { return pEscape(((text.groups || {})[item.stage] || item.stage) + ' ' + item.order_number + ' · ' + pMoney(item.amount) + (Number(item.refund_amount) > 0 ? ' · ' + text.refund + ' ' + pMoney(item.refund_amount) : '')); }).join('<i class="fa fa-long-arrow-right"></i>') + '</span></div>'; }).join('') + '</div>' : '<p class="payment-detail-empty">' + pEscape(text.chain_empty) + '</p>') + '</article>';
                return '<div class="payment-lifecycle-grid">' + cards + '</div><div class="payment-lifecycle-bottom">' + metricPanel + chainPanel + '</div>';
            }

            function renderRefundSelection(row) {
                var text = productText.refund_events || {};
                var linkedDisputes = (row.linked_disputes || []).join('、') || '-';
                var chain = '<article class="payment-refund-chain"><h4>' + pEscape(text.event_chain) + ' · ' + pEscape(row.shopify_order_number) + '</h4><p class="payment-event-path">' + pEscape(row.bill_number) + '<i class="fa fa-long-arrow-right"></i>' + pEscape(row.refund_number) + '<i class="fa fa-long-arrow-right"></i>' + pEscape(row.status) + '</p><div><span>' + pEscape(text.event_count) + '<strong>1</strong></span><span>' + pEscape(text.issue_payment) + '<strong>' + pEscape(row.shopify_order_number) + '</strong></span><span>' + pEscape(text.refunded_principal) + '<strong>' + pEscape(pMoney(row.amount)) + '</strong></span><span>' + pEscape(text.next_action) + '<strong>' + pEscape(text.pending_action) + '</strong></span></div></article>';
                var reasonRows = [[text.source_reason, row.source_reason], [text.manual_reason, row.manual_reason], [text.dispute, linkedDisputes]];
                var evidence = '<article class="payment-refund-distribution"><h4>' + pEscape(text.reason_title) + '</h4>' + reasonRows.map(function (item, index) { var hasValue = item[1] && item[1] !== '-'; return '<div><p><span>' + pEscape(item[0]) + '</span><strong>' + pEscape(item[1] || '-') + '</strong></p><i><b class="tone-' + index + '" style="width:' + (hasValue ? '100' : '0') + '%"></b></i></div>'; }).join('') + '<small>' + pEscape(text.current_page_note) + '</small></article>';
                return chain + evidence;
            }

            function renderRefundEvents(detail) {
                var rows = detail.rows || [];
                var text = productText.refund_events || {};
                productState.refundRows = rows;
                if (!rows.length) return '<div class="payment-detail-empty">' + pEscape(productText.empty) + '</div>';
                var table = '<section class="payment-refund-event-list"><h4>' + pEscape(text.title) + '</h4><div class="table-responsive"><table class="table payment-detail-table"><thead><tr>' + ['channel','order','original_payment','event_reference','fund_status','reason','dispute','time','owner'].map(function (key) { return '<th>' + pEscape(text[key]) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (row, index) { return '<tr tabindex="0" role="button" aria-selected="' + (index === 0 ? 'true' : 'false') + '" data-payment-refund-event-row="' + index + '" class="' + (index === 0 ? 'selected' : '') + '"><td>' + pEscape(row.payment_method_label) + '</td><td>' + pEscape(row.shopify_order_number) + '<br>' + pEscape(row.subscription_type) + '</td><td>' + pEscape(row.bill_number) + '<br>' + pEscape(pMoney(row.original_payment_amount)) + '</td><td>' + pEscape(row.refund_number) + '</td><td><b>' + pEscape(pMoney(row.amount)) + '</b><br>' + pEscape(row.status) + '</td><td>' + pEscape(row.source_reason || '-') + '<br>' + pEscape(row.manual_reason || '-') + '</td><td>' + pEscape((row.linked_disputes || []).join('、') || '-') + '</td><td>' + pEscape(row.imported_at) + '</td><td>' + pEscape(text.pending_owner) + '<br>' + pEscape(text.pending_action) + '</td></tr>'; }).join('') + '</tbody></table></div></section>';
                return table + '<div class="payment-refund-event-bottom" data-payment-refund-event-detail>' + renderRefundSelection(rows[0]) + '</div>';
            }

            function productCell(row, key) {
                var value = row[key];
                if (Array.isArray(value)) value = value.map(function (item) { return typeof item === 'object' ? (item.refund_number || item.name || JSON.stringify(item)) : item; }).join('、');
                if (['amount', 'revenue', 'paypal_refund', 'credit_card_refund', 'actual_refund'].indexOf(key) !== -1) return pMoney(value);
                if (key === 'quantity' || key === 'paypal_dispute_count') return pNumber(value);
                return value || '-';
            }

            function renderProductRows(detail, tab) {
                var rows = detail.rows || [];
                var columns = productColumns[tab] || [];
                var labels = productText.columns || {};
                if (!rows.length) return '<div class="payment-detail-empty">' + pEscape(productText.empty) + '</div>';
                return '<div class="table-responsive"><table class="table payment-detail-table"><thead><tr>' + columns.map(function (key) { return '<th>' + pEscape(labels[key] || key) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (row) { return '<tr>' + columns.map(function (key) { return '<td>' + pEscape(productCell(row, key)) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
            }

            function renderProductPagination(meta) {
                var target = productModal.find('[data-payment-product-detail-pagination]');
                if (!meta) { target.empty(); return; }
                target.html('<label><span>' + pEscape(productPaginationText.per_page) + '</span><select class="form-control input-sm" data-payment-product-per-page><option value="50" ' + (productState.perPage === 50 ? 'selected' : '') + '>50</option><option value="100" ' + (productState.perPage === 100 ? 'selected' : '') + '>100</option></select></label><button class="btn btn-default btn-sm" type="button" data-payment-product-page="' + (meta.current_page - 1) + '" ' + (meta.current_page <= 1 ? 'disabled' : '') + '>' + pEscape(productText.previous) + '</button><span>' + pEscape(String(productText.page_status || '').replace(':current', meta.current_page).replace(':last', meta.last_page).replace(':total', meta.total)) + '</span><button class="btn btn-default btn-sm" type="button" data-payment-product-page="' + (meta.current_page + 1) + '" ' + (meta.current_page >= meta.last_page ? 'disabled' : '') + '>' + pEscape(productText.next) + '</button><label><span>' + pEscape(productPaginationText.jump_to) + '</span><input class="form-control input-sm payment-page-jump-input" type="number" min="1" max="' + meta.last_page + '" placeholder="' + pEscape(productPaginationText.page_placeholder) + '" autocomplete="off" data-payment-product-jump-input></label><button class="btn btn-primary btn-sm" type="button" data-payment-product-jump>' + pEscape(productPaginationText.jump) + '</button>');
            }

            function loadProductDetail() {
                var query = {
                    product_id: productState.id, detail_tab: productState.tab, detail_page: productState.page, detail_per_page: productState.perPage,
                    detail_keyword: productModal.find('[name="detail_keyword"]').val(),
                    date_from: page.find('[name="date_from"]').val(), date_to: page.find('[name="date_to"]').val(),
                    store: page.find('[name="store"]').val(), keyword: page.find('[name="keyword"]').val()
                };
                productModal.find('[data-payment-product-loading]').prop('hidden', false);
                productModal.find('[data-payment-product-error]').prop('hidden', true);
                productModal.find('[data-payment-product-detail-body], [data-payment-product-detail-pagination]').empty();
                if (productState.request) productState.request.abort();
                productState.request = $.getJSON(config.productDetailUrl, query).done(function (payload) {
                    var dashboard = productState.tab === 'overview' || productState.tab === 'first_renewal';
                    var content = productState.tab === 'overview' ? renderProductOverview(payload.product || {}) : (productState.tab === 'first_renewal' ? renderFirstRenewal(payload.detail || {}) : (productState.tab === 'refunds' ? renderRefundEvents(payload.detail || {}) : renderProductRows(payload.detail || {}, productState.tab)));
                    productModal.find('[data-payment-product-detail-body]').toggleClass('payment-detail-dashboard-body', dashboard || productState.tab === 'refunds').html(content);
                    renderProductPagination(dashboard ? null : (payload.detail || {}).pagination);
                }).fail(function (xhr, status) {
                    if (status !== 'abort') productModal.find('[data-payment-product-error]').text(productText.load_failed || '').prop('hidden', false);
                }).always(function () { productModal.find('[data-payment-product-loading]').prop('hidden', true); productState.request = null; });
            }

            page.on('click', '[data-payment-product-detail]', function () {
                productState.id = String($(this).data('productId')); productState.name = String($(this).data('productName')); productState.tab = 'overview'; productState.page = 1;
                productModal.find('[data-payment-product-detail-tab]').removeClass('active').attr('aria-selected', 'false').first().addClass('active').attr('aria-selected', 'true');
                productModal.find('[data-payment-product-detail-filter]').prop('hidden', true).get(0).reset();
                productModal.find('[data-payment-product-detail-subtitle]').text(productState.name);
                productModal.modal('show'); loadProductDetail();
            });
            productModal.on('click', '[data-payment-product-detail-tab]', function () {
                productState.tab = String($(this).data('paymentProductDetailTab')); productState.page = 1;
                productModal.find('[data-payment-product-detail-tab]').removeClass('active').attr('aria-selected', 'false'); $(this).addClass('active').attr('aria-selected', 'true');
                productModal.find('[data-payment-product-detail-filter]').prop('hidden', productState.tab === 'overview' || productState.tab === 'first_renewal'); loadProductDetail();
            });
            productModal.on('submit', '[data-payment-product-detail-filter]', function (event) { event.preventDefault(); productState.page = 1; loadProductDetail(); });
            productModal.on('reset', '[data-payment-product-detail-filter]', function () { window.setTimeout(function () { productState.page = 1; loadProductDetail(); }, 0); });
            productModal.on('click', '[data-payment-product-page]', function () { productState.page = Number($(this).data('paymentProductPage')) || 1; loadProductDetail(); });
            productModal.on('change', '[data-payment-product-per-page]', function () { productState.perPage = Number($(this).val()) === 100 ? 100 : 50; productState.page = 1; loadProductDetail(); });
            productModal.on('click', '[data-payment-product-jump]', function () { var input = productModal.find('[data-payment-product-jump-input]'); if ($.trim(input.val()) === '') return; var max = Number(input.attr('max')) || 1; productState.page = Math.max(1, Math.min(max, Number(input.val()) || 1)); loadProductDetail(); });
            productModal.on('click keydown', '[data-payment-refund-event-row]', function (event) {
                if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                var index = Number($(this).data('paymentRefundEventRow'));
                var row = productState.refundRows[index];
                if (!row) return;
                productModal.find('[data-payment-refund-event-row]').removeClass('selected').attr('aria-selected', 'false');
                $(this).addClass('selected').attr('aria-selected', 'true');
                productModal.find('[data-payment-refund-event-detail]').html(renderRefundSelection(row));
            });
            productModal.on('hidden.bs.modal', function () { if (productState.request) productState.request.abort(); });
        }
    });
})(jQuery);
