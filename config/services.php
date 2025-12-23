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

    'turkey_sms' => [
        'api_key' => env('TURKEY_SMS_API_KEY'),
        'language' => env('TURKEY_SMS_LANGUAGE', 1), // 0=English, 1=Turkish, 2=Arabic
        'sender_ids' => [
            'turkey' => env('TURKEY_SMS_SENDER_ID_TURKEY', 'DEFAULT'),
            'default' => env('TURKEY_SMS_SENDER_ID_DEFAULT', 'DEFAULT'),
        ],
        'test_phone' => env('TURKEY_SMS_TEST_PHONE'), // Override all SMS to this number for testing
    ],

];
