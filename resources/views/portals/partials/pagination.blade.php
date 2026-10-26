@php
    /**
     * 通用分页 partial —— 接受手动拼接的 pagination 数组
     *
     * @param array $p {total:int, page:int, per_page:int, last_page:int, from:int, to:int}
     * @param string $routeName 用于 URL 生成的 route() 名称
     * @param array $queryParams 除 page/per_page 外需要保留的 query 参数
     * @param array $perPageOptions 每页可选项，默认 [10, 20, 50]
     * @param bool $showPerPage 是否显示 per_page 选择器，默认 true
     */
    $total = (int) ($p['total'] ?? 0);
    $current = max(1, (int) ($p['page'] ?? $p['current_page'] ?? 1));
    $perPage = max(1, (int) ($p['per_page'] ?? 10));
    $last = max(1, (int) ($p['last_page'] ?? 1));
    $from = (int) ($p['from'] ?? 0);
    $to = (int) ($p['to'] ?? 0);

    $routeName = $routeName ?? request()->route()?->getName();
    $queryParams = $queryParams ?? request()->except(['page', 'per_page']);
    $perPageOptions = $perPageOptions ?? [10, 20, 50];
    $showPerPage = $showPerPage ?? true;

    // 构造页码可见序列：1, 当前-2, 当前-1, 当前, 当前+1, 当前+2, last —— 去重排序
    $pages = collect([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $last])
        ->filter(fn ($n) => $n >= 1 && $n <= $last)
        ->unique()
        ->sort()
        ->values();

    // 页码链接必须带上当前 per_page，否则从 10 条切到下一页时
    // 会丢失该参数、回落到默认每页条数。
    $buildUrl = function (int $page, ?int $pp = null) use ($routeName, $queryParams, $perPage): string {
        $params = array_merge($queryParams, ['page' => $page, 'per_page' => $pp ?? $perPage]);
        return route($routeName, $params);
    };
    // per_page 选择器需要不带 per_page 的基础 URL，避免出现重复参数。
    $buildUrlWithoutPerPage = function (int $page) use ($routeName, $queryParams): string {
        return route($routeName, array_merge($queryParams, ['page' => $page]));
    };
@endphp

<div class="erp-pagination-box" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 14px;border-top:1px solid #f0f0f0;background:#fafbfc;">
    {{-- 左侧：总数说明 + 每页条数 --}}
    <div class="erp-pagination-info" style="display:flex;align-items:center;gap:12px;font-size:13px;color:#666;">
        <span>
            @if ($total === 0)
                暂无数据
            @else
                显示 <strong style="color:#333;">{{ $from }}</strong>–<strong style="color:#333;">{{ $to }}</strong> 条，共 <strong style="color:#3c8dbc;">{{ $total }}</strong> 条
            @endif
        </span>
        @if ($showPerPage && $total > max($perPageOptions))
            <span style="display:inline-flex;align-items:center;gap:4px;">
                每页
                <select class="form-control input-sm erp-pagination-perpage" style="width:auto;height:28px;padding:2px 6px;font-size:12px;" onchange="this.form && this.form.submit ? null : (function(url){ var sep = url.indexOf('?')>=0 ? '&' : '?'; location.href = url + sep + 'per_page=' + this.value; }.call(this, '{{ $buildUrlWithoutPerPage($current) }}'))">
                    @foreach ($perPageOptions as $opt)
                        <option value="{{ $opt }}" @selected($opt === $perPage)>{{ $opt }}</option>
                    @endforeach
                </select>
                条
            </span>
        @endif
    </div>

    {{-- 右侧：页码导航 --}}
    @if ($last > 1)
        <nav aria-label="分页导航">
            <ul class="pagination pagination-sm no-margin" style="margin:0;">
                {{-- 首页 --}}
                <li class="{{ $current <= 1 ? 'disabled' : '' }}">
                    <a href="{{ $current > 1 ? $buildUrl(1) : '#' }}" aria-label="首页" title="首页">«</a>
                </li>
                {{-- 上一页 --}}
                <li class="{{ $current <= 1 ? 'disabled' : '' }}">
                    <a href="{{ $current > 1 ? $buildUrl($current - 1) : '#' }}" rel="prev" aria-label="上一页" title="上一页">‹</a>
                </li>

                {{-- 页码列表（省略号折叠） --}}
                @php ($prev = null)
                @foreach ($pages as $page)
                    @if ($prev !== null && $page - $prev > 1)
                        <li class="disabled"><span>…</span></li>
                    @endif
                    <li class="{{ $page === $current ? 'active' : '' }}">
                        <a href="{{ $buildUrl($page) }}">{{ $page }}</a>
                    </li>
                    @php ($prev = $page)
                @endforeach

                {{-- 下一页 --}}
                <li class="{{ $current >= $last ? 'disabled' : '' }}">
                    <a href="{{ $current < $last ? $buildUrl($current + 1) : '#' }}" rel="next" aria-label="下一页" title="下一页">›</a>
                </li>
                {{-- 末页 --}}
                <li class="{{ $current >= $last ? 'disabled' : '' }}">
                    <a href="{{ $current < $last ? $buildUrl($last) : '#' }}" aria-label="末页" title="末页">»</a>
                </li>
            </ul>
        </nav>
    @endif
</div>

<style>
    .erp-pagination-box .pagination > li > a,
    .erp-pagination-box .pagination > li > span {
        border-radius: 2px !important;
        margin: 0 2px;
        min-width: 32px;
        text-align: center;
        font-size: 12px;
        padding: 4px 8px;
    }
    .erp-pagination-box .pagination > li.active > a,
    .erp-pagination-box .pagination > li.active > span {
        background-color: #3c8dbc;
        border-color: #3c8dbc;
        color: #fff;
    }
    .erp-pagination-box .pagination > li.disabled > a,
    .erp-pagination-box .pagination > li.disabled > span {
        color: #aaa;
        cursor: not-allowed;
    }
</style>
