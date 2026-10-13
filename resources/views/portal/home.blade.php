@extends('portal.layout')

@section('page_title', __('portal.menu.home'))

@section('content')
    <section class="content-header">
        <h1>
            {{ __('portal.home.workspace_title', ['portal' => $portal->name]) }}
            <small>{{ __('portal.home.workspace_description', ['portal' => $portal->name]) }}</small>
        </h1>
        <ol class="breadcrumb" style="margin-right: 30px;">
            <li><a href="/"><i class="fa fa-home"></i> {{ __('portal.menu.home') }}</a></li>
            <li class="active">{{ $portal->name }}</li>
        </ol>
    </section>

    <section class="content">
        <div class="portal-workbench-welcome">
            <h2>{{ $workbenchGreeting }}</h2>
            <p>{{ __('portal.home.today', ['date' => $today]) }}</p>
        </div>
    </section>
@endsection


