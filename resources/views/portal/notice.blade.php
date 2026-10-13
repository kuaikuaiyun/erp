@extends('portal.notice-layout')

@section('content')
    <section class="portal-notice portal-notice-{{ $noticeType }}">
        <div class="portal-notice-icon">
            <i class="fa {{ $noticeType === 'maintenance' ? 'fa-wrench' : ($noticeType === 'login' ? 'fa-sign-in' : 'fa-lock') }}"></i>
        </div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        @if ($redirectUrl && $countdown)
            <p class="portal-notice-countdown" data-portal-countdown data-seconds="{{ $countdown }}">
                {{ __('portal.public.redirecting', ['seconds' => $countdown]) }}
            </p>
            <a href="{{ $redirectUrl }}" class="portal-notice-button">{{ __('portal.public.go_now') }}</a>
            <script>
                (function () {
                    var element = document.querySelector('[data-portal-countdown]');
                    var seconds = Number(element && element.getAttribute('data-seconds'));
                    var target = @json($redirectUrl);

                    if (!element || !target || !seconds) {
                        return;
                    }

                    var timer = window.setInterval(function () {
                        seconds -= 1;
                        element.textContent = @json(__('portal.public.redirecting', ['seconds' => '__seconds__'])).replace('__seconds__', seconds);

                        if (seconds <= 0) {
                            window.clearInterval(timer);
                            window.location.assign(target);
                        }
                    }, 1000);
                })();
            </script>
        @endif
    </section>
@endsection


