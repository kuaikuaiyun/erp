(function ($) {
    'use strict';
    function config() { return window.PortalHomeFolders || null; }
    function token() { return $('meta[name="csrf-token"]').attr('content') || ''; }
    function url(template, id) { return String(template || '').replace('__FOLDER__', id); }
    function refreshFolderLabels() {
        $('[data-portal-home-folder]').each(function () {
            var folder = $(this), count = folder.find('[data-portal-home-dropzone] [data-portal-id]').length;
            folder.find('.portal-home-folder-heading small').text(count + ' 个系统');
            if (folder.hasClass('portal-home-folder-ungrouped')) folder.toggleClass('is-empty', count === 0);
        });
    }
    function saveLayout() {
        var settings = config(); if (!settings) return;
        var folders = [], ungrouped = [];
        $('[data-portal-home-folder]').each(function () {
            var folder = $(this), ids = folder.find('[data-portal-home-dropzone] [data-portal-id]').map(function () { return Number(this.getAttribute('data-portal-id')); }).get(), folderId = folder.attr('data-folder-id');
            if (folderId) folders.push({ id: Number(folderId), portal_ids: ids }); else ungrouped = ids;
        });
        $.ajax({ url: settings.layoutUrl, method: 'PUT', contentType: 'application/json', data: JSON.stringify({ folders: folders, ungrouped_portal_ids: ungrouped }), headers: { 'X-CSRF-TOKEN': token() } }).fail(function () { window.alert('系统分组保存失败，请刷新页面后重试。'); window.location.reload(); });
    }
    function bindDragAndDrop() {
        var dragged = null;
        $(document).off('.portalHomeFolders').on('dragstart.portalHomeFolders', '[data-portal-home-system]', function (event) { dragged = this; event.originalEvent.dataTransfer.effectAllowed = 'move'; event.originalEvent.dataTransfer.setData('text/plain', this.getAttribute('data-portal-id')); $('.portal-home-folder-ungrouped.is-empty').addClass('is-drop-target'); $(this).addClass('is-dragging'); }).on('dragend.portalHomeFolders', '[data-portal-home-system]', function () { $(this).removeClass('is-dragging'); dragged = null; $('.portal-home-dropzone').removeClass('is-drag-over'); $('.portal-home-folder-ungrouped').removeClass('is-drop-target'); refreshFolderLabels(); }).on('dragover.portalHomeFolders', '[data-portal-home-dropzone]', function (event) { event.preventDefault(); event.originalEvent.dataTransfer.dropEffect = 'move'; $(this).addClass('is-drag-over'); }).on('dragleave.portalHomeFolders', '[data-portal-home-dropzone]', function (event) { if (!$.contains(this, event.relatedTarget)) $(this).removeClass('is-drag-over'); }).on('drop.portalHomeFolders', '[data-portal-home-dropzone]', function (event) { event.preventDefault(); $(this).removeClass('is-drag-over'); if (!dragged) return; var target = $(event.target).closest('[data-portal-home-system]'); if (target.length && target[0] !== dragged) target.before(dragged); else $(this).append(dragged); refreshFolderLabels(); saveLayout(); });
    }
    function bindFolderActions() {
        var modal = $('#portal-home-folder-modal'), form = modal.find('[data-portal-home-folder-form]'), editingId = null;
        $(document).on('click', '[data-portal-home-folder-create]', function () { editingId = null; form[0].reset(); modal.find('.modal-title').text('新增文件夹'); modal.modal('show'); }).on('click', '[data-portal-home-folder-edit]', function () { editingId = $(this).data('folder-id'); form.find('[name="name"]').val($(this).data('folder-name')); modal.find('.modal-title').text('编辑文件夹'); modal.modal('show'); }).on('click', '[data-portal-home-folder-delete]', function () { var button = $(this); if (!window.confirm('删除“' + button.data('folder-name') + '”后，其中的系统会回到未分组系统。确定删除吗？')) return; $.ajax({ url: url(config().deleteUrl, button.data('folder-id')), method: 'DELETE', headers: { 'X-CSRF-TOKEN': token() } }).done(function () { window.location.reload(); }).fail(function () { window.alert('删除文件夹失败，请重试。'); }); });
        form.on('submit', function (event) { event.preventDefault(); var submit = form.find('[type="submit"]'); submit.prop('disabled', true); $.ajax({ url: editingId ? url(config().updateUrl, editingId) : config().storeUrl, method: editingId ? 'PATCH' : 'POST', data: { name: form.find('[name="name"]').val(), _token: token() } }).done(function () { window.location.reload(); }).fail(function (xhr) { window.alert((xhr.responseJSON && xhr.responseJSON.message) || '保存文件夹失败，请重试。'); }).always(function () { submit.prop('disabled', false); }); });
    }
    $(function () { if (config()) { bindDragAndDrop(); bindFolderActions(); } });
})(jQuery);
