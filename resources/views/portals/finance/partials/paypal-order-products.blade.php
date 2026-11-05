                        @foreach($items as $item)
                            @php
                                $weight = static fn ($value) => is_numeric($value) ? (float) $value.' 克' : '';
                                $goodsWeight = $item->sale_weight_gram ?: $item->tongtu_goods_weight_gram;
                                $productName = $item->tongtu_product_name ?: $item->product_title;
                            @endphp
                            <div class="logistics-sale-product-detail-item">
                                <div @class(['logistics-sale-product-detail-code', 'is-empty' => blank($item->tongtu_product_code)])><b>PCL:</b><span title="{{ $item->tongtu_product_code }}">{{ $item->tongtu_product_code ?: '' }}</span></div>
                                <div class="logistics-sale-product-detail-skus">
                                    <span class="logistics-sale-product-detail-sku is-original"><b>原始 SKU:</b><span title="{{ $item->shopify_sku }}">{{ $item->shopify_sku ?: '' }}</span></span>
                                    <span @class(['logistics-sale-product-detail-sku', 'is-matched' => filled($item->tongtu_sku), 'is-empty' => blank($item->tongtu_sku)])><b>通途 SKU:</b><span title="{{ $item->tongtu_sku }}">{{ $item->tongtu_sku ?: '' }}</span></span>
                                </div>
                                <div class="logistics-sale-product-detail-metrics">
                                    <span><b>数量:</b>{{ $item->quantity === null ? '' : (float) $item->quantity }}</span>
                                    <span><b>商品重量:</b>{{ $weight($goodsWeight) }}</span>
                                    <span><b>包裹重量:</b>{{ $weight($item->tongtu_package_weight_gram) }}</span>
                                </div>
                                <div class="logistics-sale-product-detail-name"><b>配货:</b><span title="{{ $productName }}">{{ $productName ?: '' }}</span></div>
                            </div>
                        @endforeach
