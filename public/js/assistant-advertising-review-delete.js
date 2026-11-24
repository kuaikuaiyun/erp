(function () {
    'use strict';

    var dialog = document.querySelector('[data-ad-review-delete-dialog]');
    if (!dialog) return;

    var message = dialog.querySelector('[data-ad-review-delete-message]');
    var confirmButton = dialog.querySelector('[data-ad-review-delete-confirm]');
    var pendingForm = null;

    function openDialog(form, text) {
        pendingForm = form;
        message.textContent = text || '删除后运营可以重新提交该日期的数据，原记录仍会保留在“已删除”记录中。';
        confirmButton.disabled = false;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', 'open');
        confirmButton.focus();
    }

    function closeDialog() {
        pendingForm = null;
        confirmButton.disabled = false;
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('.js-delete-report');
        if (!button) return;
        var form = document.getElementById('delete-report-form');
        if (!form) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        form.action = button.dataset.url;
        openDialog(form, '确定删除 '+(button.dataset.operator || '该运营')+' 在 '+(button.dataset.date || '该日期')+' 的广告费提报吗？删除后可重新提交，原记录仍会保留在“已删除”记录中。');
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.closest || !form.closest('.assistant-review-template')) return;
        if (!form.querySelector('input[name="_method"][value="DELETE"], input[name="_method"][value="delete"]')) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        openDialog(form, '确认删除这份广告费提报吗？删除后运营可以重新填写，原记录仍会保留在“已删除”记录中。');
    }, true);

    dialog.querySelectorAll('[data-ad-review-delete-close]').forEach(function (button) {
        button.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) closeDialog();
    });
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        closeDialog();
    });
    confirmButton.addEventListener('click', function () {
        if (!pendingForm) return;
        var form = pendingForm;
        pendingForm = null;
        confirmButton.disabled = true;
        HTMLFormElement.prototype.submit.call(form);
    });
})();
