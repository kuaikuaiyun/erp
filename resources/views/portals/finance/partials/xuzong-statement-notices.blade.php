@if($xuzongReport && $xuzongStatementNotices->total() > 0)
    @include('portals.finance.partials.settlement-dialog-styles')
    @section('finance_header_actions')
        <button type="button" class="btn btn-default btn-sm pull-right" id="xuzong-notice-open"
            aria-haspopup="dialog" aria-controls="xuzong-notices">
            <i class="fa fa-bell" aria-hidden="true"></i>
            待处理结算变动 <span class="badge">{{ $xuzongStatementNotices->total() }}</span>
        </button>
    @endsection

    @push('scripts')
        <dialog id="xuzong-notices" class="settlement-dialog" aria-labelledby="xuzong-notices-title">
            <header>
                <h3 id="xuzong-notices-title">待处理结算变动</h3>
                <button class="btn btn-default btn-sm" type="button" onclick="this.closest('dialog').close()">关闭</button>
            </header>
            <div class="box-body">
                <table class="table">
                    <thead><tr><th>账户</th><th>平台流水号</th><th>操作</th></tr></thead>
                    <tbody>
                        @foreach($xuzongStatementNotices as $noticeOrder)
                            <tr>
                                <td>{{ $noticeOrder->account }}</td>
                                <td>{{ $noticeOrder->reference }}</td>
                                <td>
                                    <a class="btn btn-default btn-xs" data-xuzong-statement href="{{ route('finance.reports.xuzong-credit.statement', $noticeOrder->id) }}">查看结算明细</a>

                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @include('portals.finance.pagination', ['paginator' => $xuzongStatementNotices, 'pageSizeParameter' => 'statement_notice_per_page'])
            </div>
        </dialog>
        <script>
            (() => {
                const dialog = document.getElementById('xuzong-notices');
                document.getElementById('xuzong-notice-open').addEventListener('click', () => dialog.showModal());
                @if(request()->has('statement_notice_page'))
                    dialog.showModal();
                @endif
            })();
        </script>
    @endpush
@endif