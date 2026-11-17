@extends('portal.layout')
@section('page_title', __('asset_manager.titles.' . $kind))
@section('content')
@include('portals.asset-manager.partials.record-manager')
@endsection
