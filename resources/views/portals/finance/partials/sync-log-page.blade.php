<div class="finance-table-wrap" tabindex="0" role="region" aria-label="同步记录表格">
    <table class="table finance-table">
        <thead><tr>
            <th scope="col">账户</th><th scope="col">操作</th><th scope="col">状态</th>
            <th scope="col">已保存页数 / 条数</th><th scope="col">结果说明</th><th scope="col">发起时间（北京时间）</th>
        </tr></thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) $log->account_name) }}</td>
                <td>{{ $log->operation === 'balances' ? '刷新余额' : '同步交易' }}</td>
                <td>{{ ['running' => '执行中', 'success' => '成功', 'failed' => '失败'][$log->status] ?? $log->status }}</td>
                <td>{{ $log->page_count }} / {{ $log->record_count }}</td>
                <td>{{ \App\Portals\Finance\FinanceCatalog::simplified($log->error_code ? __('finance.sync.errors.'.$log->error_code) : '—') }}</td>
                <td>{{ $log->created_at ? \Carbon\CarbonImmutable::parse($log->created_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">{{ $logs->total() ? '本页暂无同步记录' : '暂无同步记录' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if($logs->total())
    <nav class="finance-sync-log-pagination" aria-label="同步记录分页">
        <span>共 {{ $logs->total() }} 条 · 第 {{ $logs->currentPage() }} / {{ $logs->lastPage() }} 页</span>
        <div>
            @if($logs->onFirstPage())
                <button type="button" class="btn btn-default btn-sm" disabled>上一页</button>
            @else
                <a class="btn btn-default btn-sm" href="{{ $logs->url(min($logs->currentPage() - 1, $logs->lastPage())) }}" data-sync-log-page>上一页</a>
            @endif
            @if($logs->hasMorePages())
                <a class="btn btn-default btn-sm" href="{{ $logs->nextPageUrl() }}" data-sync-log-page>下一页</a>
            @else
                <button type="button" class="btn btn-default btn-sm" disabled>下一页</button>
            @endif
        </div>
    </nav>
@endif
