<article class="portal-home-card {{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? 'is-maintenance' : '' }}" data-portal-home-system data-portal-id="{{ $system['id'] }}" @if($canManageFolders) draggable="true" @endif>
    <div class="portal-home-card-heading">
        <div class="portal-home-card-icon"><i class="fa fa-cubes"></i></div>
        <span class="portal-home-card-status {{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? 'is-maintenance' : '' }}">{{ __('portal.status.' . $system['status']) }}</span>
    </div>
    <div class="portal-home-card-content"><h4>{{ $system['name'] }}</h4><p>{{ $system['remark'] ?: __('portal.public.home_ready_message') }}</p></div>
    <div class="portal-home-card-footer">
        @if ($system['can_enter'] && $system['url'])
            <a href="{{ $system['url'] }}" target="_blank" rel="noopener noreferrer" class="portal-home-enter btn btn-primary btn-sm">{{ __('portal.home.enter') }} <i class="fa fa-arrow-right"></i></a>
        @else
            <span class="portal-home-enter is-disabled btn btn-default btn-sm" aria-disabled="true" title="{{ $system['status'] === \App\Models\Portal::STATUS_MAINTENANCE ? __('portal.home.maintenance') : __('portal.home.domain_unavailable') }}">{{ __('portal.home.enter') }} <i class="fa fa-arrow-right"></i></span>
        @endif
    </div>
</article>
