<?php

// Configuration for twdnhfr/laravel-deepagents.
return [

    /*
    |--------------------------------------------------------------------------
    | Default storage backend
    |--------------------------------------------------------------------------
    |
    | Where the agent's artifacts (offloaded large tool outputs, scratchpad
    | content) and memory files are stored. Used when an agent does not set its
    | own backend via DeepAgent::backend(). One of: state, filesystem, database,
    | cache.
    |
    | - state      in-memory; lives for a single run (no persistence)
    | - filesystem real files under a root directory
    | - database   a table (persistent; survives suspend/resume across requests)
    | - cache      any Laravel cache store with a TTL (listing is unsupported)
    |
    */

    'backend' => env('DEEPAGENTS_BACKEND', 'state'),

    'backends' => [

        'state' => [],

        'filesystem' => [
            'root' => storage_path('app/deepagents'),
        ],

        'database' => [
            'table' => 'deepagents_artifacts',
            'connection' => env('DEEPAGENTS_DB_CONNECTION'), // null = default connection
        ],

        'cache' => [
            'store' => env('DEEPAGENTS_CACHE_STORE'), // null = default store
            'ttl' => 60 * 60 * 24,                    // seconds
            'prefix' => 'deepagents:artifact:',
        ],

    ],

];
