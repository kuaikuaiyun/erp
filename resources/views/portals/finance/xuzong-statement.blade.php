@extends('portals.finance.layout')

@section('finance_content')
<a class="btn btn-default" href="{{ route('finance.reports.xuzong-credit') }}">返回徐总信用卡</a>
@include('portals.finance.partials.xuzong-statement-body')
@endsection
