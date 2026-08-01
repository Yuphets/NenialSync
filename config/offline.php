<?php

return [
    'enabled' => (bool) env('LOCAL_OFFLINE_MODE', false),
    'node_id' => env('LOCAL_NODE_ID', 'store-main'),
    'cloud_url' => rtrim((string) env('CLOUD_URL', ''), '/'),
    'sync_token' => env('SYNC_SHARED_SECRET'),
    'privileged_secret' => env('SYNC_PRIVILEGED_SECRET') ?: env('APP_KEY'),
    'allowed_node_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SYNC_ALLOWED_NODE_IDS', env('LOCAL_NODE_ID', 'store-main')))
    ))),
    'timeout' => (int) env('SYNC_TIMEOUT', 20),
];
