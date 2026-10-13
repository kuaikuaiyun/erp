(function () {
    'use strict';

    function parsePositionAvailability(departmentSelect) {
        var selected = departmentSelect && departmentSelect.options[departmentSelect.selectedIndex];
        var raw = selected ? selected.getAttribute('data-position-availability') : '';

        if (!raw) {
            return {};
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return {};
        }
    }

    function setPositionAvailability(departmentSelect, positionInputs, notice, noAvailableMessage) {
        if (!departmentSelect || !positionInputs.length) {
            return;
        }

        var selected = departmentSelect.options[departmentSelect.selectedIndex];
        var hasDepartment = Boolean(departmentSelect.value);
        var availability = parsePositionAvailability(departmentSelect);
        var availableCount = 0;

        Array.prototype.forEach.call(positionInputs, function (input) {
            var state = availability[input.value] || {};
            var enabled = !hasDepartment || state.enabled !== false;
            var choice = input.closest('[data-membership-position-choice]');

            input.disabled = !enabled;

            if (choice) {
                choice.classList.toggle('is-disabled', !enabled);
            }

            if (enabled) {
                availableCount += 1;
            }
        });

        var selectedInput = Array.prototype.filter.call(positionInputs, function (input) {
            return input.checked && !input.disabled;
        })[0];

        if (!selectedInput && availableCount > 0) {
            Array.prototype.filter.call(positionInputs, function (input) {
                return !input.disabled;
            })[0].checked = true;
        }

        departmentSelect.setCustomValidity(hasDepartment && availableCount === 0 ? noAvailableMessage : '');

        if (notice) {
            notice.textContent = hasDepartment && selected ? (selected.getAttribute('data-position-notice') || '') : '';
        }
    }

    function syncAccountPositionControls(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('[data-membership-row]'), function (row) {
            setPositionAvailability(
                row.querySelector('[data-membership-department]'),
                row.querySelectorAll('[data-membership-position]'),
                row.querySelector('[data-membership-position-notice]'),
                row.getAttribute('data-no-available-position-message') || ''
            );
        });
    }

    function initialiseAccountSection(section) {
        if (section.getAttribute('data-organization-ready') === '1') {
            return;
        }

        var form = section.closest('form');
        var list = section.querySelector('[data-membership-list]');
        var template = section.querySelector('[data-membership-template]');
        var nextIndex = list ? list.querySelectorAll('[data-membership-row]').length : 0;

        if (!form) {
            return;
        }

        section.setAttribute('data-organization-ready', '1');

        syncAccountPositionControls(form);

        form.addEventListener('change', function (event) {
            if (event.target.matches('[data-membership-department]')) {
                syncAccountPositionControls(form);
            }
        });

        var addButton = section.querySelector('[data-add-membership]');
        if (addButton && list && template) {
            addButton.addEventListener('click', function () {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                nextIndex += 1;
                list.appendChild(wrapper.firstElementChild);
                syncAccountPositionControls(form);
            });
        }

        section.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-membership]');
            if (removeButton) {
                var row = removeButton.closest('[data-membership-row]');
                if (row) {
                    row.remove();
                }
            }
        });
    }

    function setDissolutionPositionAvailability(targetSelect, positionSelect, notice, noAvailableMessage) {
        if (!targetSelect || !positionSelect) {
            return;
        }

        var selectedTarget = targetSelect.options[targetSelect.selectedIndex];
        var isTransfer = Boolean(targetSelect.value) && targetSelect.value !== 'remove';

        if (!isTransfer) {
            positionSelect.disabled = true;
            positionSelect.required = false;
            targetSelect.setCustomValidity('');

            if (notice) {
                notice.textContent = '';
            }

            return;
        }

        var availability = parsePositionAvailability(targetSelect);
        var availableCount = 0;

        Array.prototype.forEach.call(positionSelect.options, function (option) {
            var state = availability[option.value] || {};
            var enabled = state.enabled !== false;
            option.disabled = !enabled;

            if (enabled) {
                availableCount += 1;
            }
        });

        if (positionSelect.options[positionSelect.selectedIndex].disabled && availableCount > 0) {
            positionSelect.value = Array.prototype.filter.call(positionSelect.options, function (option) {
                return !option.disabled;
            })[0].value;
        }

        positionSelect.disabled = false;
        positionSelect.required = true;
        targetSelect.setCustomValidity(availableCount === 0 ? noAvailableMessage : '');

        if (notice) {
            notice.textContent = selectedTarget ? (selectedTarget.getAttribute('data-position-notice') || '') : '';
        }
    }

    function syncDissolutionPositionControls(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('[data-dissolution-row]'), function (row) {
            setDissolutionPositionAvailability(
                row.querySelector('[data-dissolution-target]'),
                row.querySelector('[data-dissolution-position]'),
                row.querySelector('[data-dissolution-position-notice]'),
                row.getAttribute('data-no-available-position-message') || ''
            );
        });
    }

    function initialiseDissolutionForm(marker) {
        if (marker.getAttribute('data-organization-ready') === '1') {
            return;
        }

        var form = marker.closest('form');

        if (!form) {
            return;
        }

        marker.setAttribute('data-organization-ready', '1');
        syncDissolutionPositionControls(form);

        form.addEventListener('change', function (event) {
            if (event.target.matches('[data-dissolution-target]')) {
                syncDissolutionPositionControls(form);
            }
        });

        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                syncDissolutionPositionControls(form);
            }, 0);
        });
    }

    function initialiseDepartmentTrees() {
        Array.prototype.forEach.call(document.querySelectorAll('table'), function (table) {
            if (!table.querySelector('.organization-tree-node') || table.getAttribute('data-organization-tree-ready') === '1') {
                return;
            }

            table.setAttribute('data-organization-tree-ready', '1');
            table.classList.add('organization-department-grid');

            Array.prototype.forEach.call(table.querySelectorAll('.organization-tree-root'), function (node) {
                var row = node.closest('tr');
                if (row) {
                    row.classList.add('organization-tree-root-row');
                }
            });
            Array.prototype.forEach.call(table.querySelectorAll('.organization-tree-child'), function (node) {
                var row = node.closest('tr');
                if (row) {
                    row.classList.add('organization-tree-child-row');
                }
            });

            table.addEventListener('click', function (event) {
                var toggle = event.target.closest('[data-department-tree-toggle]');

                if (!toggle || !table.contains(toggle)) {
                    return;
                }

                var departmentId = toggle.getAttribute('data-tree-department-id');
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                var childNodes = table.querySelectorAll('[data-tree-child-of="' + departmentId + '"]');

                Array.prototype.forEach.call(childNodes, function (node) {
                    var row = node.closest('tr');
                    if (row) {
                        row.classList.toggle('organization-tree-row-collapsed', expanded);
                    }
                });

                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                toggle.setAttribute('title', expanded ? toggle.getAttribute('data-expand-label') : toggle.getAttribute('data-collapse-label'));
                var icon = toggle.querySelector('.fa');
                if (icon) {
                    icon.className = expanded ? 'fa fa-chevron-right' : 'fa fa-chevron-down';
                }
            });
        });
    }

    function initialisePermissionSection(section) {
        if (section.getAttribute('data-organization-ready') === '1') {
            return;
        }

        var form = section.closest('form');
        var tree = section.querySelector('[data-page-tree]');
        if (!tree) {
            return;
        }

        section.setAttribute('data-organization-ready', '1');

        function toggleNode(node, expanded) {
            var children = node.querySelector(':scope > [data-tree-children]');
            var toggle = node.querySelector('[data-tree-toggle]');
            if (!children || !toggle) {
                return;
            }
            children.classList.toggle('is-collapsed', !expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        Array.prototype.forEach.call(tree.querySelectorAll('[data-tree-toggle]'), function (button) {
            button.addEventListener('click', function () {
                var node = button.closest('[data-tree-node]');
                toggleNode(node, button.getAttribute('aria-expanded') !== 'true');
            });
        });

        var expandAll = section.querySelector('[data-expand-all]');
        var collapseAll = section.querySelector('[data-collapse-all]');
        if (expandAll) {
            expandAll.addEventListener('click', function () {
                Array.prototype.forEach.call(tree.querySelectorAll('[data-tree-node]'), function (node) { toggleNode(node, true); });
            });
        }
        if (collapseAll) {
            collapseAll.addEventListener('click', function () {
                Array.prototype.forEach.call(tree.querySelectorAll('[data-tree-node]'), function (node) { toggleNode(node, false); });
            });
        }

        var selectAll = section.querySelector('[data-select-all]');
        var clearAll = section.querySelector('[data-clear-all]');
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                Array.prototype.forEach.call(tree.querySelectorAll('[data-permission-checkbox]:not(:disabled)'), function (checkbox) {
                    checkbox.checked = true;
                    var select = checkbox.closest('[data-permission-row]').parentElement.querySelector('[data-scope-select]');
                    if (select) { select.disabled = false; }
                });
            });
        }
        if (clearAll) {
            clearAll.addEventListener('click', function () {
                Array.prototype.forEach.call(tree.querySelectorAll('[data-permission-checkbox]:not(:disabled)'), function (checkbox) {
                    checkbox.checked = false;
                    var select = checkbox.closest('[data-permission-row]').parentElement.querySelector('[data-scope-select]');
                    if (select) { select.disabled = true; }
                });
            });
        }

        tree.addEventListener('change', function (event) {
            if (event.target.matches('[data-permission-checkbox]:not(:disabled)')) {
                var scopeSelect = event.target.closest('[data-permission-row]').parentElement.querySelector('[data-scope-select]');
                if (scopeSelect) {
                    scopeSelect.disabled = !event.target.checked;
                }
            }
        });

        var search = section.querySelector('[data-permission-search]');
        if (search) {
            search.addEventListener('input', function () {
                var keyword = search.value.trim().toLowerCase();

                function filterNode(node) {
                    var ownMatch = node.getAttribute('data-search-name').indexOf(keyword) !== -1;
                    var childMatch = false;
                    Array.prototype.forEach.call(node.querySelectorAll(':scope > [data-tree-children] > [data-tree-node]'), function (child) {
                        childMatch = filterNode(child) || childMatch;
                    });
                    var visible = keyword === '' || ownMatch || childMatch;
                    node.hidden = !visible;
                    if (keyword !== '' && childMatch) { toggleNode(node, true); }
                    return visible;
                }

                Array.prototype.forEach.call(tree.querySelectorAll(':scope > [data-tree-node]'), filterNode);
            });
        }
    }

    function initialiseOrganizationWorkspace() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-organization-account-form], [data-organization-department-form], [data-permission-form], [data-organization-dissolution-form]'), function (marker) {
            var form = marker.closest('form');
            if (form) {
                form.classList.add('organization-form-footer-right');
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-organization-account-section]'), initialiseAccountSection);
        Array.prototype.forEach.call(document.querySelectorAll('[data-organization-dissolution-form]'), initialiseDissolutionForm);
        Array.prototype.forEach.call(document.querySelectorAll('[data-permission-form]'), initialisePermissionSection);
        initialiseDepartmentTrees();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiseOrganizationWorkspace);
    } else {
        initialiseOrganizationWorkspace();
    }

    if (window.jQuery) {
        window.jQuery(document).on('pjax:end', initialiseOrganizationWorkspace);
    }
})();


