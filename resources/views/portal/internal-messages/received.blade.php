@extends('portal.layout')

@section('page_title', '站内消息')

@push('styles')
<style>
.received-message-page{--blue:#2d86ba;--ink:#243e54;--muted:#74899a}.received-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:15px;padding:16px 18px;border-left:4px solid #f0a126;border-radius:9px;background:#fff;box-shadow:0 4px 15px rgba(32,65,91,.08)}.received-summary strong{font-size:20px;color:#d98100}.received-tabs{display:flex;gap:7px}.received-tabs .btn{border-radius:16px;padding:5px 14px}.received-message{border:0;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(31,61,84,.08);margin-bottom:14px}.received-message.pending{border-left:4px solid #f0a126}.received-message.processed{border-left:4px solid #35a36f}.received-message-head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;padding:14px 17px;background:linear-gradient(135deg,#f7fbfe,#edf5fa)}.received-title{margin:0;color:var(--ink);font-size:17px;font-weight:700}.received-meta{margin-top:5px;color:var(--muted);font-size:12px}.received-status{display:inline-block;white-space:nowrap;border-radius:14px;padding:5px 11px;font-size:12px}.received-status.pending{color:#946200;background:#fff0bd}.received-status.processed{color:#17754b;background:#dcf5e8}.received-body{padding:16px 18px;white-space:pre-wrap;color:#40576a;line-height:1.75}.received-images{display:flex;gap:10px;flex-wrap:wrap;padding:0 18px 16px}.received-images img{width:118px;height:86px;object-fit:cover;border:1px solid #d8e3ea;border-radius:7px;cursor:zoom-in}.received-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:12px 17px;border-top:1px solid #e8eef2;background:#fbfcfd}.received-processed-time{margin-right:auto;color:#718798;font-size:12px}.received-empty{padding:65px 20px;text-align:center;color:#8395a3}.received-empty .fa{display:block;font-size:36px;margin-bottom:10px;color:#b8c8d3}
</style>
@endpush

@section('content')
<section class="content-header"><h1>站内消息 <small>查看发送给我的消息并跟踪处理状态</small></h1></section>
<section class="content received-message-page">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="received-summary">
        <div><i class="fa fa-clock-o text-orange"></i> 当前待处理消息：<strong>{{ $pendingCount }}</strong> 条</div>
        <div class="received-tabs">
            <a class="btn btn-sm {{ $status === 'pending' ? 'btn-warning' : 'btn-default' }}" href="{{ route('operation.internal-messages.index',['status'=>'pending']) }}">待处理</a>
            <a class="btn btn-sm {{ $status === 'processed' ? 'btn-success' : 'btn-default' }}" href="{{ route('operation.internal-messages.index',['status'=>'processed']) }}">已处理</a>
            <a class="btn btn-sm {{ $status === 'all' ? 'btn-primary' : 'btn-default' }}" href="{{ route('operation.internal-messages.index',['status'=>'all']) }}">全部</a>
        </div>
    </div>

    @forelse($recipients as $recipient)
        @php($message = $recipient->message)
        <div class="box received-message {{ $recipient->processed_at ? 'processed' : 'pending' }}">
            <div class="received-message-head">
                <div><h3 class="received-title">{{ $message->title }}</h3><div class="received-meta">{{ $message->sender_portal_name }} · {{ $message->sender_name }} · {{ $message->published_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</div></div>
                <span class="received-status {{ $recipient->processed_at ? 'processed' : 'pending' }}">{{ $recipient->processed_at ? '已处理' : '待处理' }}</span>
            </div>
            @if($message->content)<div class="received-body">{{ $message->content }}</div>@endif
            @if($message->images->isNotEmpty())<div class="received-images">@foreach($message->images as $image)<img class="erp-zoomable" src="{{ route('internal-messages.images.show',[$message,$image]) }}" alt="{{ $image->original_name }}">@endforeach</div>@endif
            <div class="received-actions">
                @if($recipient->processed_at)<span class="received-processed-time"><i class="fa fa-check-circle"></i> 处理时间：{{ $recipient->processed_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span>
                @else<form method="post" action="{{ route('operation.internal-messages.process',$message) }}" onsubmit="return confirm('确认这条消息已经处理完成？')">@csrf<button class="btn btn-success btn-sm"><i class="fa fa-check"></i> 标记已处理</button></form>@endif
            </div>
        </div>
    @empty
        <div class="box received-empty"><i class="fa fa-inbox"></i>当前没有{{ $status === 'pending' ? '待处理' : ($status === 'processed' ? '已处理' : '') }}站内消息</div>
    @endforelse
    {{ $recipients->links() }}
</section>
@endsection
