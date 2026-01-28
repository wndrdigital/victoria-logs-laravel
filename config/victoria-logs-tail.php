<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VictoriaLogs Endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL of your VictoriaLogs instance. The /insert/jsonline path
    | will be appended automatically.
    |
    */
    'endpoint' => env('VICTORIALOGS_ENDPOINT', 'http://localhost:9428'),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | The application name to tag all log entries with. This helps identify
    | logs from different applications in VictoriaLogs.
    |
    */
    'app_name' => env('VICTORIALOGS_APP_NAME', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for HTTP requests to VictoriaLogs.
    |
    */
    'timeout' => env('VICTORIALOGS_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Basic Authentication
    |--------------------------------------------------------------------------
    |
    | Optional basic authentication credentials for VictoriaLogs.
    | Leave username empty to disable basic auth.
    |
    */
    'auth' => [
        'username' => env('VICTORIALOGS_AUTH_USERNAME'),
        'password' => env('VICTORIALOGS_AUTH_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Fields
    |--------------------------------------------------------------------------
    |
    | Additional fields to include with every log entry. Useful for adding
    | environment, hostname, or other metadata.
    |
    */
    'extra_fields' => [
        // 'environment' => env('APP_ENV'),
        // 'hostname' => gethostname(),
    ],
];
