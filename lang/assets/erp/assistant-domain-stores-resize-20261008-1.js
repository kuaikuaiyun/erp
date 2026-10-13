(function () {
    'use strict';

    var minimumWidths = {
        created_at: 92,
        site_builder: 100,
        shop_name: 120,
        domain: 150,
        domain_expires_at: 100,
        domain_check_status: 96,
        email: 150,
        email_bound: 82,
        email_purchased: 82,
        team: 110,
        project: 120,
        user_name: 130,
        erp: 100,
        credit_card: 120,
        wallet: 120,
        paypal: 120,
        other_payment: 120,
        shop_login_url: 150,
        shop_status: 96,
        plan_expires_at: 100,
        payment_card: 140,
        last_operator_name: 110,
        updated_at: 120,
        actions: 190
    };
    var maximumWidth = 560;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function setColumnWidth(column, header, width) {
        var roundedWidth = Math.round(width);

        column.style.width = roundedWidth + 'px';
        header.style.width = roundedWidth + 'px';
    }

    function initializeResizeHandle(table, header) {
        if (header.getAttribute('data-column-resizable') === 'false') {
            return;
        }

        var key = header.getAttribute('data-column-key');
        var column = table.querySelector('col[data-column-key="' + key + '"]');

        if (!key || !column) {
            return;
        }

        if (header.querySelector('.assistant-column-resize-handle')) {
            return;
        }

        var minimumWidth = minimumWidths[key] || 96;
        var handle = document.createElement('span');
        handle.className = 'assistant-column-resize-handle';
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-valuemin', String(minimumWidth));
        handle.setAttribute('aria-valuemax', String(maximumWidth));
        handle.setAttribute('tabindex', '0');

        function currentWidth() {
            return Math.max(minimumWidth, header.getBoundingClientRect().width);
        }

        function updateAccessibility(width) {
            handle.setAttribute('aria-valuenow', String(Math.round(width)));
        }

        function resize(width) {
            var adjustedWidth = clamp(width, minimumWidth, maximumWidth);

            setColumnWidth(column, header, adjustedWidth);
            updateAccessibility(adjustedWidth);
        }

        handle.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var startX = event.clientX;
            var startWidth = currentWidth();

            document.body.classList.add('assistant-column-resizing');
            handle.classList.add('is-resizing');

            function onPointerMove(moveEvent) {
                resize(startWidth + moveEvent.clientX - startX);
            }

            function onPointerUp() {
                document.body.classList.remove('assistant-column-resizing');
                handle.classList.remove('is-resizing');
                document.removeEventListener('pointermove', onPointerMove);
                document.removeEventListener('pointerup', onPointerUp);
                document.removeEventListener('pointercancel', onPointerUp);
            }

            document.addEventListener('pointermove', onPointerMove);
            document.addEventListener('pointerup', onPointerUp);
            document.addEventListener('pointercancel', onPointerUp);
        });

        handle.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            event.preventDefault();
            resize(currentWidth() + (event.key === 'ArrowRight' ? 10 : -10));
        });

        updateAccessibility(currentWidth());
        header.appendChild(handle);
    }

    function initialize(root) {
        var scope = root || document;
        var table = scope.matches && scope.matches('.assistant-domain-stores-table')
            ? scope
            : scope.querySelector('.assistant-domain-stores-table');

        if (!table) {
            return;
        }

        table.querySelectorAll('thead th[data-column-key]').forEach(function (header) {
            initializeResizeHandle(table, header);
        });
    }

    window.ErpAssistantDomainStoreColumnResize = { initialize: initialize };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
