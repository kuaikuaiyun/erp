<?php

return [
    'errors' => [
        'UNAUTHORIZED' => '财务接口凭证无效或已过期。',
        'HTTPS_REQUIRED' => '请通过 HTTPS 访问财务接口。',
        'NOT_FOUND' => '未找到所请求的财务接口或记录。',
        'METHOD_NOT_ALLOWED' => '财务接口仅支持读取请求。',
        'INVALID_REQUEST' => '财务接口的查询参数无效。',
        'INVALID_CURSOR' => '同步游标无效，请使用对应接口返回的游标。',
        'RATE_LIMITED' => '财务接口请求频率过高，请稍后重试。',
        'SYNC_NOT_READY' => '财务同步数据正在准备，请稍后重试。',
        'SERVICE_UNAVAILABLE' => '财务接口暂时不可用，请稍后重试。',
        'INTERNAL_ERROR' => '财务接口处理失败，请提供请求编号以便排查。',
    ],
    'log_summary' => [
        'success' => '物流任务已完成。',
        'failed' => '物流任务执行失败。',
        'skipped' => '物流任务已跳过。',
        'pending' => '物流任务待处理。',
    ],
    'command' => [
        'description' => '发布财务对接白名单数据。',
        'invalid_batch' => '每批条数须为 1 至 500，批次数须为 1 至 200。',
        'published' => '本次发布 :count 条财务同步记录。',
    ],
];
