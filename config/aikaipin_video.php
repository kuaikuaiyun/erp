<?php

return [
    'ffmpeg' => env('AIKAIPIN_VIDEO_FFMPEG', env('OPERATION_VIDEO_FFMPEG', 'ffmpeg')),
    'timeout' => (int) env('AIKAIPIN_VIDEO_TRANSCODE_TIMEOUT', 600),
    'auto_transcode' => env('AIKAIPIN_VIDEO_AUTO_TRANSCODE', true),
];
