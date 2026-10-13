(function ($) {
    'use strict';

    function updateDomainPreview(form) {
        var codeInput = form.querySelector('[data-portal-code-input]');
        var preview = form.querySelector('[data-portal-domain-preview]');
        var suffix = (form.getAttribute('data-domain-suffix') || '').trim();

        if (!codeInput || !preview) {
            return;
        }

        var code = codeInput.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
        if (code !== codeInput.value) {
            codeInput.value = code;
        }

        preview.value = code && suffix ? code + '.' + suffix : '';
    }

    function bindDomainPreview() {
        $('[data-portal-form]').each(function () {
            var form = this;
            updateDomainPreview(form);
            $(form).off('input.portalDomain', '[data-portal-code-input]')
                .on('input.portalDomain', '[data-portal-code-input]', function () {
                    updateDomainPreview(form);
                });
        });
    }

    function syncDepartmentOption(input) {
        $(input).closest('.portal-department-option').toggleClass('is-selected', Boolean(input.checked));
    }

    function bindDepartmentOptions() {
        $('[data-portal-form]').each(function () {
            var form = this;

            $(form).find('[data-portal-department-input]').each(function () {
                syncDepartmentOption(this);
            });

            $(form).off('change.portalDepartment', '[data-portal-department-input]')
                .on('change.portalDepartment', '[data-portal-department-input]', function () {
                    syncDepartmentOption(this);
                });
        });
    }

    function workspaceCopy() {
        return window.ErpPortalWorkspace || {};
    }

    function interpolate(template, values) {
        return String(template || '').replace(/:([a-z_]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
    }

    function notify(type, title, message) {
        if (!window.ErpAdminNotifier) {
            return;
        }

        window.ErpAdminNotifier.notify({ type: type, title: title, message: message });
    }

    function statusConfirmModal() {
        var modal = $('#portal-status-confirm-modal');
        var copy = workspaceCopy();

        if (modal.length) {
            return modal;
        }

        modal = $('<div id="portal-status-confirm-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">'
            + '<div class="modal-dialog modal-sm" role="document"><div class="modal-content">'
            + '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">'
            + '<span aria-hidden="true">&times;</span></button><h4 class="modal-title"></h4></div>'
            + '<div class="modal-body"><p class="portal-status-confirm-message"></p></div>'
            + '<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal" data-portal-status-cancel></button>'
            + '<button type="button" class="btn btn-primary" data-portal-status-confirm></button></div>'
            + '</div></div></div>');
        modal.find('.close').attr('aria-label', copy.close || '');
        modal.find('[data-portal-status-cancel]').text(copy.cancel || '');
        modal.find('[data-portal-status-confirm]').text(copy.statusChangeConfirm || '');
        $('body').append(modal);

        modal.on('click.portalStatus', '[data-portal-status-confirm]', function () {
            var request = modal.data('portalStatusRequest');
            var confirmButton = $(this);
            var config = workspaceCopy();

            if (!request || confirmButton.prop('disabled')) {
                return;
            }

            confirmButton.prop('disabled', true);
            $.ajax({
                url: request.url,
                method: 'POST',
                dataType: 'json',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content') || '',
                    status: request.status
                }
            }).done(function (response) {
                if (!response || !response.success || !response.portal) {
                    notify('error', config.statusChangeTitle || '', config.statusUpdateFailed || '');
                    return;
                }

                updateStatusSwitcher(request.switcher, response.portal);
                modal.modal('hide');
                notify('success', response.title || '', response.message || '');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : (config.statusUpdateFailed || '');

                notify('error', config.statusChangeTitle || '', message);
            }).always(function () {
                confirmButton.prop('disabled', false);
            });
        });

        return modal;
    }

    function updateStatusSwitcher(switcher, portal) {
        var button = switcher.find('[data-portal-status-toggle]');

        switcher.attr('data-portal-current-status', portal.status);
        button.removeClass('btn-success btn-warning btn-default').addClass(portal.button_class || 'btn-default');
        button.find('[data-portal-status-label]').text(portal.label || '');
        switcher.find('[data-portal-status-choice]').each(function () {
            var choice = $(this);
            var isCurrent = choice.attr('data-portal-status') === portal.status;
            var item = choice.parent('li');
            var icon = choice.find('[data-portal-status-choice-icon]');

            item.toggleClass('disabled', isCurrent);
            choice.attr('aria-disabled', isCurrent ? 'true' : 'false');
            if (isCurrent) {
                choice.attr('tabindex', '-1');
            } else {
                choice.removeAttr('tabindex');
            }
            icon.removeClass('fa-check fa-fw').addClass(isCurrent ? 'fa-check' : 'fa-fw');
        });
    }

    function bindStatusSwitcher() {
        $(document).off('click.portalStatus', '[data-portal-status-choice]')
            .on('click.portalStatus', '[data-portal-status-choice]', function (event) {
                event.preventDefault();

                var choice = $(this);
                var switcher = choice.closest('[data-portal-status-switcher]');
                var config = workspaceCopy();
                var status = choice.attr('data-portal-status');
                var label = choice.attr('data-portal-status-label');

                if (!status || choice.parent('li').hasClass('disabled') || choice.attr('aria-disabled') === 'true') {
                    return;
                }

                switcher.removeClass('open').find('[data-portal-status-toggle]').attr('aria-expanded', 'false');
                var modal = statusConfirmModal();
                modal.data('portalStatusRequest', {
                    url: switcher.attr('data-portal-status-url'),
                    status: status,
                    switcher: switcher
                });
                modal.find('.modal-title').text(config.statusChangeTitle || '');
                modal.find('.portal-status-confirm-message').text(interpolate(config.statusChangeMessage, {
                    name: switcher.attr('data-portal-name') || '',
                    status: label || ''
                }));
                modal.modal('show');
            });
    }

    function historyModal() {
        var modal = $('#portal-history-modal');
        var copy = window.ErpPortalWorkspace || {};

        if (modal.length) {
            return modal;
        }

        modal = $('<div id="portal-history-modal" class="modal fade portal-history-modal" tabindex="-1" role="dialog" aria-hidden="true">'
            + '<div class="modal-dialog modal-lg" role="document"><div class="modal-content">'
            + '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">'
            + '<span aria-hidden="true">&times;</span></button><h4 class="modal-title"></h4></div>'
            + '<div class="modal-body"><div class="text-center" style="padding:24px"><i class="fa fa-spinner fa-spin"></i></div></div>'
            + '</div></div></div>');
        modal.find('.close').attr('aria-label', copy.close || '');
        $('body').append(modal);

        return modal;
    }

    function bindHistoryModal() {
        $(document).off('click.portalHistory', '[data-portal-history-url]')
            .on('click.portalHistory', '[data-portal-history-url]', function () {
                var button = $(this);
                var modal = historyModal();
                var fallback = (window.ErpPortalWorkspace || {}).historyLoadFailed || '';

                modal.find('.modal-title').text('');
                modal.find('.modal-body').html('<div class="text-center" style="padding:24px"><i class="fa fa-spinner fa-spin"></i></div>');
                modal.modal('show');

                $.get(button.data('portal-history-url'))
                    .done(function (response) {
                        modal.find('.modal-title').text(response.title || '');
                        modal.find('.modal-body').html(response.html || '');
                    })
                    .fail(function () {
                        modal.find('.modal-body').html('<p class="text-danger" style="padding:24px"></p>')
                            .find('p').text(fallback);
                    });
            });
    }

    $(function () {
        bindDomainPreview();
        bindDepartmentOptions();
        bindHistoryModal();
        bindStatusSwitcher();
    });

    $(document).on('pjax:end', function () {
        bindDomainPreview();
        bindDepartmentOptions();
        bindHistoryModal();
        bindStatusSwitcher();
    });
})(jQuery);


