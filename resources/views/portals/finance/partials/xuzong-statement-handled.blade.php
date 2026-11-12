<form method="post" data-loading="off" action="{{ route('finance.reports.xuzong-credit.statement.update', $noticeOrder->id) }}" style="display:inline-block">
    @csrf
    <input type="hidden" name="action" value="handled">
    <input type="hidden" name="notice" value="{{ $noticeOrder->payload['_xuzong_statement_import']['notice'] }}">
    <button class="btn btn-default btn-xs" type="submit">已处理</button>
</form>