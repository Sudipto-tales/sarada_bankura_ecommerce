<?php

/**
 * Business identity used on invoices, emails, the storefront footer and WhatsApp messages.
 * Read it with business('name'), business('address.city'), business('tax.gst_rate').
 *
 * Placeholder until System / Payment settings are planned. Values come from .env so the
 * production copy can differ without code changes. No DB table on purpose (see docs/roadmap.md 0.7).
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
    'logo' => env('BUSINESS_LOGO', ''), // empty = text logo

    'currency' => 'INR',
    'timezone' => 'Asia/Kolkata',

    'tax' => [
        'prices_include_tax' => filter_var(env('BUSINESS_PRICES_INCLUDE_TAX', true), FILTER_VALIDATE_BOOLEAN),
        'gst_rate' => (float) env('BUSINESS_GST_RATE', 18),
        'hsn_default' => env('BUSINESS_HSN_DEFAULT', '6802'), // worked monumental/building stone
    ],

    // Indian financial year starts in April (used for invoice numbering).
    'fy_start_month' => 4,
];
