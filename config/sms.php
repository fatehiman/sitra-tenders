<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS driver
    |--------------------------------------------------------------------------
    |
    | "log" writes messages to the log instead of sending them — used for
    | local development so registration/OTP works without real credentials.
    | Switch to "msgway" (or any other driver registered on App\Sms\SmsManager)
    | once real credentials are available.
    |
    */

    'default' => env('SMS_DRIVER', 'log'),

    'drivers' => [

        'msgway' => [
            'api_key' => env('MSGWAY_API_KEY'),
            'base_url' => env('MSGWAY_BASE_URL', 'https://api.msgway.com'),

            // Semantic message name => msgway template ID. Keep this as the
            // single source of truth for template IDs — never hard-code an
            // ID at a call site (see msgway.md's own recommendation).
            'templates' => [
                // Built-in default Persian OTP template, no panel
                // registration/approval needed: "کد تایید شما: [code]".
                'otp' => (int) env('MSGWAY_TEMPLATE_OTP', 3),
            ],
        ],

        'log' => [],

    ],

];
