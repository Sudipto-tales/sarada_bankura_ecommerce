<?php

/**
 * Admin permissions (docs/admin-access.md §2) and the 8 starting roles (§3).
 *
 * Permissions live here in code, grouped by section; only role ↔ permission links are stored in
 * the database (role_permissions). New modules add their permissions to 'sections'.
 * Tier: view = read only · act = day-to-day actions · sensitive = Owner by default.
 *
 * The Owner role is special: it always has every permission (also ones added later), cannot be
 * deleted, and at least one active Owner must always exist.
 */
return [
    'sections' => [
        'dashboard' => ['label' => 'Dashboard', 'permissions' => [
            'dashboard.view' => ['View dashboard', 'view'],
            'dashboard.revenue' => ['See money tiles', 'sensitive'],
        ]],
        'catalog' => ['label' => 'Products & catalogue', 'permissions' => [
            'catalog.view' => ['View products & catalogue', 'view'],
            'catalog.edit' => ['Add / edit products & catalogue', 'act'],
            'catalog.import' => ['Bulk upload', 'act'],
            'catalog.delete' => ['Delete products & catalogue items', 'sensitive'],
        ]],
        'reviews' => ['label' => 'Reviews & comments', 'permissions' => [
            'reviews.view' => ['View reviews', 'view'],
            'reviews.moderate' => ['Publish / flag / archive', 'act'],
            'reviews.reply' => ['Reply to reviews', 'act'],
            'reviews.delete' => ['Delete reviews', 'sensitive'],
        ]],
        'enquiries' => ['label' => 'Enquiries', 'permissions' => [
            'enquiries.view' => ['View enquiries', 'view'],
            'enquiries.reply' => ['Reply', 'act'],
            'enquiries.assign' => ['Assign', 'act'],
        ]],
        'customers' => ['label' => 'Customers', 'permissions' => [
            'customers.view' => ['View customers', 'view'],
            'customers.edit' => ['Add / edit customers', 'act'],
            'customers.segments' => ['Manage segments & tags', 'act'],
            'customers.export' => ['Export customers', 'sensitive'],
            'customers.delete' => ['Delete customers', 'sensitive'],
        ]],
        'orders' => ['label' => 'Orders', 'permissions' => [
            'orders.view' => ['View orders', 'view'],
            'orders.accept' => ['Accept / create orders', 'act'],
            'orders.reject' => ['Decline orders', 'act'],
            'orders.dispatch' => ['Dispatch', 'act'],
            'orders.deliver' => ['Mark delivered', 'act'],
            'orders.documents' => ['Invoices & packing slips', 'act'],
        ]],
        'payments' => ['label' => 'Payments', 'permissions' => [
            'payments.view' => ['View payments', 'view'],
            'payments.verify' => ['Verify / record payments', 'act'],
            'payments.reject' => ['Reject submissions', 'act'],
            'payments.request' => ['Send payment requests', 'act'],
            'payments.refund' => ['Refunds & voids', 'sensitive'],
            'payments.accounts' => ['Receiving accounts', 'sensitive'],
        ]],
        'returns' => ['label' => 'Returns', 'permissions' => [
            'returns.view' => ['View returns', 'view'],
            'returns.decide' => ['Accept / decline returns', 'act'],
            'returns.receive' => ['Receive returned goods', 'act'],
        ]],
        'inventory' => ['label' => 'Inventory', 'permissions' => [
            'inventory.view' => ['View stock', 'view'],
            'inventory.receive' => ['Receive stock', 'act'],
            'inventory.adjust' => ['Adjust stock', 'act'],
            'inventory.settings' => ['Stock settings', 'sensitive'],
        ]],
        'rules' => ['label' => 'Rules & settings', 'permissions' => [
            'rules.view' => ['View business rules', 'view'],
            'rules.edit' => ['Edit business rules', 'act'],
            'settings.system' => ['System settings', 'sensitive'],
            'settings.payment' => ['Payment settings', 'sensitive'],
        ]],
        'staff' => ['label' => 'Staff & roles', 'permissions' => [
            'staff.manage' => ['Add / reset / disable staff', 'sensitive'],
            'roles.manage' => ['Edit roles', 'sensitive'],
            'audit.view' => ['Audit log', 'sensitive'],
        ]],
        'media' => ['label' => 'Media & notifications', 'permissions' => [
            'media.view' => ['View media library', 'view'],
            'media.edit' => ['Upload / edit media', 'act'],
        ]],
    ],

    // Seeded by Roles::sync() (admin:install). Existing roles are never overwritten — the owner may
    // have edited them. 'owner' = every permission ('*').
    'roles' => [
        'owner' => ['name' => 'Owner', 'description' => 'Shop owner — everything, incl. staff & roles, receiving accounts, refunds, settings and the audit log.', 'system' => true, 'permissions' => ['*']],
        'review-moderator' => ['name' => 'Review moderator', 'description' => 'Handles reviews & comments (publish / flag / archive, reply). Cannot delete reviews.', 'permissions' => [
            'dashboard.view', 'reviews.view', 'reviews.moderate', 'reviews.reply', 'catalog.view', 'customers.view',
        ]],
        'order-manager' => ['name' => 'Order manager', 'description' => 'Accepts and ships orders; decides and receives returns.', 'permissions' => [
            'dashboard.view', 'orders.view', 'orders.accept', 'orders.reject', 'orders.dispatch', 'orders.deliver', 'orders.documents',
            'returns.view', 'returns.decide', 'returns.receive', 'inventory.view', 'customers.view', 'payments.view', 'payments.request',
        ]],
        'payment-verifier' => ['name' => 'Payment verifier', 'description' => 'Checks UPI / UTR / screenshots. No refunds, no receiving accounts.', 'permissions' => [
            'dashboard.view', 'payments.view', 'payments.verify', 'payments.reject', 'payments.request', 'orders.view',
        ]],
        'rules-manager' => ['name' => 'Rules manager', 'description' => 'Keeps business rules correct: tax rules, advance %, return policies, stock thresholds, segment rules.', 'permissions' => [
            'dashboard.view', 'rules.view', 'rules.edit',
        ]],
        'catalogue-manager' => ['name' => 'Catalogue manager', 'description' => 'Adds and edits products, bulk upload.', 'permissions' => [
            'dashboard.view', 'catalog.view', 'catalog.edit', 'catalog.import', 'inventory.view', 'reviews.view', 'media.view', 'media.edit',
        ]],
        'stock-keeper' => ['name' => 'Stock keeper', 'description' => 'Godown / showroom stock; dispatches orders.', 'permissions' => [
            'dashboard.view', 'inventory.view', 'inventory.receive', 'inventory.adjust', 'catalog.view', 'orders.view', 'orders.dispatch',
        ]],
        'customer-support' => ['name' => 'Customer support', 'description' => 'Phone / WhatsApp / walk-in: enquiries, customers, order look-ups, review replies.', 'permissions' => [
            'dashboard.view', 'enquiries.view', 'enquiries.reply', 'enquiries.assign', 'customers.view', 'customers.edit', 'orders.view', 'reviews.view', 'reviews.reply',
        ]],
    ],
];
