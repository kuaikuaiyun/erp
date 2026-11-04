<form method="post" action="{{ route('operation.ai-product-library.publish', $product) }}" class="operation-ai-publish-form">
    @csrf
    <div class="alert alert-info">
        <i class="fa fa-info-circle"></i>
        上架前请确认当前账号归属的 Shopify 店铺。测试阶段管理员仅开放 <strong>161-sysya8-6t</strong>。
    </div>

    <div class="operation-ai-store-list">
        <h5><i class="fa fa-shopping-bag"></i> 当前可上架店铺</h5>
        @forelse($stores as $store)
            <label class="operation-ai-store-card">
                <input type="radio" name="store_id" value="{{ $store->id }}" @checked($loop->first) required>
                <span>
                    <strong>{{ $store->shop_name }}</strong>
                    <small>域名：{{ $store->domain ?: '未填写' }}</small>
                    <small>归属人：{{ $store->ownerUser?->name ?: $store->ownerUser?->username ?: '管理员测试店铺' }}</small>
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
            <input class="form-control" name="title" value="{{ $product->name }}" maxlength="255" required>
        </div>
        <div class="col-sm-4 form-group">
            <label>商品状态 <span class="text-red">*</span></label>
            <select class="form-control" name="status" required>
                <option value="DRAFT">草稿</option>
                <option value="ACTIVE">启用</option>
            </select>
        </div>
    </div>
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
    <p class="help-block">将同步商品标题、所选 SKU、售价、分类标签和产品图片。建议首次测试选择“草稿”。</p>
    <div class="text-right">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-success" @disabled($stores->isEmpty() || $skus === [])>
            <i class="fa fa-cloud-upload"></i> 确认上架
        </button>
    </div>
</form>
