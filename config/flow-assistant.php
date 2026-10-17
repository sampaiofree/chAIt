<?php

return [
    'default_model' => 'gpt-6.1-sol',
    'models' => ['gpt-6.1-sol', 'gpt-6-luna', 'gpt-6-astra'],
    'message_limit' => 8000,
    'operations_per_batch' => 20,
    'max_cycles' => 30,
    'run_seconds' => 150,
    'context_messages' => 40,
    'context_characters' => 250000,
    'max_output_tokens' => 12000,
];
