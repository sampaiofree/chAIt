<?php

return [
    'max_body_bytes' => (int) env('EXTERNAL_WEBHOOK_MAX_BODY_BYTES', 262144),
    'max_depth' => (int) env('EXTERNAL_WEBHOOK_MAX_DEPTH', 32),
    'max_fields' => (int) env('EXTERNAL_WEBHOOK_MAX_FIELDS', 1000),
    'requests_per_minute' => (int) env('EXTERNAL_WEBHOOK_REQUESTS_PER_MINUTE', 120),
    'receipt_retention_days' => 30,
];
