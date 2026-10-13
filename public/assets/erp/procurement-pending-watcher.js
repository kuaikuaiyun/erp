(function (window, document) {
    'use strict';

    var PROCUREMENT_API = '/api/procurement-inspections/pending-count';
    var OPERATION_API = '/api/product-requests/operation-pending-count';
    var PLACEMENT_REVIEW_API = '/api/placement-reviews/pending-count';
    var WAREHOUSE_API = '/api/warehouse-acceptances/pending-count';
    var POLL_INTERVAL = 60000;

    var PROCUREMENT_BADGE_ID = 'procurement-pending-badge';
    var OPERATION_BADGE_ID = 'operation-pending-badge';
    var PLACEMENT_REVIEW_BADGE_ID = 'placement-review-pending-badge';
    var WAREHOUSE_BADGE_ID = 'warehouse-pending-badge';

    // 采购检验 + 投放审核 + 运营回填 + 仓库验收：记录已通知过的 ID，新记录逐条发通知
    var notifiedProcurementIds = {};
    var notifiedPlacementReviewIds = {};
    var notifiedOperationIds = {};
    var notifiedWarehouseIds = {};

    function updateBadge(badgeId, count) {
        var badge = document.getElementById(badgeId);
        if (!badge) {
            return;
        }
        badge.textContent = String(count);
    }

    function sendNotification(title, body) {
        // 优先使用浏览器系统通知
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                var notification = new Notification(title, {
                    body: body,
                    icon: '/favicon.ico',
                    tag: title + '-' + Date.now(),
                    renotify: true
                });

                notification.onclick = function () {
                    window.focus();
                    notification.close();
                };
                return;
            } catch (e) {
                // 降级到 toast
            }
        }

        // 兜底：使用页面内 toast 通知
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify([{
                type: 'info',
                title: title,
                message: body,
                duration: 8000
            }]);
        }
    }

    function requestPermissionIfNeeded() {
        if (!('Notification' in window)) {
            return;
        }

        if (Notification.permission === 'default') {
            try {
                Notification.requestPermission(function () {});
            } catch (e) {}
        }
    }

    function fetchCount(apiUrl) {
        return fetch(apiUrl, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                    ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    : ''
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    return null;
                }
                return response.json();
            })
            .catch(function () {
                return null;
            });
    }

    function pollProcurement() {
        fetchCount(PROCUREMENT_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            var count = Number(data.count);
            updateBadge(PROCUREMENT_BADGE_ID, count);

            // 逐条检查新记录
            var items = data.items || [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (!notifiedProcurementIds[item.id]) {
                    notifiedProcurementIds[item.id] = true;
                    sendNotification(
                        '商品信息检验 - 待处理',
                        '提报编号 ' + item.report_no + ' 需要检验审核。'
                    );
                }
            }
        });
    }

    function pollOperation() {
        fetchCount(OPERATION_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            var count = Number(data.count);
            updateBadge(OPERATION_BADGE_ID, count);

            // 逐条检查新记录
            var items = data.items || [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (!notifiedOperationIds[item.id]) {
                    notifiedOperationIds[item.id] = true;
                    sendNotification(
                        '需求提报 - 待运营回填',
                        '提报编号 ' + item.report_no + ' 需要回填投放链接。'
                    );
                }
            }
        });
    }

    function pollPlacementReview() {
        fetchCount(PLACEMENT_REVIEW_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            var count = Number(data.count);
            updateBadge(PLACEMENT_REVIEW_BADGE_ID, count);

            // 逐条检查新记录
            var items = data.items || [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (!notifiedPlacementReviewIds[item.id]) {
                    notifiedPlacementReviewIds[item.id] = true;
                    sendNotification(
                        '投放链接审核 - 待审核',
                        '提报编号 ' + item.report_no + ' 需要审核投放链接。'
                    );
                }
            }
        });
    }

    function pollWarehouse() {
        fetchCount(WAREHOUSE_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            var count = Number(data.count);
            updateBadge(WAREHOUSE_BADGE_ID, count);

            // 逐条检查新记录
            var items = data.items || [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (!notifiedWarehouseIds[item.id]) {
                    notifiedWarehouseIds[item.id] = true;
                    sendNotification(
                        '仓库验收 - 待审核',
                        '提报编号 ' + item.report_no + ' 需要仓库验收审核。'
                    );
                }
            }
        });
    }

    function poll() {
        if (document.getElementById(PROCUREMENT_BADGE_ID)) {
            pollProcurement();
        }
        if (document.getElementById(OPERATION_BADGE_ID)) {
            pollOperation();
        }
        if (document.getElementById(PLACEMENT_REVIEW_BADGE_ID)) {
            pollPlacementReview();
        }
        if (document.getElementById(WAREHOUSE_BADGE_ID)) {
            pollWarehouse();
        }
    }

    function init() {
        requestPermissionIfNeeded();
        poll();
        setInterval(poll, POLL_INTERVAL);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
