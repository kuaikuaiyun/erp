@extends('portal.layout')
@section('page_title', __('asset_manager.titles.' . $kind))
@section('content')
<section class="content-header">
    <h1>{{ __('asset_manager.titles.computers') }} <small>{{ __('asset_manager.computer_ui.subtitle') }}</small></h1>
</section>
@include('portals.asset-manager.partials.computer-manager')
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/erp/asset-manager.css') }}?v=20261118-2">
<link rel="stylesheet" href="{{ asset('assets/erp/asset-computers.css') }}?v=20261118-1">
@endpush
