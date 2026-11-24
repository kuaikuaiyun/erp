(() => {
    const progress = document.getElementById('tongtu-sync-progress');
    if (!progress || progress.dataset.initialized) return;
    progress.dataset.initialized = 'true';

    const coverage = progress.querySelector('[data-coverage]');
    const active = progress.querySelector('[data-active]');
    const refresh = progress.querySelector('[data-refresh]');
    const lastSync = document.getElementById('tongtu-last-sync');
    const initialDays = Number(progress.dataset.syncedDays);
    const initialSync = lastSync.textContent.trim();

    async function poll() {
        if (!progress.isConnected) return;
        try {
            if (!document.hidden) {
                const response = await fetch(progress.dataset.url, { headers: { Accept: 'application/json' } });
                if (response.ok) {
                    const data = await response.json();
                    coverage.textContent = `${data.synced_days} / ${data.total_days}`;
                    active.textContent = data.active
                        ? progress.dataset.activeTemplate.replace('__date__', data.active.date).replace('__orders__', data.active.orders)
                        : '';
                    lastSync.textContent = progress.dataset.lastSyncTemplate.replace('__time__', data.last_sync || '').trim();
                    lastSync.hidden = !data.last_sync;
                    refresh.hidden = data.synced_days === initialDays && lastSync.textContent === initialSync;
                    progress.hidden = data.synced_days >= data.total_days && !data.active && refresh.hidden;
                }
            }
        } catch {
            // Keep the last known progress and retry on the next interval.
        } finally {
            if (progress.isConnected) setTimeout(poll, 10000);
        }
    }

    poll();
})();
