<?php

return [
    'enabled' => (bool) env('LOCAL_OFFLINE_MODE', false),
    'node_id' => env('LOCAL_NODE_ID', 'store-main'),
    'cloud_url' => rtrim((string) env('CLOUD_URL', ''), '/'),
    'sync_token' => env('SYNC_SHARED_SECRET'),
    'timeout' => (int) env('SYNC_TIMEOUT', 20),
];
