<form method="post" action="{{ route('operation.ai-product-library.publish', $product) }}" class="operation-ai-publish-form">
    @csrf
    <div class="operation-ai-publish-scroll">
    <div class="operation-ai-store-list">
        <div class="operation-ai-store-heading">
            <h5><i class="fa fa-shopping-bag"></i> 当前可上架店铺 <span class="badge" data-publish-store-result-count>{{ $stores->count() }}</span></h5>
            @if($stores->count() > 6)
                <div class="operation-ai-store-search">
                    <input type="search" placeholder="搜索店铺名、域名、归属人" data-publish-store-search>
                    <button type="button" data-publish-store-search-button aria-label="搜索店铺"><i class="fa fa-search"></i><span>搜索</span></button>
                </div>
            @endif
        </div>
        <div class="operation-ai-store-options" data-publish-store-options>
        @forelse($stores as $store)
            <label class="operation-ai-store-card" data-publish-store-card data-search-text="{{ mb_strtolower(implode(' ', array_filter([$store->shop_name, $store->domain, $store->ownerUser?->name, $store->ownerUser?->username, $store->user_name]))) }}">
                <input type="radio" name="store_id" value="{{ $store->id }}" @checked($loop->first) required>
                <span>
                    <strong>{{ $store->shop_name }}</strong>
                    <small>域名：{{ $store->domain ?: '未填写' }}</small>
                    <small>归属人：{{ $store->ownerUser?->name ?: $store->ownerUser?->username ?: '未设置' }}</small>
                    <small>使用人：{{ $store->user_name ?: '未设置' }}</small>
                    <small>接口状态：{{ filled($store->shopify_client_id) && filled($store->shopify_client_secret) ? '已配置' : '未配置 Shopify 凭证' }}</small>
                </span>
            </label>
        @empty
            <div class="alert alert-warning">当前账号没有可用于上架的 Shopify 店铺。</div>
        @endforelse
        </div>
        <div class="operation-ai-store-empty" data-publish-store-empty hidden><i class="fa fa-search"></i> 没有匹配的店铺</div>
    </div>

    @if($productPages->isNotEmpty())
    <div class="operation-ai-product-page-picker">
        <label><i class="fa fa-file-text-o"></i> 选择产品页信息 <span class="text-red">*</span></label>
        <select class="form-control" name="product_page_id" data-product-page-select required>
            @foreach($productPages as $page)
                @php($pageData = $page->shopifyData())
                @php($pageFirstVariant = $pageData['variants'][0] ?? [])
                <option value="{{ $page->id }}"
                    data-title="{{ $pageData['title'] ?? $product->name }}"
                    data-status="{{ strtoupper((string) ($pageData['status'] ?? 'DRAFT')) === 'ACTIVE' ? 'ACTIVE' : 'DRAFT' }}"
                    data-sku="{{ $pageFirstVariant['sku'] ?? '' }}"
                    data-price="{{ $pageFirstVariant['price'] ?? 0 }}"
                    data-landing-page-url="{{ $page->landing_page_url }}">
                    {{ $page->name }}（{{ count($pageData['variants'] ?? []) }} 个规格 / {{ count($pageData['images'] ?? []) }} 张图片）{{ $page->is_default ? ' · 默认' : '' }}
                </option>
            @endforeach
        </select>
        <a class="operation-ai-product-page-link" href="{{ $productPages->first()?->landing_page_url ?: '#' }}" target="_blank" rel="noopener noreferrer"
            data-product-page-landing-link @if(!$productPages->first()?->landing_page_url) hidden @endif><i class="fa fa-external-link"></i> 查看该 CSV 对应落地页</a>
        <p class="help-block">该商品共有 {{ $productPages->count() }} 份产品页资料，上架时只同步当前选择的这一份。</p>
    </div>
    @endif

    <div class="row">
        <div class="col-sm-8 form-group">
            <label>Shopify 商品标题 <span class="text-red">*</span></label>
            <input class="form-control" name="title" value="{{ $shopifyData['title'] ?? $product->name }}" maxlength="255" required>
        </div>
        <div class="col-sm-4 form-group">
            <label>商品状态 <span class="text-red">*</span></label>
            <select class="form-control" name="status" required>
                <option value="DRAFT" @selected(($shopifyData['status'] ?? 'DRAFT') !== 'ACTIVE')>草稿</option>
                <option value="ACTIVE" @selected(($shopifyData['status'] ?? '') === 'ACTIVE')>启用</option>
            </select>
        </div>
    </div>
    @if($productPages->isNotEmpty())
        @php($firstVariant = $shopifyData['variants'][0] ?? [])
        <input type="hidden" name="sku" value="{{ $firstVariant['sku'] ?? '' }}" data-product-page-sku>
        <input type="hidden" name="price" value="{{ $firstVariant['price'] ?? 0 }}" data-product-page-price>
        @foreach($productPages as $page)
            @php($pageData = $page->shopifyData())
            <div data-product-page-panel="{{ $page->id }}" @if(!$loop->first) hidden @endif>
                <div class="alert alert-success operation-ai-product-page-summary">
                    <i class="fa fa-check-circle"></i>
                    将同步 <strong>{{ count($pageData['variants'] ?? []) }} 个规格</strong>、
                    <strong>{{ count($pageData['images'] ?? []) }} 张商品图</strong>、英文图文详情、品牌、商品类型、售价和库存。
                </div>
                <div class="table-responsive operation-ai-variant-preview">
                    <table class="table table-bordered table-condensed">
                        <thead><tr><th>款式</th><th>SKU</th><th>售价（USD）</th><th>库存</th></tr></thead>
                        <tbody>
                        @foreach($pageData['variants'] ?? [] as $variant)
                            <tr>
                                <td>{{ collect($variant['options'] ?? [])->pluck('value')->filter()->implode(' / ') ?: '默认款式' }}</td>
                                <td><code>{{ $variant['sku'] }}</code></td>
                                <td>${{ number_format((float) $variant['price'], 2) }}</td>
                                <td>{{ (int) ($variant['inventory_quantity'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @elseif(!empty($shopifyData['variants']))
        @php($firstVariant = $shopifyData['variants'][0])
        <input type="hidden" name="sku" value="{{ $firstVariant['sku'] }}">
        <input type="hidden" name="price" value="{{ $firstVariant['price'] }}">
        <div class="alert alert-success"><i class="fa fa-check-circle"></i> 已补充 Shopify 完整数据：将同步 <strong>{{ count($shopifyData['variants']) }} 个规格</strong>、<strong>{{ count($shopifyData['images'] ?? []) }} 张商品图</strong>、英文图文详情、品牌、商品类型、售价和库存。</div>
    @else
    <div class="row">
        <div class="col-sm-8 form-group">
            <label>上架 SKU <span class="text-red">*</span></label>
            <select class="form-control" name="sku" required>
                @forelse($skus as $sku)<option value="{{ $sku }}">{{ $sku }}</option>@empty<option value="">该产品未填写 SKU</option>@endforelse
            </select>
        </div>
        <div class="col-sm-4 form-group">
            <label>售价（USD）<span class="text-red">*</span></label>
            <input class="form-control" type="number" name="price" value="0.00" min="0" max="99999999" step="0.01" required>
        </div>
    </div>
    <p class="help-block">当前产品没有导入 Shopify 完整数据，将按基础单规格方式上架。</p>
    @endif
    </div>
    <div class="operation-ai-publish-actions">
        <span><i class="fa fa-info-circle"></i> 请选择店铺并确认商品信息</span>
        <div>
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-success" @disabled($stores->isEmpty() || $skus === [])>
            <i class="fa fa-cloud-upload"></i> 确认上架
        </button>
        </div>
    </div>
</form>
