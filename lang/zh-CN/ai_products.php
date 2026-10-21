<?php

return [
    'menus' => [
        'request' => '已有产品投放连接提报',
        'request_hint' => 'AI开品库里面有的或者查重有的',
        'review' => '已有产品投放连接审核',
    ],

    'filters' => [
        'keyword' => '关键词',
        'keyword_placeholder' => '提报编号 / 投放链接',
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
        'title' => '已有产品投放连接提报',
        'subtitle' => '',
        'create_button' => '发起提报',
        'create_title' => '发起已有产品投放连接提报',
        'submit_success' => '提报成功，提报编号：:report_no',
        'update_success' => '已重新提交审核，提报编号：:report_no',
        'edit_button' => '重新编辑',
        'edit_title' => '重新编辑投放链接',
        'edit_not_allowed' => '只有审核不通过的记录才能重新编辑',
        'list_title' => '提报记录',
    ],

    'review' => [
        'title' => '已有产品投放连接审核',
        'subtitle' => '审核运营提交的已有产品投放连接',
        'list_title' => '审核列表',
        'approve_button' => '审核通过',
        'reject_button' => '审核不通过',
        'approve_confirm' => '确认审核通过该条提报吗？',
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
