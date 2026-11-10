<button type="button" class="btn btn-default btn-sm" id="xuzong-credit-import-open">导入账单</button>
<dialog id="xuzong-credit-import" aria-labelledby="xuzong-credit-import-title">
    <form method="post" action="{{ route('finance.reports.xuzong-credit.import') }}" enctype="multipart/form-data">
        @csrf
        <h4 id="xuzong-credit-import-title">导入徐总信用卡账单</h4>
        <p>账单仅用于徐总信用卡报表。匹配时核对交易号、商户和支付状态，并用通途订单交叉核对旧格式。重复导入时补齐账单的空字段。</p>
        <div class="form-group">
            <label for="xuzong-import-source">账单格式</label>
            <select id="xuzong-import-source" name="source" class="form-control" required>
                <option value="xuzong1-details" @selected(old('source', 'xuzong1-details') === 'xuzong1-details')>徐总支付1明细表</option>
                <option value="xuzong2-details" @selected(old('source') === 'xuzong2-details')>徐总支付2明细表</option>
            </select>
        </div>
        <div class="form-group">
            <label for="xuzong-import-account">信用卡账户</label>
            <select id="xuzong-import-account" name="account" class="form-control" required>
                <option value="">请选择信用卡账户</option>
                @foreach($xuzongImportAccounts as $account => $label)
                    <option value="{{ $account }}" @selected(old('account') === (string) $account)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="xuzong-import-timezone">账单原始时区</label>
            <select id="xuzong-import-timezone" name="source_timezone" class="form-control" required>
                @foreach(\App\Portals\Finance\FinanceWorkbookService::TIMEZONES as $timezone)
                    <option value="{{ $timezone }}" @selected(old('source_timezone', 'Asia/Shanghai') === $timezone)>{{ \App\Portals\Finance\FinanceCatalog::timezoneLabel($timezone) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="xuzong-import-file">账单文件</label>
            <input type="file" id="xuzong-import-file" name="file" accept=".xlsx,.csv,.tsv,.txt" required>
        </div>
        <button type="submit" class="btn btn-primary">导入并匹配</button>
        <button type="button" class="btn btn-default" id="xuzong-credit-import-close">取消</button>
    </form>
</dialog>
@push('scripts')
<script>
    (function () {
        const dialog = document.getElementById('xuzong-credit-import');
        document.getElementById('xuzong-credit-import-open').addEventListener('click', () => dialog.showModal());
        document.getElementById('xuzong-credit-import-close').addEventListener('click', () => dialog.close());
    })();
</script>
@endpush
