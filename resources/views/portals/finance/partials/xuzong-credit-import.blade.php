@include('portals.finance.partials.settlement-dialog-styles')
<button type="button" class="btn btn-default btn-sm" id="xuzong-credit-import-open">导入账单 / 订单</button>
<dialog class="settlement-dialog" id="xuzong-credit-import" aria-labelledby="xuzong-credit-import-title">
    <header>
        <h3 id="xuzong-credit-import-title">导入徐总信用卡订单或账单</h3>
        <button type="button" data-xuzong-import-close aria-label="关闭">×</button>
    </header>
    <form method="post" action="{{ route('finance.reports.xuzong-credit.import') }}" enctype="multipart/form-data" data-loading="off">
        @csrf
        <label class="settlement-import-drop" for="xuzong-import-file">
            <i class="fa fa-cloud-upload" aria-hidden="true"></i>
            <span data-xuzong-filename style="display:block;white-space:pre-line;overflow-wrap:anywhere;max-height:9em;overflow:auto">点击选择或拖入多个文件</span>
            <input type="file" id="xuzong-import-file" name="file" accept=".xlsx,.csv,.tsv,.txt" multiple required>
        </label>
        <p class="settlement-note" style="padding:0 20px">支持订单、退款明细和逐笔结算账单。退款明细按平台订单号匹配，仅计入成功退款。默认按北京时间读取。</p>
        <p id="xuzong-credit-import-progress" style="padding:0 20px;white-space:pre-line;overflow-wrap:anywhere" role="status" aria-live="polite"></p>
        <footer>
            <button type="button" class="btn btn-default" data-xuzong-import-close>取消</button>
            <button type="submit" class="btn btn-success">确认导入</button>
        </footer>
    </form>
</dialog>
@push('scripts')
<script>
    (function () {
        const dialog = document.getElementById('xuzong-credit-import');
        const form = dialog.querySelector('form');
        const input = document.getElementById('xuzong-import-file');
        const progress = document.getElementById('xuzong-credit-import-progress');
        const resultKey = 'finance:xuzong-credit:import-result';
        document.getElementById('xuzong-credit-import-open').addEventListener('click', () => dialog.showModal());
        const closeButtons = [...dialog.querySelectorAll('[data-xuzong-import-close]')];
        closeButtons.forEach(button => button.addEventListener('click', () => dialog.close()));
        const previousResult = sessionStorage.getItem(resultKey);
        if (previousResult !== null) {
            sessionStorage.removeItem(resultKey);
            progress.textContent = previousResult;
            closeButtons.at(-1).textContent = '关闭';
            dialog.showModal();
        }
        input.addEventListener('change', () => {
            dialog.querySelector('[data-xuzong-filename]').textContent = [...input.files].map(file => file.name).join('\n') || '点击选择或拖入多个文件';
        });
        dialog.addEventListener('cancel', event => {
            if (form.querySelector('[type="submit"]').disabled) event.preventDefault();
        });
        async function uploadParts(file, isStatement) {
            if (isStatement || !/\.csv$/i.test(file.name)) return [file];
            const maxBytes = 1024 * 1024;
            const csv = await file.text();
            const firstBreak = csv.indexOf('\n');
            const header = csv.slice(0, firstBreak + 1);
            if (firstBreak < 0) throw new Error('文件没有可导入的记录。');
            const encoder = new TextEncoder();
            const headerBytes = encoder.encode(header).length;
            const parts = [];
            let start = firstBreak + 1;
            let partStart = start;
            let partBytes = headerBytes;
            let quoted = false;
            let partRows = 0;
            for (let index = start; index <= csv.length; index++) {
                if (csv[index] === '"') quoted = !quoted;
                if (index !== csv.length && (csv[index] !== '\n' || quoted)) continue;
                const end = Math.min(index + 1, csv.length);
                if (end === start) break;
                const rowBytes = encoder.encode(csv.slice(start, end)).length;
                if (rowBytes + headerBytes > maxBytes) throw new Error('有一条记录超过上传分段的大小限制。');
                if (partRows === 4500 || partBytes + rowBytes > maxBytes) {
                    parts.push(csv.slice(partStart, start));
                    partStart = start;
                    partBytes = headerBytes;
                    partRows = 0;
                }
                partBytes += rowBytes;
                partRows++;
                start = end;
            }
            if (start > partStart) parts.push(csv.slice(partStart, start));
            if (parts.length === 0) throw new Error('文件没有可导入的记录。');

            return parts.map(part => new File([header, part], file.name, {type: 'text/csv'}));
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const files = [...input.files];
            const submit = form.querySelector('[type="submit"]');
            submit.textContent = '正在导入…';
            submit.disabled = input.disabled = true;
            closeButtons.forEach(button => { button.disabled = true; });
            let total = 0;
            let saved = 0;
            let matched = null;
            const errors = [];
            try {
                const uploads = await Promise.all(files.map(async file => {
                    const header = /\.(csv|tsv|txt)$/i.test(file.name) ? (await file.slice(0, 8192).text()).split(/\r?\n/, 1)[0] : '';
                    const isStatement = header.includes('折算后结算贡献') && header.includes('适用汇率');
                    const rank = isStatement ? 2 : header.includes('退款单号') ? 1 : 0;
                    return {file, rank};
                }));
                uploads.sort((a, b) => a.rank - b.rank);
                for (const [index, {file, rank}] of uploads.entries()) {
                    let finished = 0;
                    try {
                        const parts = await uploadParts(file, rank > 0);
                        for (const part of parts) {
                            progress.textContent = '正在导入文件 ' + (index + 1) + ' / ' + files.length + '，第 ' + (finished + 1) + ' / ' + parts.length + ' 段…';
                            const data = new FormData(form);
                            data.set('file', part);
                            const response = await fetch(form.action, {method: 'POST', headers: {'Accept': 'application/json'}, body: data});
                            const result = await response.json().catch(() => ({}));
                            if (!response.ok || typeof result.total !== 'number') {
                                throw new Error(result.errors?.file?.[0] || result.message || '导入失败（HTTP ' + response.status + '）。');
                            }
                            total += result.total;
                            saved += result.saved;
                            if (typeof result.matched === 'number') matched = (matched ?? 0) + result.matched;
                            finished++;
                        }
                    } catch (error) {
                        errors.push(file.name + '：' + (finished ? '已完成 ' + finished + ' 段。' : '') + error.message);
                    }
                }
                const matchSummary = matched === null ? '' : '，匹配 ' + matched + ' 条';
                progress.textContent = ['已处理 ' + total + ' 条，新增或补齐 ' + saved + ' 条' + matchSummary + '。', ...errors].join('\n');
                if (saved > 0) {
                    closeButtons.at(-1).textContent = '关闭';
                    sessionStorage.setItem(resultKey, progress.textContent);
                    window.location.reload();
                }
            } finally {
                submit.disabled = input.disabled = false;
                submit.textContent = '确认导入';
                closeButtons.forEach(button => { button.disabled = false; });
            }
        });
    })();
</script>
@endpush
