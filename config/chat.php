<?php

return [
    'enabled' => (bool) env('CHAIT_REALTIME_ENABLED', false),
    'page_size' => 200,
    'preview_characters' => 200,
    'max_webhook_bytes' => 262144,
    'temporary_hours' => 24,
    'requests_per_second' => 8,
    'concurrent_requests' => 2,
    'workspace_limits' => ['inbound' => 2, 'automations' => 1, 'ai' => 1, 'media' => 1],
];
