<?php

return [
    'enabled' => env('STACKRADAR_ENABLED', true),
    'project_token' => env('STACKRADAR_PROJECT_TOKEN'),
    'environment' => env('STACKRADAR_ENVIRONMENT', env('APP_ENV', 'production')),
    'endpoint' => env('STACKRADAR_ENDPOINT'),
    'interval' => 3600,
    'retry_after' => 60,
    'timeout' => 2.0,
    'cache_store' => env('STACKRADAR_CACHE_STORE', 'file'),
    // Resolve the default instance at runtime, not while building the config cache.
    'instance_id' => env('STACKRADAR_INSTANCE_ID'),
    'release' => env('STACKRADAR_RELEASE'),
];
