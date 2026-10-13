@php($footer = app(\App\Services\SystemSettingService::class)->footer())
<footer class="main-footer admin-custom-footer">
    <strong>{{ $footer['copyright_text'] !== '' ? $footer['copyright_text'] : __('configuration.footer.default_copyright', ['name' => config('admin.name')]) }}</strong>
    @if($footer['contact_information'] !== '')
        <button type="button" class="admin-footer-contact" id="admin-footer-contact">{{ __('configuration.footer.contact_link') }}</button>
    @endif
</footer>

<style>
    .admin-custom-footer { min-height: 50px; }
    .admin-custom-footer strong { display: inline; vertical-align: baseline; }
    .admin-footer-contact { appearance: none; background: transparent; border: 0; color: #337ab7; cursor: pointer; display: inline; font: inherit; line-height: inherit; margin-left: 4px; padding: 0; vertical-align: baseline; }
    .admin-footer-contact:hover, .admin-footer-contact:focus { color: #23527c; text-decoration: underline; }
    #toast-container .toast-message { white-space: pre-wrap; }
</style>

@if($footer['contact_information'] !== '')
<script>
    (function () {
        var contactButton = document.getElementById('admin-footer-contact');
        var contactInformation = @json($footer['contact_information']);
        var contactTitle = @json(__('configuration.footer.contact_toast_title'));

        if (!contactButton) { return; }

        contactButton.addEventListener('click', function () {
            if (typeof window.toastr === 'undefined') { return; }

            window.toastr.info(contactInformation, contactTitle, {
                closeButton: true,
                closeOnHover: false,
                escapeHtml: true,
                extendedTimeOut: 0,
                positionClass: 'toast-top-right',
                progressBar: true,
                timeOut: 5000
            });
        });
    })();
</script>
@endif


