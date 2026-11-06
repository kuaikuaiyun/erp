(function () {
    'use strict';
    window.financeDownload = async function (url, button) {
        if (button.dataset.exportBusy === '1') return;
        button.dataset.exportBusy = '1';
        var original = button.innerHTML;
        var wasDisabled = button.disabled;
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> 正在导出…';
        var notice = button.parentElement.querySelector('[data-finance-download-status]');
        if (!notice) {
            notice = document.createElement('span');
            notice.dataset.financeDownloadStatus = '1';
            notice.setAttribute('role', 'status');
            notice.style.fontSize = '12px';
            button.insertAdjacentElement('afterend', notice);
        }
        notice.textContent = '正在生成文件，请稍候…';
        try {
            var response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
            var disposition = response.headers.get('Content-Disposition') || '';
            if (!response.ok || !/attachment/i.test(disposition)) {
                var error = '导出失败，请稍后重试。';
                if ((response.headers.get('Content-Type') || '').includes('application/json')) {
                    var data = await response.json();
                    error = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || error);
                }
                throw new Error(error);
            }
            var blob = await response.blob();
            var filename = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
            var plain = /filename="?([^";]+)"?/i.exec(disposition);
            var name = filename ? decodeURIComponent(filename[1]) : (plain ? plain[1] : 'finance-export.xlsx');
            var link = document.createElement('a');
            var objectUrl = URL.createObjectURL(blob);
            link.href = objectUrl;
            link.download = name.replace(/[\\/]/g, '_');
            link.dataset.loading = 'off';
            document.body.appendChild(link);
            link.click(); link.remove();
            setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
            notice.remove();
        } catch (error) {
            notice.textContent = error.message || '下载失败，请稍后重试。';
        } finally {
            button.dataset.exportBusy = '0';
            button.disabled = wasDisabled;
            button.removeAttribute('aria-disabled');
            button.removeAttribute('aria-busy');
            button.innerHTML = original;
        }
    };
})();
