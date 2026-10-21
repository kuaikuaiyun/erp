<?php

return [
    'page_title' => 'Shopify 店铺分析',

    'title' => 'Shopify 店铺分析',
    'subtitle' => '运营系统',

    'menus' => [
        'store_management' => '店铺管理',
        'domain_stores' => '店铺列表',
    ],

    'common' => [
        'search' => '搜索',
        'clear_filters' => '清空筛选',
        'no_data' => '暂无店铺数据',
        'no_results' => '没有匹配的店铺',
        'total_count' => '共 :count 条',
        'empty' => '—',
        'all' => '全部',
        'per_page' => '每页条数',
        'readonly_tip' => '只读视图',
        'yes' => '是',
        'no' => '否',
    ],

    'currency' => [
        'usd_prefix' => 'US$',
    ],

    'overview' => [
        'stores' => '店铺数',
        'synced_stores' => '已成功拉取',
        'total_sales' => '销售额（USD）',
        'orders' => '订单数',
        'average_order_value' => '客单价（USD）',
        'original_total_sales' => '店铺原始营业额',
        'refunds' => '退款额（USD）',
        'net_sales' => '净额数据（USD）',
    ],

    'subscriptions' => [
        'title' => '订阅订单汇总',
        'regular' => '无订阅（普通订单）',
        'subscribed' => '已订阅',
        'recurring' => '复购单',
        'orders' => '总单量',
        'sales' => '总营业额（USD）',
        'original_sales' => '店铺原始营业额',
    ],

    'metrics' => [
        'business_summary' => '营业汇总',
    ],

    'filters' => [
        'title' => '筛选条件',
        'shop_name' => '店铺名',
        'shop_name_placeholder' => '请输入店铺名',
        'domains' => '域名',
        'domains_placeholder' => '多个域名请用逗号分隔',
        'timezone' => '统计时区',
        'timezone_utc' => 'UTC+00:00 协调世界时',
        'timezone_shanghai' => 'UTC+08:00 上海时间',
        'from_date' => '开始时间',
        'to_date' => '结束时间',
        'submit' => '查询',
        'reset' => '重置',
    ],

    'table' => [
        'store' => '店铺',
        'domain' => '域名',
        'email_purchased' => '邮箱购买',
        'shop_status' => '店铺状态',
        'sync_status' => '同步状态',
        'actions' => '操作',
    ],

    'card' => [
        'not_available' => '—',
        'no_data' => '所选时段无订单数据',
        'rate_missing' => '系统中缺少该币种的固定参考汇率，请联系管理员配置。',
    ],

    'actions' => [
        'refresh' => '刷新',
        'inactive' => '店铺已停用',
    ],

    'messages' => [
        'refresh_queued' => '已加入刷新队列，稍后自动拉取最新 Shopify 数据。',
        'inactive_store' => '店铺已停用，无法刷新。',
        'batch_in_progress' => '已有刷新任务正在进行中，请等待完成后再试。',
        'store_busy' => '该店铺正在刷新中，请稍后再试。',
    ],

    'pagination' => [
        'summary' => '显示 :from - :to 条，共 :total 家店铺',
        'navigation' => '分页导航',
        'previous' => '上一页',
        'next' => '下一页',
        'per_page' => '每页显示',
        'records_suffix' => '家',
    ],
];