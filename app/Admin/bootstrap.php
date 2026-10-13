<?php

/**
 * Laravel-admin - admin builder based on Laravel.
 * @author leeqvip <https://github.com/leeqvip>
 *
 * Bootstraper for Admin.
 *
 * Here you can remove builtin form field:
 * Ladmin\Form::forget(['map', 'editor']);
 *
 * Or extend custom form field:
 * Ladmin\Form::extend('php', PHPEditor::class);
 *
 * Or require js and css assets:
 * Admin::css('/packages/prettydocs/css/styles.css');
 * Admin::js('/packages/prettydocs/js/main.js');
 *
 */

Ladmin\Form::forget(['map', 'editor']);

Ladmin\Admin::css('/assets/erp/sidebar-layout.css?v=20261006-2');
Ladmin\Admin::css('/assets/erp/system-font.css?v=20260928-1');
Ladmin\Admin::css('/assets/erp/admin-notifications.css?v=20260929-2');
Ladmin\Admin::css('/assets/erp/admin-navigation.css?v=20260929-1');
Ladmin\Admin::css('/assets/erp/configuration-workspace.css?v=20260928-4');
Ladmin\Admin::css('/assets/erp/organization-workspace.css?v=20260930-2');
Ladmin\Admin::css('/assets/erp/portal-workspace.css?v=20260930-9');
Ladmin\Admin::css('/assets/erp/interaction-loading-20261011-1.css?v=20261011-3');
Ladmin\Admin::headerJs('/assets/erp/admin-notifications.js?v=20260929-2');
Ladmin\Admin::js('/assets/erp/organization-workspace.js?v=20260929-5');
Ladmin\Admin::js('/assets/erp/admin-navigation.js?v=20260929-1');
Ladmin\Admin::js('/assets/erp/portal-workspace.js?v=20260930-2');
Ladmin\Admin::js('/assets/erp/interaction-loading-20261011-1.js?v=20261011-3');
app(\App\Services\AdminWatermarkService::class)->registerForCurrentAdministrator();
