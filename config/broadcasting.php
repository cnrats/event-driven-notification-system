<?php

declare(strict_types=1);

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        // Status changes are published to the Reverb WebSocket server.
        // The values are demo defaults; see docker/app.env.
        'reverb' => [
            'driver' => 'reverb',
            'key' => (string) env('REVERB_APP_KEY', 'notification-system-key'),
            'secret' => (string) env('REVERB_APP_SECRET', 'notification-system-secret'),
            'app_id' => (string) env('REVERB_APP_ID', 'notification-system'),
            'options' => [
                'host' => env('REVERB_HOST', 'reverb'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
