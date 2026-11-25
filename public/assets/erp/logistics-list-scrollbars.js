(function () {
    'use strict';

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
    });
}());
