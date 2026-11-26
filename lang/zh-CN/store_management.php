<?php

return [
    'baokuan_list' => [
        'platform' => '平台',
        'all_platforms' => '全部平台',
        'department' => '运营部门',
        'all_departments' => '全部部门',
        'operator' => '运营人员',
        'all_operators' => '全部运营',
        'store' => '店铺名称／域名',
        'store_name' => '店铺名称',
        'keyword' => '输入店铺名称或域名搜索',
        'expand_stores' => '展开店铺候选',
        'no_match' => '没有匹配店铺，仍可直接按当前输入内容查询。',
        'no_domain' => '未填写域名',
        'no_department' => '未绑定部门',
        'unnamed' => '未命名店铺',
        'builder' => '建站系统',
        'store_status' => '店铺状态',
        'credential_status' => '配置状态',
        'credential' => 'API 配置',
        'guide' => '密钥填写教程',
        'statuses' => [
            'in_use' => '在用',
            'abandoned' => '已弃用',
            'stopped' => '已停用',
        ],
        'configured' => '已配置',
        'unconfigured' => '未配置',
    ],
    'credential_guide' => [
        'shopyy_nbc' => [
            'tab' => 'shopyy/nbc',
            'step1_title' => '步骤 1：进入开发者页面',
            'step1_body' => '登录店铺后台，依次进入「店铺设置」→「系统设置」→「开发者」。',
            'step1_alt' => 'Shopyy / NBC 从系统设置进入开发者页面',
            'step2_title' => '步骤 2：配置 IP 白名单',
            'step2_body' => '点击「IP白名单」，按下图在白名单中填写 *.*.*.*，然后保存。',
            'step2_alt' => 'Shopyy / NBC 配置 IP 白名单',
            'step3_title' => '步骤 3：复制 Token 并保存到 ERP',
            'step3_body' => '复制对应开发者应用的 Token，粘贴到 ERP 对应店铺的 Token 栏并保存。约等待 10 分钟，系统将开始同步店铺订单；具体同步范围以现有订单同步规则为准。',
            'step3_alt' => 'Shopyy / NBC 复制开发者应用 Token',
        ],
    ],
    'page_title' => '店铺列表',

    'title' => '店铺列表',
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
