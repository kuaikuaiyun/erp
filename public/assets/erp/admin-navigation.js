(function (window, document) {
    'use strict';

    function normalisePath(path) {
        if (!path) {
            return '/';
        }

        var normalised = path.replace(/\/+$/, '');

        return normalised || '/';
    }

    function linkPath(link) {
        var href = link.getAttribute('href');

        if (!href || href === '#' || href.indexOf('javascript:') === 0) {
            return null;
        }

        try {
            var url = new window.URL(link.href, window.location.origin);

            return url.origin === window.location.origin ? normalisePath(url.pathname) : null;
        } catch (error) {
            return null;
        }
    }

    function matchesCurrentPath(currentPath, candidatePath) {
        if (currentPath === candidatePath) {
            return true;
        }

        if (candidatePath === '/' || currentPath.indexOf(candidatePath + '/') !== 0) {
            return false;
        }

        // The dashboard normally maps to the admin root (for example /admin).
        // It should only be active on its exact route, never for every admin page.
        return candidatePath.split('/').filter(Boolean).length > 1;
    }

    function findBestMenuItem(menu, currentPath) {
        var best = null;

        Array.prototype.forEach.call(menu.querySelectorAll('li > a[href]'), function (link) {
            if (link.target === '_blank') {
                return;
            }

            var candidatePath = linkPath(link);

            if (!candidatePath || !matchesCurrentPath(currentPath, candidatePath)) {
                return;
            }

            if (!best || candidatePath.length > best.path.length) {
                best = {
                    item: link.parentElement,
                    path: candidatePath
                };
            }
        });

        return best ? best.item : null;
    }

    function markMenuAncestors(item) {
        var current = item;

        while (current && current.tagName && current.tagName.toLowerCase() === 'li') {
            current.classList.add('active');

            var parentMenu = current.parentElement;
            if (!parentMenu || !parentMenu.classList.contains('treeview-menu')) {
                break;
            }

            parentMenu.classList.add('menu-open');
            current = parentMenu.parentElement;
        }
    }

    function synchroniseSidebarMenu() {
        var menu = document.querySelector('.main-sidebar .sidebar-menu');

        if (!menu) {
            return;
        }

        var activeItem = findBestMenuItem(menu, normalisePath(window.location.pathname));

        if (!activeItem) {
            return;
        }

        Array.prototype.forEach.call(menu.querySelectorAll('li.active'), function (item) {
            item.classList.remove('active');
        });

        Array.prototype.forEach.call(menu.querySelectorAll('ul.treeview-menu.menu-open'), function (item) {
            item.classList.remove('menu-open');
        });

        markMenuAncestors(activeItem);
    }

    function configureProgress() {
        if (!window.NProgress || typeof window.NProgress.configure !== 'function') {
            return;
        }

        // #app is replaced on every PJAX response. Mounting progress there makes
        // the old NProgress element disappear mid-animation and then re-render.
        window.NProgress.configure({
            parent: 'body',
            showSpinner: false,
            trickle: false,
            speed: 140,
            easing: 'ease-out'
        });
    }

    function scheduleSidebarSync() {
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(synchroniseSidebarMenu);

            return;
        }

        window.setTimeout(synchroniseSidebarMenu, 0);
    }

    function initialise() {
        configureProgress();
        synchroniseSidebarMenu();

        if (window.jQuery) {
            window.jQuery(document)
                .off('pjax:end.erp-navigation')
                .on('pjax:end.erp-navigation', scheduleSidebarSync);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
})(window, document);


