<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'flow_manager' => [
        'rabbitmq_url' => env('RABBITMQ_URL', 'amqp://guest:guest@rabbitmq:5672/'),
        'command_queue' => env('FLOW_MANAGER_COMMAND_QUEUE', 'flow-manager.commands'),
        'response_queue' => env('FLOW_MANAGER_RESPONSE_QUEUE') ?: null,
        'event_exchange' => env('FLOW_MANAGER_EVENT_EXCHANGE', 'flow-manager.events'),
        'event_queue' => env('FLOW_MANAGER_EVENT_QUEUE', 'flow-manager.ui.events'),
        'timeout' => (int) env('FLOW_MANAGER_TIMEOUT', 8000),
        'response_timeout' => (int) env('FLOW_MANAGER_RESPONSE_TIMEOUT', 45000),
        'test_run_id' => env('KAWAFLOW_TEST_RUN_ID'),
    ],
];
