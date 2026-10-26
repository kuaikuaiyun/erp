<section class="product-request-detail-modal">
    <header class="modal-header product-request-detail-modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
        <div class="product-request-detail-modal-heading">
            <h4 class="modal-title"><i class="fa fa-database"></i> 产品详情</h4>
            <p>只读视图，不可编辑</p>
        </div>
        <div class="product-request-detail-modal-status">
            <span class="product-request-badge link-source-{{ $linkSku->source ?: 'system' }}">{{ ($linkSku->source ?: 'system') === 'system' ? '系统' : '导入' }}</span>
            <span class="product-request-badge link-review-{{ $linkSku->review_status === 'pending' ? 'pending' : 'done' }}">{{ $linkSku->review_status === 'pending' ? '待录入' : '已录入' }}</span>
        </div>
    </header>

    <div class="modal-body product-request-detail-modal-body">
        {{-- 基础信息 --}}
        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5><i class="fa fa-info-circle"></i> 基础信息</h5></header>
            <div class="product-request-detail-section-body product-request-detail-modal-overview">
                <div class="product-request-detail-modal-image-panel">
                    <span class="product-request-detail-label">商品主图</span>
                    <div class="product-request-image-grid">
                        @if($linkSku->product_image)
                            <a href="{{ route('product-requests.link-skus.product-image', $linkSku) }}" data-product-request-image-preview data-product-request-image-alt="商品主图"><img loading="lazy" decoding="async" src="{{ route('product-requests.link-skus.product-image', $linkSku) }}" alt="商品主图"></a>
                        @else
                            <span class="text-muted" style="font-size:12px;">暂无图片</span>
                        @endif
                    </div>
                </div>
                <div class="product-request-detail-modal-grid">
                    <div class="product-request-detail-item product-request-detail-item-span-2">
                        <span class="product-request-detail-label">1688 链接</span>
                        <span class="product-request-detail-value">@if($linkSku->supplier_url)<a href="{{ $linkSku->supplier_url }}" target="_blank" rel="noopener">{{ $linkSku->supplier_url }}</a>@else<span class="text-muted">未填写</span>@endif</span>
                    </div>
                    <div class="product-request-detail-item">
                        <span class="product-request-detail-label">商品名称</span>
                        <span class="product-request-detail-value">{{ $productName ?: '<span class="text-muted">未填写</span>' }}</span>
                    </div>
                    <div class="product-request-detail-item">
                        <span class="product-request-detail-label">PCL 编号</span>
                        <span class="product-request-detail-value">{{ $pcl ?: '<span class="text-muted">未填写</span>' }}</span>
                    </div>
                    <div class="product-request-detail-item">
                        <span class="product-request-detail-label">更新时间</span>
                        <span class="product-request-detail-value">{{ optional($linkSku->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- SKU 信息 --}}
        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5><i class="fa fa-list-ul"></i> SKU 信息</h5></header>
            <div class="product-request-detail-section-body">
                <div class="product-request-detail-modal-grid" style="grid-template-columns: 1fr;">
                    <div class="product-request-detail-item">
                        <span class="product-request-detail-label">SKU 列表</span>
                        <span class="product-request-detail-value">
                            @if($skus !== [])
                                <span class="product-request-sku-tags">@foreach($skus as $sku)<span class="product-request-sku-tag">{{ $sku }}</span>@endforeach</span>
                            @else
                                <span class="text-muted">暂无 SKU</span>
                            @endif
                        </span>
                    </div>
                </div>
                @if($skus !== [])
                <h6 class="product-request-detail-subtitle">采购价 / 重量</h6>
                <div class="link-sku-value-table">
                    <div class="link-sku-value-row link-sku-value-head">
                        <span class="link-sku-value-cell link-sku-value-cell-sku">SKU</span>
                        <span class="link-sku-value-cell link-sku-value-cell-price">采购价</span>
                        <span class="link-sku-value-cell link-sku-value-cell-weight">重量</span>
                    </div>
                    @foreach($skus as $sku)
                        @php($sv = $skuValues[$sku] ?? [])
                        <div class="link-sku-value-row">
                            <span class="link-sku-value-cell link-sku-value-cell-sku"><span class="product-request-sku-tag">{{ $sku }}</span></span>
                            <span class="link-sku-value-cell link-sku-value-cell-price">{{ $sv['price'] ?? '—' }}</span>
                            <span class="link-sku-value-cell link-sku-value-cell-weight">{{ $sv['weight'] ?? '—' }}</span>
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
        </section>

        {{-- 实拍图 --}}
        @php($existingImages = array_values((array) ($linkSku->images ?? [])))
        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5><i class="fa fa-camera"></i> 实拍图</h5></header>
            <div class="product-request-detail-section-body">
                <div class="product-request-detail-modal-media">
                    @if($existingImages !== [])
                    <div class="product-request-image-grid">
                        @foreach($existingImages as $imageIndex => $imagePath)
                            <a href="{{ route('product-requests.link-skus.image', [$linkSku, $imageIndex]) }}" data-product-request-image-preview data-product-request-image-alt="实拍图">
                                <img loading="lazy" decoding="async" src="{{ route('product-requests.link-skus.image', [$linkSku, $imageIndex]) }}" alt="实拍图">
                            </a>
                        @endforeach
                    </div>
                    @else
                    <p class="text-muted" style="font-size:13px;margin:0;">暂无实拍图</p>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <footer class="modal-footer product-request-detail-modal-footer">
        <button class="btn btn-default" type="button" data-dismiss="modal"><i class="fa fa-times"></i> 关闭</button>
    </footer>
</section>
<style>
    .link-sku-value-table { border: 1px solid #e1e9f0; border-radius: 5px; overflow: hidden; margin-top: 8px; }
    .link-sku-value-row { display: flex; align-items: stretch; }
    .link-sku-value-row + .link-sku-value-row { border-top: 1px solid #e1e9f0; }
    .link-sku-value-head { background: #f4f8fc; font-weight: 600; color: #5a7188; font-size: 12px; }
    .link-sku-value-cell { padding: 8px 12px; display: flex; align-items: center; font-size: 13px; }
    .link-sku-value-cell-sku { flex: 1 1 auto; min-width: 0; }
    .link-sku-value-cell-price, .link-sku-value-cell-weight { flex: 0 0 100px; justify-content: center; color: #334e67; }
    .link-sku-value-head .link-sku-value-cell-price, .link-sku-value-head .link-sku-value-cell-weight { color: #6b8194; }
</style>
