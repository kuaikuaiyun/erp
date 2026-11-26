(function () {
        var form = document.getElementById('admin-login-form');
        var ticket = document.getElementById('login-tencent-captcha-ticket');
        var randstr = document.getElementById('login-tencent-captcha-randstr');
        var status = document.getElementById('login-tencent-captcha-status');
        var button = form ? form.querySelector('button[type="submit"]') : null;
        var options = JSON.parse(document.getElementById('password-captcha-options').textContent);
        var appId = options.appId;
        var messages = {
            loadFailed: options.load_failed,
            cancelled: options.cancelled,
            failed: options.failed
        };

        if (!form || !ticket || !randstr || !status) { return; }

        function showStatus(message) { status.textContent = message; status.classList.add('is-visible'); }
        function clearStatus() { status.textContent = ''; status.classList.remove('is-visible'); }
        function setSubmitting(value) {
            if (window.ErpLoginSubmitGuard) {
                if (value) { window.ErpLoginSubmitGuard.begin(); } else { window.ErpLoginSubmitGuard.end(); }
                return;
            }
            if (button) { button.disabled = value; }
        }

        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-tencent-captcha-verified') === '1') { return; }

            event.preventDefault();
            clearStatus();
            ticket.value = '';
            randstr.value = '';

            if (typeof window.TencentCaptcha !== 'function') {
                showStatus(messages.loadFailed);
                return;
            }

            setSubmitting(true);
            window.fetch(form.getAttribute('data-tencent-options-url'), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('Unable to create Tencent captcha options.'); }
                return response.json();
            }).then(function (options) {
                if (!options || !options.aid_encrypted || options.aid_encrypted_type !== 'CBC') {
                    throw new Error('Invalid Tencent captcha options.');
                }

                var captcha = new window.TencentCaptcha(appId, function (result) {
                    if (result && Number(result.ret) === 0 && result.ticket && result.randstr) {
                        ticket.value = String(result.ticket);
                        randstr.value = String(result.randstr);
                        form.setAttribute('data-tencent-captcha-verified', '1');
                        setSubmitting(true);
                        form.submit();
                        return;
                    }
                    setSubmitting(false);
                    showStatus(result && Number(result.ret) === 2 ? messages.cancelled : messages.failed);
                }, {
                    aidEncrypted: options.aid_encrypted,
                    aidEncryptedType: options.aid_encrypted_type
                });
                captcha.show();
            }).catch(function () {
                setSubmitting(false);
                showStatus(messages.loadFailed);
            });
        });
    })();
