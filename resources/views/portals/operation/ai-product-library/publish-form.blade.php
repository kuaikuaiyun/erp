<form method="post" action="{{ route('operation.ai-product-library.publish', $product) }}" class="operation-ai-publish-form">
    @csrf
    <div class="operation-ai-store-list">
        <h5><i class="fa fa-shopping-bag"></i> 当前可上架店铺</h5>
        @forelse($stores as $store)
            <label class="operation-ai-store-card">
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
    @if(!empty($shopifyData['variants']))
        @php($firstVariant = $shopifyData['variants'][0])
        <input type="hidden" name="sku" value="{{ $firstVariant['sku'] }}">
        <input type="hidden" name="price" value="{{ $firstVariant['price'] }}">
        <div class="alert alert-success">
            <i class="fa fa-check-circle"></i>
            已补充 Shopify 完整数据：将同步 <strong>{{ count($shopifyData['variants']) }} 个规格</strong>、
            <strong>{{ count($shopifyData['images'] ?? []) }} 张商品图</strong>、英文图文详情、品牌、商品类型、售价和库存。
        </div>
        <div class="table-responsive operation-ai-variant-preview">
            <table class="table table-bordered table-condensed">
                <thead><tr><th>款式</th><th>SKU</th><th>售价（USD）</th><th>库存</th></tr></thead>
                <tbody>
                @foreach($shopifyData['variants'] as $variant)
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
    <div class="text-right">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-success" @disabled($stores->isEmpty() || $skus === [])>
            <i class="fa fa-cloud-upload"></i> 确认上架
        </button>
    </div>
</form>
