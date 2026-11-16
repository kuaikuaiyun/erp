(() => {
    const notice = document.querySelector('[data-finance-snapshot-status]');
    const form = notice?.parentElement.querySelector('form[method="get"]');
    if (!form) return;

    const url = new URL(window.location.href);
    url.searchParams.set('snapshot_status', '1');
    const originalFilters = new URLSearchParams(new FormData(form)).toString();
    const messages = {
        pending: '报表已登记，等待后台生成。',
        updating: '正在更新，当前显示上一版。',
        failed: '上次更新失败，后台会重试。',
    };

    async function checkStatus() {
        if (!document.hidden) {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (response.status === 401 || response.status === 403 || response.redirected) return;
                if (response.ok) {
                    const { state } = await response.json();
                    if (state === 'ready') {
                        const filtersChanged = new URLSearchParams(new FormData(form)).toString() !== originalFilters;
                        if (!filtersChanged && !form.contains(document.activeElement)
                            && !document.querySelector('[data-export-row]:checked')) {
                            window.location.reload();
                        } else {
                            const link = document.createElement('a');
                            link.href = window.location.href;
                            link.textContent = '查看已生成的结果';
                            notice.replaceChildren('报表已生成。', link);
                        }
                        return;
                    }
                    if (messages[state] && state !== notice.dataset.financeSnapshotStatus) {
                        notice.textContent = messages[state];
                        notice.dataset.financeSnapshotStatus = state;
                    }
                }
            } catch {
                // Retry transient network failures on the next check.
            }
        }
        window.setTimeout(checkStatus, 10000);
    }

    window.setTimeout(checkStatus, 10000);
})();
