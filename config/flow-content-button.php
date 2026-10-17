<?php

return [
    // Connection ID => url/reply/copy => verified and measured max_text_length,
    // max_label_length, max_value_length (url/copy), max_reply_id_length (reply).
    // Keep disabled until real rendering and reply webhooks have been verified.
    'connections' => env('APP_ENV') === 'local'
        ? (json_decode((string) env('CHAIT_LOCAL_FLOW_CONTENT_BUTTON_CONNECTIONS', '{}'), true) ?: [])
        : [],
];
