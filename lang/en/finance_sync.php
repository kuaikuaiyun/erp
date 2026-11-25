<?php

return [
    'errors' => [
        'UNAUTHORIZED' => 'The finance API credential is invalid or expired.',
        'HTTPS_REQUIRED' => 'Use HTTPS to access the finance API.',
        'NOT_FOUND' => 'The requested finance endpoint or record was not found.',
        'METHOD_NOT_ALLOWED' => 'The finance API only accepts read requests.',
        'INVALID_REQUEST' => 'The finance API query parameters are invalid.',
        'INVALID_CURSOR' => 'The sync cursor is invalid. Use a cursor issued for this endpoint.',
        'RATE_LIMITED' => 'Too many finance API requests. Try again later.',
        'SYNC_NOT_READY' => 'Finance sync data is being prepared. Try again later.',
        'SERVICE_UNAVAILABLE' => 'The finance API is temporarily unavailable. Try again later.',
        'INTERNAL_ERROR' => 'The finance API request failed. Provide the request ID for support.',
    ],
    'log_summary' => [
        'success' => 'The logistics task completed.',
        'failed' => 'The logistics task failed.',
        'skipped' => 'The logistics task was skipped.',
        'pending' => 'The logistics task is pending.',
    ],
    'command' => [
        'description' => 'Publish allowlisted data for the finance integration.',
        'invalid_batch' => 'Batch size must be between 1 and 500, and batch count between 1 and 200.',
        'published' => 'Published :count finance sync records.',
    ],
];
