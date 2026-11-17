<button class="btn btn-default btn-xs" type="button" data-edit-settlement
    data-tongtu-display="{{ \App\Portals\Finance\FinanceCatalog::displayTongtuOrder($editable['erp_order'] ?? '') }}"
    data-row="{{ json_encode(array_intersect_key($editable, array_flip(['id', 'reference', 'currency', ...array_keys($moneyFields), ...array_keys($textFields), ...array_keys($editorFields)])), JSON_UNESCAPED_UNICODE) }}">编辑</button>
