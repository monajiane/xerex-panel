<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | The default broadcaster is used when the `Broadcast` facade is called
    | without an explicit connection name. We use the `reverb` driver in
    | production (run via `php artisan reverb:start`) and fall back to `log`
    | in local dev so events are still emitted to the application log.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [

        'reverb' => [
            'driver'   => 'reverb',
            'key'      => env('REVERB_APP_KEY'),
            'secret'   => env('REVERB_APP_SECRET'),
            'app_id'   => env('REVERB_APP_ID'),
            'options'  => [
                'host'   => env('REVERB_HOST', '0.0.0.0'),
                'port'   => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Verify TLS certs in production, skip in dev.
                'verify' => env('REVERB_VERIFY_TLS', true),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key'    => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'useTLS'  => true,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],

];
