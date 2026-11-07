<div data-settlement-condition class="settlement-condition-row">
    <select class="form-control" data-condition-field required aria-label="筛选字段">
        <option value="">请选择筛选字段</option>
        @foreach($conditionFields as $field => $label)
            <option value="{{ $field }}" @selected(($condition['field'] ?? '') === $field)>{{ $label }}</option>
        @endforeach
    </select>
    @if($enhancedConditions)
        <select class="form-control" data-condition-operator aria-label="比较方式">
            @foreach(['equals' => '等于', 'contains' => '包含', 'not_equals' => '不等于', 'empty' => '为空', 'not_empty' => '不为空',
                'gt' => '大于', 'gte' => '大于等于', 'lt' => '小于', 'lte' => '小于等于'] as $operator => $label)
                <option value="{{ $operator }}" @selected(($condition['operator'] ?? 'equals') === $operator)>{{ $label }}</option>
            @endforeach
        </select>
    @else
        <input type="hidden" data-condition-operator value="equals">
    @endif
    <input class="form-control" data-condition-value value="{{ $condition['value'] ?? '' }}" maxlength="191" aria-label="筛选值">
    <button class="btn btn-default" type="button" data-remove-condition>移除</button>
</div>
