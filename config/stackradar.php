<?php

return [
    'enabled' => env('STACKRADAR_ENABLED', true),
    'ingest_key' => env('STACKRADAR_INGEST_KEY'),
    'environment' => env('STACKRADAR_ENVIRONMENT'),
    'service' => env('STACKRADAR_SERVICE'),
    'release' => env('STACKRADAR_RELEASE'),
    'artifact' => env('STACKRADAR_ARTIFACT'),
    'ingest_url' => env('STACKRADAR_INGEST_URL', 'https://ingest.stackradar.com'),
    'startup_delay' => 5,
    'startup_jitter' => 30,
    'timeout' => 2.0,
    'cache_store' => env('STACKRADAR_CACHE_STORE', 'file'),
];
