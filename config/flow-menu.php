<?php

return [
    // Enable only after verifying real button/list selection events on this connection.
    // connection ID => format (button/list) => verified, max_options, max_label_length.
    // These are measured provider limits, not inferred WhatsApp API limits.
    'connections' => env('APP_ENV') === 'local'
        ? (json_decode((string) env('CHAIT_LOCAL_FLOW_MENU_CONNECTIONS', '{}'), true) ?: [])
        : [],
    'confirmation_attempts' => 24,
    'confirmation_interval_seconds' => 5,
];
