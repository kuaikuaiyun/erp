@include('portals.finance.partials.settlement-dialog-styles')
@push('styles')
<style>
#xuzong-statement-dialog { width: 960px; border: 1px solid #d3e0eb; }
#xuzong-statement-dialog > header, #xuzong-statement-dialog > footer { position: sticky; z-index: 1; background: #f7fafc; }
#xuzong-statement-dialog > header { top: 0; }
#xuzong-statement-dialog > footer { bottom: 0; }
#xuzong-statement-dialog [hidden] { display: none !important; }
.xuzong-statement-section { margin: 12px 0; padding: 12px; border: 1px solid #d3e0eb; border-radius: 4px; background: #f7fafc; }
.xuzong-statement-section > summary { cursor: pointer; font-weight: 600; }
.xuzong-statement-section form { margin-top: 10px; }
</style>
@endpush
@push('scripts')
<dialog id="xuzong-statement-dialog" class="settlement-dialog" aria-labelledby="xuzong-statement-title">
    <header>
        <h3 id="xuzong-statement-title">结算变动明细</h3>
    </header>
    <div class="box-body">
        <div role="status" data-statement-status></div>
        <div data-statement-body></div>
    </div>
    <footer>
        <button class="btn btn-default btn-sm" type="button" data-statement-back>返回</button>
        <button class="btn btn-primary btn-sm" type="submit" form="xuzong-order-edit-form" data-statement-save hidden>保存订单</button>
    </footer>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('xuzong-statement-dialog');
    const body = dialog.querySelector('[data-statement-body]');
    const status = dialog.querySelector('[data-statement-status]');
    const save = dialog.querySelector('[data-statement-save]');
    const notices = document.getElementById('xuzong-notices');
    let returnToNotices = false;
    let url;
    dialog.querySelector('[data-statement-back]').addEventListener('click', () => {
        if (!busy) dialog.close();
    });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (returnToNotices && document.getElementById('xuzong-notice-open')?.hidden === false) {
            notices.showModal();
        }
    });
    let busy = false;

    async function load(target) {
        const response = await fetch(target, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok || response.redirected) throw new Error('读取结算明细失败，请重试。');
        body.innerHTML = await response.text();
        save.hidden = !body.querySelector("#xuzong-order-edit-form");
    }

    document.addEventListener('click', async event => {
        const link = event.target.closest('[data-xuzong-statement]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        url = link.href;
        body.replaceChildren();
        status.textContent = '正在加载…';
        save.hidden = true;
        returnToNotices = notices?.open ?? false;
        if (returnToNotices) notices.close();
        dialog.showModal();
        try {
            await load(url);
            status.textContent = '';
        } catch (error) {
            status.textContent = error.message;
        }
    });

    body.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const form = event.target;
        busy = true;
        save.disabled = true;
        status.textContent = '正在保存…';
        try {
            const data = new FormData(form);
            const query = new URLSearchParams(window.location.search);
            data.set('notice_page', query.get('statement_notice_page') || '1');
            data.set('notice_per_page', query.get('statement_notice_per_page') || '10');
            const response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || '保存失败。');
            if (result.handled) {
                const target = new URL(window.location.href);
                if (notices) target.searchParams.set('statement_notice_page', String(result.noticePage));
                window.location.replace(target);
                return;
            }
            await load(url);
            status.textContent = '结算明细已更新。';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy = false;
            save.disabled = false;
        }
    });
})();
</script>
@endpush