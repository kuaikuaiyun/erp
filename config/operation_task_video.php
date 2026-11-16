<?php

return [
    'x_accel_enabled' => env('OPERATION_VIDEO_X_ACCEL_ENABLED', false),
    'internal_prefix' => '/_operation_private/',
    'ffmpeg' => env('OPERATION_VIDEO_FFMPEG', 'ffmpeg'),
    'ffprobe' => env('OPERATION_VIDEO_FFPROBE', 'ffprobe'),
    'timeout' => 300,
];
