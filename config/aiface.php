<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WebSocket Server Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the AiFace WebSocket / WSS server engine.
    | Devices initiate connections to ws://<host>:<port><path>
    |
    */
    'server' => [
        'host' => env('AIFACE_WS_HOST', '0.0.0.0'),
        'port' => (int) env('AIFACE_WS_PORT', 7788),
        'path' => env('AIFACE_WS_PATH', '/pub/chat'),
        'timeout' => (int) env('AIFACE_WS_TIMEOUT', 30),
        'ping_interval' => (int) env('AIFACE_WS_PING_INTERVAL', 10),
        
        // Command response wait timeout in seconds
        'command_timeout' => (int) env('AIFACE_COMMAND_TIMEOUT', 10),

        // Local IPC bridge for CLI / PHP-FPM communication (default: 127.0.0.1:7789)
        'ipc_host' => env('AIFACE_IPC_HOST', '127.0.0.1'),
        'ipc_port' => (int) env('AIFACE_IPC_PORT', 7789),

        // SSL / TLS configuration for WSS (port 433)
        'ssl' => [
            'enabled' => (bool) env('AIFACE_WSS_ENABLED', false),
            'local_cert' => env('AIFACE_SSL_CERT', null),
            'local_pk' => env('AIFACE_SSL_KEY', null),
            'passphrase' => env('AIFACE_SSL_PASSPHRASE', null),
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Device Registration Defaults
    |--------------------------------------------------------------------------
    |
    | Parameters returned to the device during the "reg" handshake.
    |
    */
    'registration' => [
        // Re-registration interval sent to the device (in seconds)
        'tryseconds' => (int) env('AIFACE_REG_TRYSECONDS', 300),

        // Synchronize cloud time with server time upon registration
        'auto_sync_time' => (bool) env('AIFACE_AUTO_SYNC_TIME', true),

        // Suppress device reports if true
        'nosenduser' => (bool) env('AIFACE_NO_SEND_USER', false),
        'nosendlog' => (bool) env('AIFACE_NO_SEND_LOG', false),
        'nosendimage' => (bool) env('AIFACE_NO_SEND_IMAGE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Reports Response Defaults
    |--------------------------------------------------------------------------
    |
    | Default response parameters returned to devices on active reports.
    |
    */
    'reports' => [
        'sendlog' => [
            'auto_mark' => true, // Mark log as read on device so it is not resent
            'default_access' => 1, // 1 = grant access, 0 = deny
            'message' => 'Welcome',
            'fontsize' => 24,
            'text_color' => [0, 255, 0], // RGB
            'voice' => '',
            'voiceindex' => 0,
            'questionnaire' => false,
        ],
        'sendpin' => [
            'auto_authorize' => true,
            'default_access' => 1,
            'message' => 'Access Granted',
            'fontsize' => 24,
            'text_color' => [0, 255, 0],
            'voice' => '',
            'voiceindex' => 0,
        ],
        'sendqrcode' => [
            'auto_authorize' => true,
            'default_access' => 1,
            'message' => 'QR Verified',
            'fontsize' => 24,
            'text_color' => [0, 255, 0],
            'voice' => '',
            'voiceindex' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database & Storage Settings
    |--------------------------------------------------------------------------
    |
    | Automatically persist registered devices, punch logs, and user credentials.
    |
    */
    'storage' => [
        'enabled' => (bool) env('AIFACE_STORAGE_ENABLED', true),
        'table_prefix' => env('AIFACE_TABLE_PREFIX', 'aiface_'),
        'store_photos' => (bool) env('AIFACE_STORE_PHOTOS', true),
        'photo_disk' => env('AIFACE_PHOTO_DISK', 'public'),
        'photo_path' => env('AIFACE_PHOTO_PATH', 'aiface_photos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | External Webhooks
    |--------------------------------------------------------------------------
    |
    | Forward hardware events asynchronously to external HTTP webhook endpoints.
    |
    */
    'webhooks' => [
        'enabled' => (bool) env('AIFACE_WEBHOOKS_ENABLED', false),
        'url' => env('AIFACE_WEBHOOK_URL', ''),
        'secret' => env('AIFACE_WEBHOOK_SECRET', ''),
        'timeout' => 5,
        'events' => [
            'device.connected',
            'device.registered',
            'attendance.logged',
            'attendance.clockin',
            'attendance.clockout',
            'user.pushed',
            'pin.received',
            'qrcode.scanned',
            'gps.received',
            'intercom.call',
            'command.response',
            'device.disconnected',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API Settings
    |--------------------------------------------------------------------------
    |
    | Expose HTTP REST endpoints for external systems / frontends to trigger commands.
    |
    */
    'api' => [
        'enabled' => (bool) env('AIFACE_API_ENABLED', true),
        'prefix' => env('AIFACE_API_PREFIX', 'api/aiface'),
        'middleware' => ['api'],
        'auth_token' => env('AIFACE_API_TOKEN', null), // Optional Bearer token
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive Admin Dashboard UI
    |--------------------------------------------------------------------------
    |
    | Built-in web dashboard to view online devices, live punches, and run any command.
    |
    */
    'dashboard' => [
        'enabled' => (bool) env('AIFACE_DASHBOARD_ENABLED', true),
        'prefix' => env('AIFACE_DASHBOARD_PREFIX', 'aiface/dashboard'),
        'middleware' => ['web'],
    ],
];
