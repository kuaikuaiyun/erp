<div data-account-transfer-root>
    <div hidden data-transfer-config>{{ json_encode(['base'=>admin_url('organization/accounts/transfer'),'csrf'=>csrf_token(),'texts'=>__('account_transfer')], JSON_UNESCAPED_UNICODE) }}</div>
    <dialog class="account-transfer-dialog" data-transfer-dialog aria-labelledby="account-transfer-title">
        <header><h3 id="account-transfer-title"></h3><button type="button" data-transfer-close aria-label="{{ __('account_transfer.close') }}">×</button></header>
        <section data-transfer-import>
            <p>{{ __('account_transfer.intro') }}</p>
            <a href="{{ admin_url('organization/accounts/transfer/template') }}" target="_blank" rel="noopener" data-pjax="0">{{ __('account_transfer.template') }}</a>
            <label>{{ __('account_transfer.file') }}<input type="file" accept=".xlsx" data-transfer-file></label>
            <button type="button" class="btn btn-primary" data-transfer-preview data-loading="off">{{ __('account_transfer.preview') }}</button>
            <div class="account-transfer-table" data-transfer-table></div>
            <label>{{ __('account_transfer.password') }}<input type="password" minlength="8" maxlength="255" autocomplete="new-password" data-transfer-password></label>
        </section>
        <section data-transfer-export hidden>
            <p>{{ __('account_transfer.export_intro') }}</p>
            <label><input type="radio" name="account-transfer-scope" value="filtered" checked> {{ __('account_transfer.filtered') }}</label>
            <label><input type="radio" name="account-transfer-scope" value="selected"> {{ __('account_transfer.selected') }}</label>
        </section>
        <p data-transfer-status role="status" aria-live="polite"></p>
        <footer><button type="button" class="btn btn-default" data-transfer-close>{{ __('account_transfer.close') }}</button><button type="button" class="btn btn-primary" data-transfer-save data-loading="off"></button></footer>
    </dialog>
</div>
