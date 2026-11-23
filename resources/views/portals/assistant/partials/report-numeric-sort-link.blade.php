@php
    $isActiveSort = $sortBy === $sortKey;
    $nextDirection = $isActiveSort && $sortDirection === 'desc' ? 'asc' : 'desc';
@endphp
<a class="report-sort-link {{ $isActiveSort ? 'active' : '' }}"
   href="{{ request()->fullUrlWithQuery(['sort_by' => $sortKey, 'sort_direction' => $nextDirection, 'page' => 1]) }}"
   title="按{{ $label }}{{ $nextDirection === 'desc' ? '从高到低' : '从低到高' }}排序">
    <span>{{ $label }}</span>
    <span class="report-sort-icon"><i class="fa {{ $isActiveSort ? ($sortDirection === 'desc' ? 'fa-sort-amount-desc' : 'fa-sort-amount-asc') : 'fa-sort' }}"></i></span>
</a>
