<?php

/**
 * Business identity used on invoices, emails, the storefront footer and WhatsApp messages.
 * Read it with business('name'), business('address.city'), business('tax.gst_rate').
 *
 * Placeholder until System / Payment settings are planned. Values come from .env so the
 * production copy can differ without code changes. No DB table on purpose (see docs/README.md).
 */
return [
    'name' => env('BUSINESS_NAME', 'Maa Sarada Marble & Stone'),
    'legal_name' => env('BUSINESS_LEGAL_NAME', env('BUSINESS_NAME', 'Maa Sarada Marble & Stone')),

    'address' => [
        'line1' => env('BUSINESS_ADDRESS_LINE1', ''),
        'line2' => env('BUSINESS_ADDRESS_LINE2', ''),
        'city' => env('BUSINESS_CITY', 'Bankura'),
        'state' => env('BUSINESS_STATE', 'West Bengal'),
        'state_code' => env('BUSINESS_STATE_CODE', '19'), // GST state code for West Bengal
        'pincode' => env('BUSINESS_PINCODE', ''),
        'country' => env('BUSINESS_COUNTRY', 'India'),
    ],

    'phone' => env('BUSINESS_PHONE', ''),
    'whatsapp' => env('BUSINESS_WHATSAPP', env('BUSINESS_PHONE', '')),
    'email' => env('BUSINESS_EMAIL', ''),
    'website' => env('APP_URL', ''),

    'gstin' => env('BUSINESS_GSTIN', ''),
    'pan' => env('BUSINESS_PAN', ''),

    // Path under public/ (use with base_url()).
    'logo' => env('BUSINESS_LOGO', 'assets/image/logo-full.png'), // empty = text logo

    'currency' => 'INR',
    'timezone' => 'Asia/Kolkata',

    'tax' => [
        // Owner decision 2026-11-24: GST is added on top (total = subtotal − discount + GST).
        'prices_include_tax' => filter_var(env('BUSINESS_PRICES_INCLUDE_TAX', false), FILTER_VALIDATE_BOOLEAN),
        'gst_rate' => (float) env('BUSINESS_GST_RATE', 18),
        'hsn_default' => env('BUSINESS_HSN_DEFAULT', '6802'), // worked monumental/building stone
    ],

    // Indian financial year starts in April (used for invoice numbering).
    'fy_start_month' => 4,

    // Document number series (document_counters): PREFIX/26-27/0001, gapless per financial year.
    'numbering' => [
        'order' => env('BUSINESS_ORDER_PREFIX', 'MSO'),
        'invoice' => env('BUSINESS_INVOICE_PREFIX', 'MSM'),
        'payment' => 'RCP',
        'refund' => 'RFD',
        'attempt' => 'PAT',
        'return' => 'RET',
        'enquiry' => 'ENQ',
    ],

    'delivery' => [
        'otp_days' => (int) env('BUSINESS_DELIVERY_OTP_DAYS', 7),   // a delivery OTP stays valid this long
        'otp_attempts' => 5,                                         // wrong tries before a new OTP is needed
    ],
];
