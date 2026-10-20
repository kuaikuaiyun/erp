<?php

return [
    'menus' => [
        'request' => 'AI 团队产品库产品提报',
        'request_hint' => '如果你是 AI 产品库选的品，这边提交你选的品跟你的落地页',
        'review' => 'AI 产品投放链接审核',
    ],

    'filters' => [
        'keyword' => '关键词',
        'keyword_placeholder' => '提报编号 / AI 产品 / 投放链接',
        'per_page' => '每页条数',
    ],

    'common' => [
        'all' => '全部',
        'search' => '搜索',
        'reset' => '重置',
        'close' => '关闭',
        'cancel' => '取消',
        'no_records' => '暂无数据',
        'pagination_summary' => '共 :total 条，当前第 :current/:last 页',
    ],

    'operation' => [
        'title' => 'AI 团队产品库产品提报',
        'subtitle' => '如果你是 AI 产品库选的品，这边提交你选的品跟你的落地页',
        'create_button' => '发起提报',
        'create_title' => '发起 AI 产品需求提报',
        'submit_success' => '提报成功，提报编号：:report_no',
        'list_title' => '提报记录',
    ],

    'review' => [
        'title' => 'AI 产品投放链接审核',
        'subtitle' => '审核运营提交的 AI 产品投放链接需求',
        'list_title' => '审核列表',
        'approve_button' => '审核通过',
        'reject_button' => '审核不通过',
        'approve_confirm' => '确认审核通过该条 AI 产品需求提报吗？',
        'reject_modal_title' => '审核不通过',
        'reject_submit' => '确认不通过',
        'approve_success' => '已通过：:report_no',
        'reject_success' => '已驳回：:report_no',
        'already_reviewed' => '该记录已完成审核，无法重复操作',
        'no_selection' => '请先选择要操作的记录',
    ],

    'fields' => [
        'report_no' => '提报编号',
        'ai_product' => 'AI 产品',
        'ai_product_placeholder' => '请选择 AI 产品',
        'placement_link' => '投放链接',
        'placement_link_placeholder' => '请填写投放链接（以 http:// 或 https:// 开头）',
        'reporter' => '提报人',
        'remark' => '备注信息',
        'review_status' => '审核状态',
        'review_remark' => '审核备注',
        'reviewer' => '审核人',
        'submitted_at' => '提报时间',
        'reviewed_at' => '审核时间',
        'operation' => '操作',
        'category' => '分类',
        'description' => '产品说明',
    ],

    'status' => [
        'pending' => '待审核',
        'approved' => '审核通过',
        'rejected' => '审核不通过',
    ],

    'validation' => [
        'product_invalid' => '所选 AI 产品不存在或已停用，请重新选择',
        'link_invalid' => '投放链接格式不正确，需以 http:// 或 https:// 开头',
        'remark_required' => '审核不通过时必须填写审核备注',
    ],
];
