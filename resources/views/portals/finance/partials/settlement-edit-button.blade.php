<button class="btn btn-default btn-xs" type="button" data-edit-settlement
    data-tongtu-display="{{ \App\Portals\Finance\FinanceCatalog::displayTongtuOrder($editable['erp_order'] ?? '') }}"
    data-row="{{ json_encode(array_intersect_key($orderView ? $editable : array_replace($editable, $editable['card_layout'] ?? []), array_flip(['id', 'reference', 'currency', 'timezone', ...array_keys($moneyFields), ...array_keys($textFields), ...array_keys($editorFields)])), JSON_UNESCAPED_UNICODE) }}">编辑</button>
