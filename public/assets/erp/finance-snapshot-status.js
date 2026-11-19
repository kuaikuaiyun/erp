(() => {
    const notice = document.querySelector('[data-finance-snapshot-status]');
    const form = document.querySelector('form[method="get"]');
    if (!form) return;

    const url = new URL(window.location.href);
    url.searchParams.set('snapshot_status', '1');
    url.searchParams.delete('report_snapshot');
    const displayedTime = notice.dataset.financeSnapshotTime;
    const displayedVersion = notice.dataset.financeSnapshotVersion;
    const message = notice.querySelector('[data-finance-snapshot-message]');
    const formValues = () => new URLSearchParams(new FormData(form)).toString();
    const originalFilters = formValues();
    let navigating = false;
    form.addEventListener('submit', () => { navigating = true; });
    document.addEventListener('click', (event) => {
        if (event.target.closest?.('.finance-summary-tabs a, .finance-channel-tabs a, .finance-report-actions a')) {
            navigating = true;
        }
    }, true);
    const messages = {
        pending: '报表已登记，等待后台生成。',
        updating: '正在更新，当前显示上一版。',
        failed: '上次更新失败，后台会重试。',
    };

    async function checkStatus() {
        if (!document.hidden && !navigating) {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (response.status === 401 || response.status === 403 || response.redirected) return;
                if (response.ok) {
                    const { state, updated_at: updatedAt, version } = await response.json();
                    if (navigating) return;
                    const changed = version ? version !== displayedVersion : updatedAt && updatedAt !== displayedTime;
                    if (state === 'ready' && changed) {
                        const latest = new URL(window.location.href);
                        latest.searchParams.delete('report_snapshot');
                        if (formValues() === originalFilters && !form.contains(document.activeElement)
                            && !document.querySelector('[data-export-row]:checked')) {
                            window.location.replace(latest.toString());
                        } else {
                            const link = document.createElement('a');
                            link.href = latest.toString();
                            link.textContent = '查看已生成的结果';
                            message.replaceChildren('报表已生成。', link, ' ');
                        }
                        return;
                    }
                    if (state !== notice.dataset.financeSnapshotStatus) {
                        message.textContent = messages[state] ?? '';
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
