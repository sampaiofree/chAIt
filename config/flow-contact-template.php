<?php

return [
    // Enable only after received-content equality is proven on this connection.
    // Synthetic test profiles are not evidence of UAZAPI compatibility.
    'connections' => env('APP_ENV') === 'local'
        ? (json_decode((string) env('CHAIT_LOCAL_FLOW_CONTACT_TEMPLATE_CONNECTIONS', '{}'), true) ?: [])
        : [],
];
