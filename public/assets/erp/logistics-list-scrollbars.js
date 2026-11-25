(function () {
    'use strict';

    function preserveHandlingPosition(area) {
        var storageKey = 'erp:logistics:handling-position:' + window.location.pathname;
        var openedPosition;

        function findRow(id) {
            return area.querySelector('[data-logistics-handling-row="' + CSS.escape(id) + '"]');
        }

        function capturePosition(row) {
            return {
                id: row.dataset.logisticsHandlingRow,
                rowTop: row.getBoundingClientRect().top,
                pageY: window.scrollY,
                listX: area.scrollLeft,
                listY: area.scrollTop
            };
        }

        area.addEventListener('click', function (event) {
            if (!event.target.closest('[data-logistics-handling-open]')) return;
            var row = event.target.closest('[data-logistics-handling-row]');
            if (row) openedPosition = capturePosition(row);
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            var id = form.dataset.logisticsHandlingForm;
            if (!id || event.defaultPrevented) return;
            var row = findRow(id);
            if (!row) return;

            var position = openedPosition && openedPosition.id === id ? openedPosition : capturePosition(row);
            position.returnUrl = form.elements.return_to.value.split('#')[0];
            position.sourceUrl = window.location.href.split('#')[0];
            position.savedAt = Date.now();
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify(position));
            } catch (error) {
                // Saving the record still works when browser storage is unavailable.
            }
        });

        window.addEventListener('pageshow', function () {
            var position;
            try {
                position = JSON.parse(window.sessionStorage.getItem(storageKey));
                window.sessionStorage.removeItem(storageKey);
            } catch (error) {
                return;
            }
            var url = window.location.href.split('#')[0];
            if (!position || Date.now() - position.savedAt > 300000
                || (url !== position.returnUrl && url !== position.sourceUrl)) return;

            window.requestAnimationFrame(function () {
                area.scrollLeft = position.listX;
                area.scrollTop = position.listY;
                var row = findRow(position.id);
                var pageY = row ? window.scrollY + row.getBoundingClientRect().top - position.rowTop : position.pageY;
                window.scrollTo({ top: Math.max(0, pageY), behavior: 'instant' });
            });
        });
    }

    document.querySelectorAll('[data-logistics-list-scrollbars]').forEach(function (area) {
        var timer;

        area.addEventListener('scroll', function () {
            window.clearTimeout(timer);
            area.classList.add('is-scrolling');
            timer = window.setTimeout(function () {
                area.classList.remove('is-scrolling');
            }, 2000);
        }, { passive: true });

        area.addEventListener('pointermove', function (event) {
            if (event.pointerType === 'touch') return;
            var bounds = area.getBoundingClientRect();
            var horizontalEdge = area.scrollWidth > area.clientWidth && event.clientY >= bounds.bottom - 18;
            area.classList.toggle('is-scrollbar-hovered', horizontalEdge);
        });

        area.addEventListener('pointerleave', function () {
            area.classList.remove('is-scrollbar-hovered');
        });

        area.setAttribute('data-scrollbars-ready', '');
        preserveHandlingPosition(area);
    });
}());
