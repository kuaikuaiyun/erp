<div class="portal-home-dashboard">
    <section class="portal-home-hero">
        <div class="portal-home-hero-main">
            <span class="portal-home-eyebrow"><i class="fa fa-circle"></i> {{ __('portal.home.platform_online') }}</span>
            <h2>{{ $greeting }}</h2>
            <p class="portal-home-today">{{ __('portal.home.today', ['date' => $today]) }}</p>
            <p class="portal-home-hero-intro">{{ __('portal.home.hero_intro') }}</p>
            <div class="portal-home-user-strip" aria-label="{{ __('portal.home.account_status') }}">
                <span class="portal-home-user-pill">
                    <i class="fa fa-circle"></i>
                    {{ __('portal.home.account_status') }} <strong>{{ __('portal.home.account_normal') }}</strong>
                </span>
            </div>
        </div>
        <div class="portal-home-hero-orbit" aria-hidden="true">
            <span class="portal-home-orbit-ring portal-home-orbit-ring-outer"></span>
            <span class="portal-home-orbit-ring portal-home-orbit-ring-inner"></span>
            <span class="portal-home-orbit-node portal-home-orbit-node-one"></span>
            <span class="portal-home-orbit-node portal-home-orbit-node-two"></span>
            <span class="portal-home-orbit-core"><i class="fa fa-cubes"></i></span>
        </div>
    </section>

    <section class="portal-home-systems">
        <div class="portal-home-section-heading">
            <h3>{{ __('portal.home.systems') }}</h3>
            <p>{{ __('portal.home.systems_intro') }}</p>
        </div>

        @if ($systems->isEmpty())
            <div class="portal-home-empty">
                <i class="fa fa-th-large"></i>
                <h4>{{ __('portal.home.empty_title') }}</h4>
                <p>{{ __('portal.home.empty_message') }}</p>
            </div>
        @else
            <div class="portal-home-grid">
                @foreach ($systems as $system)
                    <article class="portal-home-card {{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? 'is-maintenance' : '' }}">
                        <div class="portal-home-card-heading">
                            <div class="portal-home-card-icon"><i class="fa fa-cubes"></i></div>
                            <span class="portal-home-card-status {{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? 'is-maintenance' : '' }}">
                                {{ __('portal.status.' . $system['status']) }}
                            </span>
                        </div>
                        <div class="portal-home-card-content">
                            <h4>{{ $system['name'] }}</h4>
                            <p>{{ $system['remark'] ?: __('portal.public.home_ready_message') }}</p>
                        </div>
                        <div class="portal-home-card-footer">
                            @if ($system['can_enter'] && $system['url'])
                                <a href="{{ $system['url'] }}" target="_blank" rel="noopener noreferrer" class="portal-home-enter btn btn-primary btn-sm">
                                    {{ __('portal.home.enter') }} <i class="fa fa-arrow-right"></i>
                                </a>
                            @else
                                <span class="portal-home-enter is-disabled btn btn-default btn-sm"
                                      aria-disabled="true"
                                      title="{{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? __('portal.home.maintenance') : __('portal.home.domain_unavailable') }}">
                                    {{ __('portal.home.enter') }} <i class="fa fa-arrow-right"></i>
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>


