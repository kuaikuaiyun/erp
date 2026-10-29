@extends('portal.layout')

@section('page_title', '仓库异常SKU数据')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261021-2">
@endpush

@section('content')
    <section class="content-header">
        <h1>仓库异常SKU数据 <small>入录审核系统 · 仓库提报的规格 SKU 重量异常队列</small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">待整改</span>
                        <span class="info-box-number">{{ $pendingCount }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">已整改</span>
                        <span class="info-box-number">{{ $resolvedCount }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-body">
                <form id="caigou-warehouse-weight-filter" class="form-inline" method="get" action="{{ route('caigou.warehouse-weight-exceptions.index') }}">
                    <div class="form-group" style="margin-right:10px;">
                        <label style="margin-right:6px;">状态</label>
                        <select name="status" class="form-control input-sm">
                            <option value="">全部</option>
                            <option value="pending" @selected($filters['status'] === 'pending')>待整改</option>
                            <option value="resolved" @selected($filters['status'] === 'resolved')>已整改</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-right:10px;">
                        <label style="margin-right:6px;">搜索</label>
                        <input name="keyword" type="search" class="form-control input-sm" value="{{ $filters['keyword'] }}" placeholder="通途 SKU / Shopify SKU / 产品名 / 驳回理由 / 备注 / 提报人">
                    </div>
                    <div class="form-group" style="margin-right:10px;">
                        <label style="margin-right:6px;">每页</label>
                        <select name="per_page" class="form-control input-sm" onchange="this.form.submit()">
                            @foreach([10, 20, 50] as $size)
                                <option value="{{ $size }}" @selected((int) $filters['per_page'] === $size)>{{ $size }} 条</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-search"></i> 查询</button>
                    <a class="btn btn-default btn-sm" href="{{ route('caigou.warehouse-weight-exceptions.index') }}">重置</a>
                </form>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">异常队列 <small>共 {{ $pagination['total'] }} 条</small></h3>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th style="width:60px;">图片</th>
                        <th>通途 SKU / Shopify SKU</th>
                        <th>产品编号 / 产品名</th>
                        <th style="width:110px;">通途重量(g)</th>
                        <th style="width:110px;">实际重量(g)</th>
                        <th style="width:110px;">驳回理由</th>
                        <th style="min-width:220px;">备注</th>
                        <th style="width:80px;">实物图<small class="text-muted" style="display:block;font-weight:normal;font-size:11px;">点击放大</small></th>
                        <th style="min-width:110px;">仓库实拍图<small class="text-muted" style="display:block;font-weight:normal;font-size:11px;">点击放大</small></th>
                        <th style="width:80px;">状态</th>
                        <th style="width:110px;">提报人</th>
                        <th style="width:150px;">时间</th>
                        <th style="width:130px;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr data-id="{{ $row->id }}">
                            <td>
                                @if($row->product_image_url)
                                    <img src="{{ $row->product_image_url }}" class="product-statistics-thumb erp-zoomable" style="width:48px;height:48px;object-fit:cover;border:1px solid #eee;border-radius:3px;cursor:zoom-in;" alt="" title="点击放大查看" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <code>{{ $row->tongtu_sku }}</code>
                                @if($row->shopify_sku)
                                    <div class="text-muted" style="font-size:12px;">{{ $row->shopify_sku }}</div>
                                @endif
                            </td>
                            <td>
                                @if($row->tongtu_product_code)
                                    <code style="font-size:12px;">{{ $row->tongtu_product_code }}</code>
                                @endif
                                <div>{{ $row->tongtu_product_name ?: '—' }}</div>
                            </td>
                            <td class="text-right">
                                @if($row->original_weight_gram > 0)
                                    {{ number_format($row->original_weight_gram, 2, '.', '') }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($row->corrected_weight_gram > 0)
                                    <strong>{{ number_format($row->corrected_weight_gram, 2, '.', '') }}</strong>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($row->reject_reason)
                                    <span class="label label-danger">{{ \App\Models\WarehouseSkuWeightException::rejectReasonLabel($row->reject_reason) }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td style="min-width:220px;word-break:break-word;line-height:1.6;white-space:normal;">
                                @if($row->remark)
                                    <div>{{ \Illuminate\Support\Str::limit($row->remark, 60) }}</div>
                                @endif
                                @if($row->status === 'resolved' && $row->resolution_remark)
                                    <div class="text-muted" style="font-size:12px;"><i class="fa fa-check-circle-o"></i> 整改备注: {{ \Illuminate\Support\Str::limit($row->resolution_remark, 60) }}</div>
                                @endif
                                @if(!$row->remark && !($row->status === 'resolved' && $row->resolution_remark))
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($row->physical_image_path)
                                    <img src="{{ route('warehouse.weight-exceptions.physical-image', $row->id) }}" class="product-statistics-thumb erp-zoomable" style="width:48px;height:48px;object-fit:cover;border:1px solid #dfe6ed;border-radius:3px;cursor:zoom-in;" alt="" title="点击放大查看实物图" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @forelse((array) $row->warehouse_image_refs as $warehouseImage)
                                    <img src="{{ $warehouseImage['url'] }}" class="product-statistics-thumb erp-zoomable" style="width:48px;height:48px;object-fit:cover;border:1px solid #dfe6ed;border-radius:3px;cursor:zoom-in;margin:0 4px 4px 0;" alt="仓库实拍图" title="点击放大查看仓库实拍图" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td>
                                <button type="button" class="btn btn-info btn-xs warehouse-weight-sales-btn" data-id="{{ $row->id }}">
                                    <i class="fa fa-eye"></i> 查看出单
                                </button>
                                @if($row->status === 'pending')
                                    <span class="label label-warning">待整改</span>
                                @else
                                    <span class="label label-success">已整改</span>
                                @endif
                            </td>
                            <td>{{ $row->reporter_name ?: '—' }}</td>
                            <td>
                                <div>{{ $row->created_at?->format('Y-m-d H:i') }}</div>
                                @if($row->status === 'resolved' && $row->resolved_at)
                                    <div class="text-muted" style="font-size:12px;">已整改: {{ $row->resolved_at->format('m-d H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                @if($row->status === 'pending')
                                    <button type="button" class="btn btn-success btn-xs warehouse-weight-resolve-btn" data-id="{{ $row->id }}">
                                        <i class="fa fa-check"></i> 标记已整改
                                    </button>
                                @elseif(!$row->warehouse_review_approved)
                                    <button type="button" class="btn btn-success btn-xs warehouse-weight-approve-btn" data-id="{{ $row->id }}">
                                        <i class="fa fa-check"></i> 审核通过
                                    </button>
                                @else
                                    <span class="label label-success">已审核</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="text-center text-muted" style="padding:40px;">
                                <i class="fa fa-inbox fa-2x"></i>
                                <div style="margin-top:8px;">当前筛选条件下没有异常记录</div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($pagination['total'] > 0)
                @include('portals.partials.pagination', [
                    'p' => $pagination,
                    'routeName' => 'caigou.warehouse-weight-exceptions.index',
                    'queryParams' => request()->except(['page', 'per_page']),
                    'perPageOptions' => [10, 20, 50],
                ])
            @endif
        </div>
    </section>

    <div class="modal fade" id="warehouse-weight-resolve-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">标记异常已整改</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="warehouse-weight-resolve-id">
                    <label>整改备注（可选）</label>
                    <textarea id="warehouse-weight-resolve-remark" class="form-control" rows="3" maxlength="500" placeholder="简要说明处理方式或参考来源"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-success" id="warehouse-weight-resolve-confirm"><i class="fa fa-check"></i> 确认标记</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="warehouse-weight-sales-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-shopping-cart text-aqua"></i> SKU 出单店铺与运营人员</h4>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info" id="warehouse-weight-sales-summary" style="margin-bottom:12px;"></div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead><tr><th>产品名</th><th>店铺</th><th>店铺域名</th><th>运营人员</th><th class="text-right">订单数</th><th class="text-right">商品数量</th><th>最近下单时间</th></tr></thead>
                            <tbody id="warehouse-weight-sales-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var modal = $('#warehouse-weight-resolve-modal');
    var salesModal = $('#warehouse-weight-sales-modal');
    if (!modal.length) return;
    var resolveUrl = '{{ route("caigou.warehouse-weight-exceptions.index") }}';

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c);
        });
    }

    function openResolve(id) {
        $('#warehouse-weight-resolve-id').val(id);
        $('#warehouse-weight-resolve-remark').val('');
        modal.modal('show');
    }

    $('.warehouse-weight-resolve-btn').on('click', function () {
        openResolve($(this).data('id'));
    });

    $('.warehouse-weight-sales-btn').on('click', function () {
        var button = $(this);
        var id = button.data('id');
        var body = $('#warehouse-weight-sales-body');
        button.prop('disabled', true);
        $('#warehouse-weight-sales-summary').html('<i class="fa fa-spinner fa-spin"></i> 正在查询出单记录...');
        body.html('<tr><td colspan="7" class="text-center text-muted" style="padding:30px;">正在加载...</td></tr>');
        salesModal.modal('show');

        $.ajax({
            url: resolveUrl + '/' + id + '/sales-detail',
            method: 'GET',
            success: function (res) {
                var rows = (res && res.rows) || [];
                $('#warehouse-weight-sales-summary').html('<strong>SKU：</strong>' + escapeHtml(res.sku || '—') + '　<strong>产品名：</strong>' + escapeHtml(res.product_name || '未填写产品名') + '　<strong>店铺/运营组合：</strong>' + rows.length + ' 条');
                if (!rows.length) {
                    body.html('<tr><td colspan="7" class="text-center text-muted" style="padding:30px;"><i class="fa fa-inbox"></i> 暂未找到该 SKU 的出单记录</td></tr>');
                    return;
                }
                body.html(rows.map(function (row) {
                    var quantity = Number(row.product_quantity || 0);
                    return '<tr>'
                        + '<td>' + escapeHtml(row.product_name || '未填写产品名') + '</td>'
                        + '<td>' + escapeHtml(row.store_name || '未识别店铺') + '</td>'
                        + '<td>' + escapeHtml(row.shop_domain || '—') + '</td>'
                        + '<td><strong>' + escapeHtml(row.operator_name || '未分配运营') + '</strong></td>'
                        + '<td class="text-right">' + Number(row.order_count || 0) + '</td>'
                        + '<td class="text-right">' + quantity.toLocaleString(undefined, {maximumFractionDigits:4}) + '</td>'
                        + '<td>' + escapeHtml(row.latest_order_at || '—') + '</td>'
                        + '</tr>';
                }).join(''));
            },
            error: function (xhr) {
                $('#warehouse-weight-sales-summary').text('查询失败');
                body.html('<tr><td colspan="7" class="text-center text-danger" style="padding:30px;">' + escapeHtml((xhr.responseJSON && xhr.responseJSON.message) || '无法加载出单记录') + '</td></tr>');
            },
            complete: function () { button.prop('disabled', false); }
        });
    });

    function postAction(id, action, button, confirmText) {
        if (confirmText && !confirm(confirmText)) return;
        var token = $('meta[name="csrf-token"]').attr('content') || '';
        button.prop('disabled', true);
        $.ajax({
            url: resolveUrl + '/' + id + '/' + action,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token },
            success: function (res) {
                alert((res && res.message) || '操作成功');
                location.reload();
            },
            error: function (xhr) {
                button.prop('disabled', false);
                alert((xhr.responseJSON && xhr.responseJSON.message) || '操作失败');
            }
        });
    }

    $('.warehouse-weight-approve-btn').on('click', function () {
        postAction($(this).data('id'), 'approve', $(this), '确认审核通过？');
    });

    $('#warehouse-weight-resolve-confirm').on('click', function () {
        var id = $('#warehouse-weight-resolve-id').val();
        var remark = $('#warehouse-weight-resolve-remark').val();
        var token = $('meta[name="csrf-token"]').attr('content') || '';
        var btn = $(this).prop('disabled', true);
        $.ajax({
            url: '{{ route("caigou.warehouse-weight-exceptions.index") }}/' + id + '/resolve',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token },
            data: { resolution_remark: remark },
            success: function (res) {
                btn.prop('disabled', false);
                if (res && res.ok) {
                    modal.modal('hide');
                    location.reload();
                } else {
                    alert((res && res.message) || '标记失败');
                }
            },
            error: function (xhr) {
                btn.prop('disabled', false);
                var msg = '标记失败';
                try { msg = (xhr.responseJSON && xhr.responseJSON.message) || msg; } catch (e) {}
                alert(msg);
            }
        });
    });
})();
</script>
@endpush
