@extends('portal.layout')

@section('page_title', $menu->title ?: __($menu->title_key))

@section('content')
    @php
        $menuLabel = $menu->title ?: __($menu->title_key);
    @endphp

    <section class="content-header">
        <h1>
            {{ $menuLabel }}
            <small>{{ $portal->name }}</small>
        </h1>
        <ol class="breadcrumb" style="margin-right: 30px;">
            <li><a href="/"><i class="fa fa-home"></i> {{ __('portal.menu.home') }}</a></li>
            @if ($parentMenu)
                <li>{{ $parentMenu->title ?: __($parentMenu->title_key) }}</li>
            @endif
            <li class="active">{{ $menuLabel }}</li>
        </ol>
    </section>

    <section class="content">
        <div class="box box-primary portal-menu-placeholder">
            <div class="box-body">
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa {{ $menu->icon ?: 'fa-cube' }}"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">{{ $menuLabel }}</span>
                        <span class="info-box-number">{{ __('portal.public.menu_ready_title') }}</span>
                        <span class="progress-description">{{ __('portal.public.menu_ready_message') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection


