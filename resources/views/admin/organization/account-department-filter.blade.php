@if (($mode ?? '') === 'style')
.department-tree-filter {
    position: relative;
    width: 100%;
}
.department-tree-filter__trigger {
    align-items: center;
    background: #fff;
    border: 1px solid #d2d6de;
    border-radius: 3px;
    color: #555;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    min-height: 34px;
    padding: 6px 11px;
    text-align: left;
    width: 100%;
}
.department-tree-filter__trigger:hover,
.department-tree-filter.is-open .department-tree-filter__trigger {
    border-color: #3c8dbc;
}
.department-tree-filter__trigger-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.department-tree-filter__arrow {
    color: #777;
    margin-left: 12px;
    transition: transform .18s ease;
}
.department-tree-filter.is-open .department-tree-filter__arrow {
    transform: rotate(180deg);
}
.department-tree-filter__panel {
    background: #fff;
    border: 1px solid #d2d6de;
    border-radius: 3px;
    box-shadow: 0 6px 16px rgba(0, 0, 0, .14);
    display: none;
    left: 0;
    margin-top: 4px;
    min-width: 320px;
    position: absolute;
    right: 0;
    z-index: 1051;
}
.department-tree-filter.is-open .department-tree-filter__panel {
    display: block;
}
.department-tree-filter__toolbar {
    border-bottom: 1px solid #edf0f2;
    padding: 10px;
}
.department-tree-filter__search {
    border: 1px solid #d2d6de;
    border-radius: 3px;
    height: 34px;
    outline: none;
    padding: 6px 10px;
    width: 100%;
}
.department-tree-filter__search:focus {
    border-color: #3c8dbc;
}
.department-tree-filter__tree {
    list-style: none;
    margin: 0;
    max-height: 320px;
    overflow: auto;
    padding: 7px 0;
}
.department-tree-filter__node {
    list-style: none;
    margin: 0;
    padding: 0;
}
.department-tree-filter__row {
    align-items: center;
    display: flex;
    min-height: 32px;
    padding: 3px 10px;
}
.department-tree-filter__row:hover {
    background: #f3f8fb;
}
.department-tree-filter__toggle,
.department-tree-filter__spacer {
    align-items: center;
    background: transparent;
    border: 0;
    color: #687782;
    display: inline-flex;
    flex: 0 0 22px;
    height: 22px;
    justify-content: center;
    padding: 0;
}
.department-tree-filter__toggle:focus {
    outline: none;
}
.department-tree-filter__toggle-icon {
    font-size: 11px;
    transition: transform .15s ease;
}
.department-tree-filter__node.is-collapsed > .department-tree-filter__row .department-tree-filter__toggle-icon {
    transform: rotate(-90deg);
}
.department-tree-filter__node.is-collapsed > .department-tree-filter__children {
    display: none;
}
.department-tree-filter__label {
    align-items: center;
    cursor: pointer;
    display: flex;
    flex: 1;
    font-weight: 400;
    margin: 0;
    min-width: 0;
}
.department-tree-filter__checkbox {
    margin: 0 7px 0 0 !important;
}
.department-tree-filter__folder {
    color: #d5a026;
    margin-right: 6px;
}
.department-tree-filter__name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.department-tree-filter__children {
    list-style: none;
    margin: 0;
    padding: 0 0 0 20px;
}
.department-tree-filter__footer {
    align-items: center;
    border-top: 1px solid #edf0f2;
    display: flex;
    justify-content: flex-end;
    min-height: 38px;
    padding: 6px 10px;
}
.department-tree-filter__clear {
    background: transparent;
    border: 0;
    color: #3c8dbc;
    padding: 3px 5px;
}
.department-tree-filter__empty {
    color: #999;
    display: none;
    padding: 22px 12px;
    text-align: center;
}
@else
(function ($) {
    var departments = @json($departments ?? []);
    var texts = @json($texts ?? []);

    function initialiseDepartmentTreeFilter() {
        var $select = $('select[name="department_ids[]"]');
        if (!$select.length || $select.data('department-tree-ready')) {
            return;
        }

        $select.data('department-tree-ready', true);
        var selectedIds = ($select.val() || []).map(String);
        var selected = {};
        selectedIds.forEach(function (id) { selected[id] = true; });

        var byParent = {};
        departments.forEach(function (department) {
            var parent = department.parent_id === null ? 'root' : String(department.parent_id);
            byParent[parent] = byParent[parent] || [];
            byParent[parent].push(department);
        });

        var $widget = $('<div class="department-tree-filter"></div>');
        var $trigger = $('<button type="button" class="department-tree-filter__trigger" aria-expanded="false"></button>');
        var $triggerText = $('<span class="department-tree-filter__trigger-text"></span>');
        $trigger.append($triggerText, '<i class="fa fa-angle-down department-tree-filter__arrow" aria-hidden="true"></i>');

        var $panel = $('<div class="department-tree-filter__panel"></div>');
        var $search = $('<input type="text" class="department-tree-filter__search">').attr('placeholder', texts.search || '');
        var $tree = $('<ul class="department-tree-filter__tree"></ul>');
        var $empty = $('<div class="department-tree-filter__empty"></div>').text(texts.empty || '');
        var $clear = $('<button type="button" class="department-tree-filter__clear"></button>').text(texts.clear || '');
        $panel.append($('<div class="department-tree-filter__toolbar"></div>').append($search), $tree, $empty, $('<div class="department-tree-filter__footer"></div>').append($clear));
        $widget.append($trigger, $panel);

        function renderNodes(parentId, $container) {
            (byParent[parentId] || []).forEach(function (department) {
                var id = String(department.id);
                var children = byParent[id] || [];
                var $node = $('<li class="department-tree-filter__node"></li>').attr('data-id', id).attr('data-name', String(department.name || '').toLowerCase());
                var $row = $('<div class="department-tree-filter__row"></div>');
                if (children.length) {
                    $row.append('<button type="button" class="department-tree-filter__toggle" aria-expanded="true"><i class="fa fa-caret-down department-tree-filter__toggle-icon"></i></button>');
                } else {
                    $row.append('<span class="department-tree-filter__spacer"></span>');
                }

                var $checkbox = $('<input type="checkbox" class="department-tree-filter__checkbox">').val(id).prop('checked', !!selected[id]);
                var $label = $('<label class="department-tree-filter__label"></label>');
                $label.append($checkbox, '<i class="fa fa-folder department-tree-filter__folder" aria-hidden="true"></i>', $('<span class="department-tree-filter__name"></span>').text(department.name));
                $row.append($label);
                $node.append($row);

                if (children.length) {
                    var $children = $('<ul class="department-tree-filter__children"></ul>');
                    renderNodes(id, $children);
                    $node.append($children);
                }
                $container.append($node);
            });
        }

        function updateSelection() {
            var values = [];
            $tree.find('.department-tree-filter__checkbox:checked').each(function () { values.push(String(this.value)); });
            $select.val(values).trigger('change');
            $triggerText.text(values.length ? String(texts.selected || '').replace(':count', values.length) : (texts.all || ''));
        }

        renderNodes('root', $tree);
        updateSelection();
        $select.next('.select2').hide();
        $select.after($widget);

        $trigger.on('click', function () {
            var opening = !$widget.hasClass('is-open');
            $('.department-tree-filter.is-open').removeClass('is-open').find('.department-tree-filter__trigger').attr('aria-expanded', 'false');
            $widget.toggleClass('is-open', opening);
            $trigger.attr('aria-expanded', opening ? 'true' : 'false');
            if (opening) { window.setTimeout(function () { $search.trigger('focus'); }, 0); }
        });
        $tree.on('click', '.department-tree-filter__toggle', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var $node = $(this).closest('.department-tree-filter__node');
            $node.toggleClass('is-collapsed');
            $(this).attr('aria-expanded', $node.hasClass('is-collapsed') ? 'false' : 'true');
        });
        $tree.on('change', '.department-tree-filter__checkbox', updateSelection);
        $clear.on('click', function () {
            $tree.find('.department-tree-filter__checkbox').prop('checked', false);
            updateSelection();
        });
        $search.on('input', function () {
            var keyword = $.trim(String($(this).val() || '')).toLowerCase();
            var visibleCount = 0;
            $($tree.find('.department-tree-filter__node').get().reverse()).each(function () {
                var $node = $(this);
                var selfMatches = !keyword || String($node.data('name') || '').indexOf(keyword) !== -1;
                var childMatches = keyword && $node.find('> .department-tree-filter__children > .department-tree-filter__node').filter(function () {
                    return $(this).data('filter-visible') === true;
                }).length > 0;
                var isVisible = selfMatches || childMatches;
                $node.data('filter-visible', isVisible).toggle(isVisible);
                if (isVisible) { visibleCount++; }
                if (keyword && childMatches) { $node.removeClass('is-collapsed'); }
            });
            $empty.toggle(visibleCount === 0);
            $tree.toggle(visibleCount !== 0);
        });
        $(document).off('click.departmentTreeFilter').on('click.departmentTreeFilter', function (event) {
            if (!$(event.target).closest('.department-tree-filter').length) {
                $widget.removeClass('is-open');
                $trigger.attr('aria-expanded', 'false');
            }
        });
        $select.closest('form').on('reset.departmentTreeFilter', function () {
            window.setTimeout(function () {
                $tree.find('.department-tree-filter__checkbox').prop('checked', false);
                $search.val('').trigger('input');
                updateSelection();
            }, 0);
        });
    }

    $(initialiseDepartmentTreeFilter);
})(jQuery);
@endif
