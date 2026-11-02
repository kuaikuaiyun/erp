@if (($mode ?? '') === 'style')
.department-tree-filter {
    background: #fff;
    border: 1px solid #d2d6de;
    border-radius: 3px;
    overflow: hidden;
    width: 100%;
}
.department-tree-filter__summary {
    align-items: center;
    background: #fff;
    border: 0;
    color: #555;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    min-height: 34px;
    padding: 7px 11px;
    text-align: left;
    width: 100%;
}
.department-tree-filter__summary:hover,
.department-tree-filter.is-open .department-tree-filter__summary {
    background: #f7fbfd;
    color: #2f7fa9;
}
.department-tree-filter__summary-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.department-tree-filter__summary-arrow {
    color: #7d8990;
    margin-left: 10px;
    transition: transform .18s ease;
}
.department-tree-filter.is-open .department-tree-filter__summary-arrow {
    transform: rotate(180deg);
}
.department-tree-filter__body {
    border-top: 1px solid #e5e9ec;
    display: none;
}
.department-tree-filter.is-open .department-tree-filter__body {
    display: block;
}
.department-tree-filter__toolbar {
    background: #fafbfc;
    border-bottom: 1px solid #e5e9ec;
    padding: 9px 10px;
}
.department-tree-filter__search-wrap {
    position: relative;
}
.department-tree-filter__search-icon {
    color: #929da6;
    left: 10px;
    position: absolute;
    top: 9px;
}
.department-tree-filter__search {
    background: #fff;
    border: 1px solid #d2d6de;
    border-radius: 3px;
    height: 34px;
    outline: none;
    padding: 6px 30px 6px 31px;
    width: 100%;
}
.department-tree-filter__search:focus {
    border-color: #3c8dbc;
    box-shadow: 0 0 0 1px rgba(60, 141, 188, .08);
}
.department-tree-filter__search-clear {
    background: transparent;
    border: 0;
    color: #999;
    display: none;
    font-size: 17px;
    line-height: 1;
    padding: 4px;
    position: absolute;
    right: 6px;
    top: 5px;
}
.department-tree-filter__tree-wrap {
    max-height: 285px;
    min-height: 126px;
    overflow: auto;
    padding: 7px 4px 7px 8px;
}
.department-tree-filter__tree,
.department-tree-filter__children {
    list-style: none;
    margin: 0;
    padding: 0;
}
.department-tree-filter__children {
    border-left: 1px dotted #aeb7bd;
    margin-left: 10px;
    padding-left: 15px;
}
.department-tree-filter__node {
    list-style: none;
    margin: 0;
    padding: 0;
    position: relative;
}
.department-tree-filter__children > .department-tree-filter__node::before {
    border-top: 1px dotted #aeb7bd;
    content: '';
    left: -15px;
    position: absolute;
    top: 16px;
    width: 14px;
}
.department-tree-filter__row {
    align-items: center;
    border-radius: 2px;
    display: flex;
    min-height: 32px;
    padding: 3px 7px 3px 2px;
}
.department-tree-filter__row:hover {
    background: #eef6fa;
}
.department-tree-filter__node.is-selected > .department-tree-filter__row {
    background: #e7f3f9;
}
.department-tree-filter__toggle,
.department-tree-filter__spacer {
    align-items: center;
    background: #fff;
    border: 1px solid #9da7ad;
    color: #4d5960;
    display: inline-flex;
    flex: 0 0 14px;
    font-size: 11px;
    height: 14px;
    justify-content: center;
    line-height: 12px;
    margin-right: 6px;
    padding: 0;
    width: 14px;
}
.department-tree-filter__spacer {
    border-color: transparent;
    background: transparent;
}
.department-tree-filter__toggle:focus {
    outline: none;
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
    color: #7b858b;
    margin-right: 6px;
}
.department-tree-filter__name {
    color: #333;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.department-tree-filter__empty {
    color: #999;
    display: none;
    padding: 34px 12px;
    text-align: center;
}
.department-tree-filter__footer {
    align-items: center;
    background: #fafbfc;
    border-top: 1px solid #e5e9ec;
    display: flex;
    justify-content: space-between;
    min-height: 39px;
    padding: 6px 10px;
}
.department-tree-filter__count {
    color: #667681;
    font-size: 12px;
}
.department-tree-filter__clear {
    background: transparent;
    border: 0;
    color: #3c8dbc;
    padding: 3px 5px;
}
.department-tree-filter__clear:disabled {
    color: #aaa;
    cursor: default;
}
@else
(function ($) {
    var departments = @json($departments ?? []);
    var texts = @json($texts ?? []);
    var observer = null;

    function findDepartmentSelect() {
        return $('select').filter(function () {
            var name = String(this.name || '');
            return name.indexOf('department_id') !== -1 && $(this).prop('multiple');
        }).first();
    }

    function initialiseDepartmentTreeFilter() {
        var $select = findDepartmentSelect();
        if (!$select.length) {
            return false;
        }
        if ($select.data('department-tree-ready')) {
            return true;
        }

        $select.data('department-tree-ready', true);
        var selected = {};
        ($select.val() || []).forEach(function (id) { selected[String(id)] = true; });

        var byParent = {};
        departments.forEach(function (department) {
            var parent = department.parent_id === null ? 'root' : String(department.parent_id);
            byParent[parent] = byParent[parent] || [];
            byParent[parent].push(department);
        });

        var $widget = $('<div class="department-tree-filter"></div>');
        var $summary = $('<button type="button" class="department-tree-filter__summary" aria-expanded="false"></button>');
        var $summaryText = $('<span class="department-tree-filter__summary-text"></span>');
        $summary.append($summaryText, '<i class="fa fa-angle-down department-tree-filter__summary-arrow" aria-hidden="true"></i>');
        var $body = $('<div class="department-tree-filter__body"></div>');
        var $toolbar = $('<div class="department-tree-filter__toolbar"></div>');
        var $searchWrap = $('<div class="department-tree-filter__search-wrap"></div>');
        var $search = $('<input type="text" class="department-tree-filter__search">').attr('placeholder', texts.search || '');
        var $searchClear = $('<button type="button" class="department-tree-filter__search-clear" aria-label="clear">&times;</button>');
        $searchWrap.append('<i class="fa fa-search department-tree-filter__search-icon" aria-hidden="true"></i>', $search, $searchClear);
        $toolbar.append($searchWrap);

        var $treeWrap = $('<div class="department-tree-filter__tree-wrap"></div>');
        var $tree = $('<ul class="department-tree-filter__tree"></ul>');
        var $empty = $('<div class="department-tree-filter__empty"></div>').text(texts.empty || '');
        $treeWrap.append($tree, $empty);

        var $count = $('<span class="department-tree-filter__count"></span>');
        var $clear = $('<button type="button" class="department-tree-filter__clear"></button>').text(texts.clear || '');
        var $footer = $('<div class="department-tree-filter__footer"></div>').append($count, $clear);
        $body.append($toolbar, $treeWrap, $footer);
        $widget.append($summary, $body);

        function renderNodes(parentId, $container) {
            (byParent[parentId] || []).forEach(function (department) {
                var id = String(department.id);
                var children = byParent[id] || [];
                var $node = $('<li class="department-tree-filter__node"></li>')
                    .attr('data-id', id)
                    .attr('data-name', String(department.name || '').toLowerCase());
                var $row = $('<div class="department-tree-filter__row"></div>');

                if (children.length) {
                    $node.addClass('is-collapsed');
                    $row.append('<button type="button" class="department-tree-filter__toggle" aria-expanded="false">+</button>');
                } else {
                    $row.append('<span class="department-tree-filter__spacer"></span>');
                }

                var $checkbox = $('<input type="checkbox" class="department-tree-filter__checkbox">')
                    .val(id)
                    .prop('checked', !!selected[id]);
                var $label = $('<label class="department-tree-filter__label"></label>');
                $label.append(
                    $checkbox,
                    '<i class="fa fa-folder department-tree-filter__folder" aria-hidden="true"></i>',
                    $('<span class="department-tree-filter__name"></span>').text(department.name)
                );
                $row.append($label);
                $node.append($row).toggleClass('is-selected', !!selected[id]);

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
            $tree.find('.department-tree-filter__checkbox').each(function () {
                var checked = this.checked;
                $(this).closest('.department-tree-filter__node').toggleClass('is-selected', checked);
                if (checked) { values.push(String(this.value)); }
            });
            $select.val(values).trigger('change');
            var selectionText = values.length ? String(texts.selected || '').replace(':count', values.length) : (texts.all || '');
            $count.text(selectionText);
            $summaryText.text(selectionText);
            $clear.prop('disabled', values.length === 0);
        }

        function filterTree() {
            var keyword = $.trim(String($search.val() || '')).toLowerCase();
            var visibleCount = 0;
            $searchClear.toggle(keyword !== '');
            if (!keyword) {
                $tree.find('.department-tree-filter__node').filter(function () {
                    return $(this).children('.department-tree-filter__children').length > 0;
                })
                    .addClass('is-collapsed')
                    .children('.department-tree-filter__row').find('.department-tree-filter__toggle')
                    .html('+').attr('aria-expanded', 'false');
            }
            $($tree.find('.department-tree-filter__node').get().reverse()).each(function () {
                var $node = $(this);
                var selfMatches = !keyword || String($node.data('name') || '').indexOf(keyword) !== -1;
                var childMatches = keyword && $node.find('> .department-tree-filter__children > .department-tree-filter__node').filter(function () {
                    return $(this).data('filter-visible') === true;
                }).length > 0;
                var visible = selfMatches || childMatches;
                $node.data('filter-visible', visible).toggle(visible);
                if (visible) { visibleCount++; }
                if (keyword && childMatches) {
                    $node.removeClass('is-collapsed').children('.department-tree-filter__row').find('.department-tree-filter__toggle').html('&minus;').attr('aria-expanded', 'true');
                }
            });
            $tree.toggle(visibleCount > 0);
            $empty.toggle(visibleCount === 0);
        }

        renderNodes('root', $tree);
        updateSelection();

        $select.hide();
        $select.siblings('.select2, .select2-container').hide();
        $select.closest('.form-group').find('.select2-container').hide();
        $select.after($widget);

        $summary.on('click', function () {
            var opening = !$widget.hasClass('is-open');
            $widget.toggleClass('is-open', opening);
            $summary.attr('aria-expanded', opening ? 'true' : 'false');
            if (opening) {
                window.setTimeout(function () { $search.trigger('focus'); }, 0);
            }
        });
        $tree.on('click', '.department-tree-filter__toggle', function (event) {
            event.preventDefault();
            var $node = $(this).closest('.department-tree-filter__node');
            var collapsed = !$node.hasClass('is-collapsed');
            $node.toggleClass('is-collapsed', collapsed);
            $(this).html(collapsed ? '+' : '&minus;').attr('aria-expanded', collapsed ? 'false' : 'true');
        });
        $tree.on('change', '.department-tree-filter__checkbox', updateSelection);
        $search.on('input', filterTree);
        $searchClear.on('click', function () { $search.val(''); filterTree(); $search.trigger('focus'); });
        $clear.on('click', function () {
            $tree.find('.department-tree-filter__checkbox').prop('checked', false);
            updateSelection();
        });
        $(document).off('click.departmentTreeFilter').on('click.departmentTreeFilter', function (event) {
            if (!$(event.target).closest($widget).length) {
                $widget.removeClass('is-open');
                $summary.attr('aria-expanded', 'false');
            }
        });
        $select.closest('form').on('reset.departmentTreeFilter', function () {
            window.setTimeout(function () {
                $widget.removeClass('is-open');
                $summary.attr('aria-expanded', 'false');
                $tree.find('.department-tree-filter__checkbox').prop('checked', false);
                $search.val('');
                filterTree();
                updateSelection();
            }, 0);
        });

        return true;
    }

    $(function () {
        if (initialiseDepartmentTreeFilter()) {
            return;
        }
        observer = new MutationObserver(function () {
            if (initialiseDepartmentTreeFilter() && observer) {
                observer.disconnect();
                observer = null;
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(function () {
            if (observer) { observer.disconnect(); observer = null; }
        }, 5000);
    });
})(jQuery);
@endif
