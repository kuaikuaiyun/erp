@if($data['total'] > 0)
<div class="abnormal-pagination">
    <span class="text-muted">显示 {{ $data['from'] }}-{{ $data['to'] }} 条，共 {{ $data['total'] }} 条</span>
    <div>
        @php
            $baseQuery = request()->except([$pageKey, $otherPageKey]);
            $baseQuery[$otherPageKey] = $otherPage;
        @endphp
        @if($data['page'] > 1)<a class="btn btn-default btn-sm" href="{{ route('caigou.abnormal-skus.index', array_merge($baseQuery, [$pageKey=>$data['page']-1])) }}"><i class="fa fa-angle-left"></i> 上一页</a>@endif
        <span class="btn btn-primary btn-sm disabled">{{ $data['page'] }} / {{ $data['last_page'] }}</span>
        @if($data['page'] < $data['last_page'])<a class="btn btn-default btn-sm" href="{{ route('caigou.abnormal-skus.index', array_merge($baseQuery, [$pageKey=>$data['page']+1])) }}">下一页 <i class="fa fa-angle-right"></i></a>@endif
    </div>
</div>
@endif
