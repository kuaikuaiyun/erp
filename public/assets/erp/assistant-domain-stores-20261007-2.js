(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreTranslations || {};

    function translate(key, replacements) {
        var message = translations[key] || key;

        Object.keys(replacements || {}).forEach(function (name) {
            message = message.split('__' + name + '__').join(String(replacements[name]));
        });

        return message;
    }

    window.ErpAssistantDomainStores = { ready: true };

    $(function () {
        window.setTimeout(function () {
            $('.assistant-success-alert').fadeTo(250, 0).slideUp(180, function () {
                $(this).remove();
            });
        }, 4000);

        addFieldActions();
        initializeSelection();
        initializeImportDropzone();

        if ($('.assistant-import-errors').length) {
            $('#assistant-import-modal').modal('show');
        }

        if ($('.assistant-export-errors').length) {
            $('#assistant-export-modal').modal('show');
        }
    });

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function addFieldActions(scope) {
        (scope || $(document)).find('.assistant-field-value, .assistant-note-value').each(function () {
            var field = $(this);

            if (field.find('.assistant-field-content').length) {
                return;
            }

            var content = $('<span class="assistant-field-content"></span>');
            content.append(field.contents());
            field.append(content);

            var text = content.text().trim();

            if (!text || text === '—') {
                return;
            }

            if (field.hasClass('assistant-truncate-value')) {
                content.attr('title', text);
            }

            field.addClass('assistant-copy-trigger').attr({
                'data-copy-value': text,
                title: translate('clickToCopy'),
                tabindex: 0,
                role: 'button',
                'aria-label': translate('clickToCopy')
            });
        });
    }

    function selectedStoreIds() {
        return $('.assistant-row-selector.is-selected').map(function () {
            return $(this).data('store-id');
        }).get();
    }

    function setRowSelection(selector, selected) {
        selector.toggleClass('is-selected', selected)
            .attr('aria-pressed', selected ? 'true' : 'false')
            .text(selected ? translate('selected') : translate('select'));
    }

    function updateSelectionState() {
        var selectors = $('.assistant-row-selector');
        var selected = selectedStoreIds();
        var master = $('.assistant-select-all');
        var selectedScope = $('input[name="scope"][value="selected"]');
        var batchDeleteForm = $('#assistant-batch-delete-form');
        var batchDeleteInputs = $('#assistant-batch-delete-inputs');
        var batchDeleteButton = $('#assistant-batch-delete-button');
        var batchUpdateTrigger = $('#assistant-batch-update-trigger');
        var allSelected = selectors.length > 0 && selected.length === selectors.length;

        master.toggleClass('is-selected', allSelected)
            .attr('aria-pressed', allSelected ? 'true' : 'false')
            .text(allSelected ? translate('unselectPage') : translate('selectPage'));
        $('#assistant-current-selection-count').text(translate('selectionCount', { count: selected.length }));
        $('#assistant-selected-count').text(translate('selectedCount', { count: selected.length }));
        selectedScope.prop('disabled', selected.length === 0);

        batchDeleteInputs.empty();
        selected.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(batchDeleteInputs);
        });
        batchDeleteForm.prop('hidden', selected.length === 0);
        batchDeleteButton.html('<i class="fa fa-trash"></i> ' + translate('batchDeleteCount', { count: selected.length }));
        batchUpdateTrigger.prop('hidden', selected.length === 0);

        if (selected.length === 0 && selectedScope.prop('checked')) {
            $('input[name="scope"][value="filtered"]').prop('checked', true);
        }
    }

    function initializeSelection() {
        updateSelectionState();

        $(document).on('click', '.assistant-select-all', function () {
            var shouldSelect = $('.assistant-row-selector.is-selected').length !== $('.assistant-row-selector').length;

            $('.assistant-row-selector').each(function () {
                setRowSelection($(this), shouldSelect);
            });
            updateSelectionState();
        });

        $(document).on('click', '.assistant-row-selector', function () {
            var selector = $(this);
            setRowSelection(selector, !selector.hasClass('is-selected'));
            updateSelectionState();
        });

        $(document).on('submit', '.assistant-batch-delete-form', function (event) {
            var selected = selectedStoreIds();

            if (selected.length === 0) {
                event.preventDefault();
                showCopyToast(translate('selectBeforeDelete'));
                return;
            }

            if (!window.confirm(translate('batchDeleteConfirm', { count: selected.length }))) {
                event.preventDefault();
            }
        });

        $('#assistant-export-modal').on('show.bs.modal', function () {
            updateSelectionState();
        });

        $('#assistant-export-form').on('submit', function (event) {
            var form = $(this);
            var scope = form.find('input[name="scope"]:checked').val();
            var selected = selectedStoreIds();
            var inputs = $('#assistant-export-selected-inputs');

            inputs.empty();

            if (scope === 'selected' && selected.length === 0) {
                event.preventDefault();
                showCopyToast(translate('selectBeforeExport'));
                return;
            }

            selected.forEach(function (id) {
                $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(inputs);
            });
        });
    }

    window.ErpAssistantDomainStores.refresh = function (scope) {
        addFieldActions(scope);
        updateSelectionState();
    };

    function initializeImportDropzone() {
        var zone = $('.assistant-import-dropzone');
        var input = $('#assistant-import-file');
        var filename = $('#assistant-import-file-name');

        if (!zone.length || !input.length) {
            return;
        }

        function showFilename(file) {
            filename.text(file ? translate('selectedFile', { name: file.name }) : '');
            zone.toggleClass('is-filled', Boolean(file));
        }

        input.on('change', function () {
            showFilename(this.files && this.files[0]);
        });

        zone.on('dragover dragenter', function (event) {
            event.preventDefault();
            event.stopPropagation();
            zone.addClass('is-dragover');
        }).on('dragleave dragend drop', function (event) {
            event.preventDefault();
            event.stopPropagation();
            zone.removeClass('is-dragover');
        }).on('drop', function (event) {
            var files = event.originalEvent.dataTransfer.files;

            if (!files || !files.length) {
                return;
            }

            input.prop('files', files);
            showFilename(files[0]);
        });
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (error) {
                reject(error);
            }

            document.body.removeChild(textarea);
        });
    }

    function showCopyToast(message) {
        $('.assistant-copy-toast').remove();
        $('<div class="assistant-copy-toast" role="status"></div>').text(message).appendTo(document.body)
            .delay(1200).fadeOut(180, function () {
                $(this).remove();
            });
    }

    function copyFieldValue(field) {
        copyText(field.attr('data-copy-value') || '').then(function () {
            field.addClass('is-copied').attr('title', translate('copied'));
            showCopyToast(translate('copied'));
            window.setTimeout(function () {
                field.removeClass('is-copied').attr('title', translate('clickToCopy'));
            }, 1200);
        }).catch(function () {
            showCopyToast(translate('copyFailed'));
        });
    }

    $(document).on('click', '.assistant-copy-trigger', function (event) {
        event.preventDefault();
        copyFieldValue($(this));
    });

    $(document).on('keydown', '.assistant-copy-trigger', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        copyFieldValue($(this));
    });

    $(document).on('click', '.assistant-history-trigger', function () {
        var button = $(this);
        var content = $('#assistant-history-content');
        var title = $('#assistant-history-title');
        var shopName = button.data('shop-name');

        title.text(translate('historyModalTitle', { name: shopName }));
        content.html('<p class="text-muted">' + escapeHtml(translate('historyLoading')) + '</p>');

        $.get(button.data('history-url')).done(function (response) {
            content.html(response.html || '<p class="text-muted">' + escapeHtml(translate('historyEmpty')) + '</p>');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : translate('historyLoadFailed');
            content.html('<p class="text-danger">' + escapeHtml(message) + '</p>');
        });
    });

    $(document).on('click', '.assistant-history-toggle', function () {
        var button = $(this);
        var changes = button.closest('.assistant-history-card').find('.assistant-history-change-list');
        var expanded = button.attr('aria-expanded') === 'true';

        changes.prop('hidden', expanded);
        button.attr('aria-expanded', expanded ? 'false' : 'true');
        button.html(expanded ? '<i class="fa fa-angle-down"></i> ' + escapeHtml(translate('expand')) : '<i class="fa fa-angle-up"></i> ' + escapeHtml(translate('collapse')));
    });

    $(document).on('submit', '.assistant-delete-form', function (event) {
        var button = $(this).find('button[type="submit"]');
        var shopName = button.data('shop-name') || '';

        if (!window.confirm(translate('deleteConfirm', { name: shopName }))) {
            event.preventDefault();
        }
    });
})(jQuery);
