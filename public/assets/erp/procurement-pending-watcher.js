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

    // 已通知过的记录 ID 持久化到浏览器本地，刷新页面后不会重复提醒同一条记录
    var STORAGE_PREFIX = 'erpPendingNotified:';
    var MAX_TRACKED_IDS = 1000;
    var memoryFallback = {};

    function loadNotified(storageKey) {
        var ids = memoryFallback[storageKey] || [];

        try {
            var raw = window.localStorage.getItem(STORAGE_PREFIX + storageKey);
            if (raw) {
                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    ids = parsed;
                }
            }
        } catch (e) {
            // localStorage 不可用时退回内存记录
        }

        memoryFallback[storageKey] = ids;

        return ids;
    }

    function saveNotified(storageKey, ids) {
        var trimmed = ids.length > MAX_TRACKED_IDS ? ids.slice(ids.length - MAX_TRACKED_IDS) : ids;
        memoryFallback[storageKey] = trimmed;

        try {
            window.localStorage.setItem(STORAGE_PREFIX + storageKey, JSON.stringify(trimmed));
        } catch (e) {
            // 忽略写入失败
        }
    }

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

    // 只对本次轮询中首次出现的记录发通知（触发条件与菜单角标统计规则一致）
    function notifyNewItems(storageKey, items, title, buildBody) {
        var notified = loadNotified(storageKey);
        var known = {};

        for (var i = 0; i < notified.length; i++) {
            known[String(notified[i])] = true;
        }

        var changed = false;
        for (var j = 0; j < items.length; j++) {
            var item = items[j];
            if (!item || typeof item.id === 'undefined') {
                continue;
            }

            var key = String(item.id);
            if (known[key]) {
                continue;
            }

            known[key] = true;
            notified.push(item.id);
            changed = true;
            sendNotification(title, buildBody(item));
        }

        if (changed) {
            saveNotified(storageKey, notified);
        }
    }

    function pollProcurement() {
        fetchCount(PROCUREMENT_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            updateBadge(PROCUREMENT_BADGE_ID, Number(data.count));
            notifyNewItems('procurement', data.items || [], '商品信息检验 - 待处理', function (item) {
                return '提报编号 ' + item.report_no + ' 需要检验审核。';
            });
        });
    }

    function pollOperation() {
        fetchCount(OPERATION_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            updateBadge(OPERATION_BADGE_ID, Number(data.count));
            notifyNewItems('operation', data.items || [], '需求提报 - 待运营回填', function (item) {
                return '提报编号 ' + item.report_no + ' 需要回填投放链接。';
            });
        });
    }

    function pollPlacementReview() {
        fetchCount(PLACEMENT_REVIEW_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            updateBadge(PLACEMENT_REVIEW_BADGE_ID, Number(data.count));
            notifyNewItems('placement-review', data.items || [], '投放链接审核 - 待审核', function (item) {
                return '提报编号 ' + item.report_no + ' 需要审核投放链接。';
            });
        });
    }

    function pollWarehouse() {
        fetchCount(WAREHOUSE_API).then(function (data) {
            if (!data || typeof data.count === 'undefined') {
                return;
            }
            updateBadge(WAREHOUSE_BADGE_ID, Number(data.count));
            notifyNewItems('warehouse', data.items || [], '仓库验收 - 待审核', function (item) {
                return '提报编号 ' + item.report_no + ' 需要仓库验收审核。';
            });
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
