<?php

/**
 * WhatsApp channel — Meta Cloud API direct (docs/whatsapp.md).
 *
 * Credentials come from .env ONLY and are never shown or edited in the admin panel.
 * The channel is ACTIVE only when WHATSAPP_ENABLED=true and all five required values are set;
 * otherwise it is DORMANT and every flow keeps its wa.me / email behaviour.
 */
$bool = static fn($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN);

return [
    'enabled' => $bool(env('WHATSAPP_ENABLED', false)),

    // Required (all five) for the channel to be active.
    'access_token' => (string) env('WHATSAPP_ACCESS_TOKEN', ''),
    'phone_number_id' => (string) env('WHATSAPP_PHONE_NUMBER_ID', ''),
    'business_account_id' => (string) env('WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
    'app_secret' => (string) env('WHATSAPP_APP_SECRET', ''),
    'webhook_verify_token' => (string) env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', ''),

    // Optional.
    'api_version' => (string) (env('WHATSAPP_API_VERSION', '') ?: 'v21.0'),
    'default_lang' => (string) (env('WHATSAPP_DEFAULT_LANG', '') ?: 'en'),
    'test_number' => (string) env('WHATSAPP_TEST_NUMBER', ''),

    'graph_url' => 'https://graph.facebook.com',
    'timeout' => 8, // seconds, per call (sends are synchronous — there is no queue)

    /*
     | App event → Meta template. `name` is the template name approved in WhatsApp Manager (a rename
     | is a config change only); `params` is the body variable order {{1}}, {{2}}…; `button`:
     |   url        dynamic URL button, we pass only the token (https://<domain>/order/{{1}})
     |   copy_code  authentication "Copy code" button (gets the code)
     |   null       no button
     */
    'templates' => [
        'auth_otp' => ['name' => 'auth_otp', 'category' => 'authentication', 'params' => ['code'], 'button' => 'copy_code'],
        'order_confirmed' => ['name' => 'order_confirmed', 'category' => 'utility', 'params' => ['name', 'order_number', 'total'], 'button' => 'url'],
        'payment_request' => ['name' => 'payment_request', 'category' => 'utility', 'params' => ['name', 'amount', 'order_number'], 'button' => 'url'],
        'payment_received' => ['name' => 'payment_received', 'category' => 'utility', 'params' => ['name', 'amount', 'order_number', 'balance'], 'button' => 'url'],
        'payment_rejected' => ['name' => 'payment_rejected', 'category' => 'utility', 'params' => ['name', 'order_number', 'reason'], 'button' => 'url'],
        'order_dispatched' => ['name' => 'order_dispatched', 'category' => 'utility', 'params' => ['name', 'order_number', 'dispatch_date'], 'button' => 'url'],
        'order_delivered' => ['name' => 'order_delivered', 'category' => 'utility', 'params' => ['name', 'order_number'], 'button' => 'url'],
        'return_update' => ['name' => 'return_update', 'category' => 'utility', 'params' => ['name', 'return_number', 'status'], 'button' => 'url'],
        'staff_invite' => ['name' => 'staff_invite', 'category' => 'utility', 'params' => ['name', 'login_link'], 'button' => null],
        'offer_broadcast' => ['name' => 'offer_broadcast', 'category' => 'marketing', 'params' => ['name', 'offer'], 'button' => 'url'],
    ],

    // Automatic sends from the order / payment flows (only while active). Moves to
    // System settings → Channels when that page is planned.
    'auto' => [
        'order_confirmed' => true,
        'order_dispatched' => true,
        'order_delivered' => true,
        'payment_received' => true,
        'payment_rejected' => true,
        'delivery_otp' => true,
    ],

    // OTP limits per phone (authentication templates).
    'otp_limits' => ['per_minute' => 1, 'per_hour' => 5],

    // Approximate Meta per-message rates in ₹ (India) — estimate only, check before go-live.
    'rates' => ['authentication' => 0.13, 'utility' => 0.13, 'marketing' => 0.86, 'service' => 0.0],
];
