<?php

return [
    'ffmpeg' => env('AIKAIPIN_VIDEO_FFMPEG', env('OPERATION_VIDEO_FFMPEG', 'ffmpeg')),
    'timeout' => (int) env('AIKAIPIN_VIDEO_TRANSCODE_TIMEOUT', 600),
    // Keep the uploaded source file by default. Some production servers do
    // not provide FFmpeg and HEVC conversion must never block product saves.
    // Browsers that cannot decode the source video show the existing
    // download fallback instead.
    'auto_transcode' => env('AIKAIPIN_VIDEO_AUTO_TRANSCODE', false),
    // Product-library videos use their own delivery switch. Reusing the
    // review-center X-Accel location makes production return an empty/404
    // response when that Nginx internal alias does not include this storage
    // directory. PHP byte-range streaming is the safe default.
    'x_accel_enabled' => env('AIKAIPIN_VIDEO_X_ACCEL_ENABLED', false),
    'internal_prefix' => env('AIKAIPIN_VIDEO_INTERNAL_PREFIX', '/_aikaipin_private/'),
];
