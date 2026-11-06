@extends('portal.layout')

@section('page_title', $page['title'])

@push('styles')
<style>
.daily-submission-shell{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}
.daily-submission-empty{padding:54px 24px;text-align:center;color:#71869a}
.daily-submission-empty .icon{display:inline-flex;width:64px;height:64px;align-items:center;justify-content:center;margin-bottom:15px;border-radius:18px;background:#eaf4fb;color:#3184b9;font-size:27px}
.daily-submission-empty h3{margin:0 0 9px;color:#284b65;font-size:20px}
.daily-submission-empty p{margin:0;font-size:13px}
</style>
@endpush

@section('content')
<section class="content-header">
    <h1>{{ $page['title'] }} <small>日常提报</small></h1>
</section>
<section class="content">
    <div class="box daily-submission-shell">
        <div class="daily-submission-empty">
            <span class="icon"><i class="fa {{ $page['icon'] }}" aria-hidden="true"></i></span>
            <h3>{{ $page['title'] }}</h3>
            <p>菜单及独立页面已建立，提报字段与审核流程配置后将在此处使用。</p>
        </div>
    </div>
</section>
@endsection
