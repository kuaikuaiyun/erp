(function () {
    'use strict';
    var form = document.getElementById('finance-payment-account-form');
    if (!form) return;
    var editors = JSON.parse(document.getElementById('finance-payment-account-editors').textContent);
    var type = form.elements.account_type;
    var secret = form.elements.relay_hmac_secret;
    var relay = document.getElementById('finance-paypal-relay-fields');
    var platform = form.elements.card_platform;
    var nameLabel = form.querySelector('label[for="finance-account-name"]');
    var hasSecret = false;
    var relayConfigured = false;
    var editing = false;
    var relayStatus = document.getElementById('finance-account-relay-status');
    var fields = ['account_name', 'account_type', 'card_group_name', 'card_platform', 'card_merchant_key', 'main_currency', 'status', 'remark', 'relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_server_remark'];
    function updateMerchant() {
        var conflux = !platform.disabled && platform.value === 'ye';
        nameLabel.textContent = conflux ? '店铺名称' : nameLabel.dataset.defaultLabel;
        document.getElementById('finance-credit-account-note').hidden = conflux || platform.disabled;
        form.elements.card_merchant_key.disabled = !conflux;
        form.elements.card_merchant_key.required = conflux && !!form.dataset.newCardPlatform;
        document.getElementById('finance-card-merchant-field').hidden = !conflux;
    }
    function updateType() {
        var paypal = type.value === 'paypal';
        relay.hidden = !paypal;
        relay.disabled = !paypal;
        platform.disabled = paypal;
        form.elements.card_group_name.disabled = paypal;
        document.getElementById('finance-card-group-field').hidden = paypal;
        document.getElementById('finance-card-platform-field').hidden = paypal;
        updateMerchant();
        relay.querySelectorAll('[data-relay-required]').forEach(function (input) {
            input.required = paypal && (input !== secret || !hasSecret);
        });
        secret.placeholder = hasSecret && paypal ? form.dataset.secretPreserved : '';
        relayStatus.textContent = relayConfigured ? relayStatus.dataset.configured : (editing ? relayStatus.dataset.missing : relayStatus.dataset.newAccount);
        if (!paypal) secret.value = '';
    }
    function open(key, kind, keepInput, newPlatform, newGroup) {
        var editor = key ? editors[key] : null;
        if (key && !editor) return;
        form.action = editor ? editor.url : form.dataset.storeUrl;
        form.elements.account_editor_key.value = key || '';
        hasSecret = !!(editor && editor.has_secret);
        relayConfigured = !!(editor && editor.relay_configured);
        editing = !!editor;
        document.getElementById('finance-payment-account-create-title').textContent = editor ? form.dataset.editTitle : form.dataset.createTitle;
        type.querySelectorAll('option').forEach(function (option) { option.disabled = !!(editor && editor.type_locked && option.value !== editor.account_type); });
        document.getElementById('finance-account-type-lock').hidden = !(editor && editor.type_locked);
        if (!keepInput) {
            form.querySelectorAll('.alert').forEach(function (alert) { alert.remove(); });
            var values = editor || {account_name: '', account_type: kind || 'paypal', card_group_name: newGroup || '', card_platform: newPlatform || '', main_currency: 'USD', status: 'enabled', remark: ''};
            fields.forEach(function (field) { form.elements[field].value = values[field] || (field === 'relay_hmac_key_id' ? 'erp-main' : ''); });
        }
        secret.value = '';
        updateType();
        $('#finance-payment-account-create').modal('show');
    }
    type.addEventListener('change', updateType);
    platform.addEventListener('change', updateMerchant);
    form.elements.main_currency.addEventListener('input', function () { this.value = this.value.toUpperCase(); });
    document.querySelectorAll('[data-add-payment-account]').forEach(function (button) {
        button.addEventListener('click', function () { open('', button.dataset.addPaymentAccount, false, button.dataset.cardPlatform, button.dataset.creditGroup); });
    });
    document.querySelectorAll('[data-edit-payment-account]').forEach(function (button) {
        button.addEventListener('click', function () { open(button.dataset.editPaymentAccount, null, false); });
    });
    function lockAccountType(event) {
        var editor = editors[event.detail.key];
        if (editor) editor.type_locked = true;
    }
    document.addEventListener('finance:balance-refreshed', lockAccountType);
    document.addEventListener('finance:transactions-synced', lockAccountType);
    $('#finance-payment-account-create').on('hidden.bs.modal', function () { secret.value = ''; });
    if (form.dataset.failed === 'true') {
        open(form.elements.account_editor_key.value, type.value, true);
    } else if (form.dataset.newCardPlatform) {
        open('', 'credit_card', false, form.dataset.newCardPlatform, form.dataset.newCardGroup);
    } else {
        updateType();
    }
})();
