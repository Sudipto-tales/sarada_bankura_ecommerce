<?php

/**
 * Web routes.
 *
 * @var \Vayu\Routing\Router $router
 */

use App\Controllers\Admin\AttributeController;
use App\Controllers\Admin\BrandController;
use App\Controllers\Admin\CatalogController;
use App\Controllers\Admin\CustomerController;
use App\Controllers\Admin\CustomerSegmentController;
use App\Controllers\Admin\CustomerTagController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ImportController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\InventoryController;
use App\Controllers\Admin\LookupController;
use App\Controllers\Admin\NotificationController;
use App\Controllers\Admin\OrderController;
use App\Controllers\Admin\OrderDocumentController;
use App\Controllers\Admin\PaymentAccountController;
use App\Controllers\Admin\PaymentController;
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\ReviewController;
use App\Controllers\Admin\TagController;
use App\Controllers\Admin\VariantController;
use App\Controllers\PayController;
use App\Controllers\PublicOrderController;
use App\Controllers\ReviewFormController;
use App\Controllers\WelcomeController;

// Storefront (Stage 8, docs/storefront.md). StoreOpen = launch switch (STOREFRONT_ENABLED; admins preview),
// StoreCustomer = signed-in customer from the session. Fully-qualified names keep this block self-contained.
$router->group(['middleware' => [\App\Middleware\StoreOpen::class, \App\Middleware\StoreCustomer::class]], function ($router): void {
    $router->get('/', [\App\Controllers\Store\HomeController::class, 'index'])->name('home');
    $router->get('/brands', [\App\Controllers\Store\BrandController::class, 'index'])->name('store.brands');
    $router->get('/policy', [\App\Controllers\Store\PageController::class, 'policy'])->name('store.policy');
    $router->get('/policy/{page}', [\App\Controllers\Store\PageController::class, 'policySection'])->name('store.policy.section');
    $router->get('/shop', [\App\Controllers\Store\ShopController::class, 'index'])->name('store.shop');
    $router->get('/shop/{cat}', [\App\Controllers\Store\ShopController::class, 'category'])->name('store.shop.category');
    $router->get('/shop/{cat}/{sub}', [\App\Controllers\Store\ShopController::class, 'room'])->name('store.shop.room');
    $router->get('/product/{slug}', [\App\Controllers\Store\ProductController::class, 'show'])->name('store.product');
    $router->get('/contact', [\App\Controllers\Store\PageController::class, 'contact'])->name('store.contact');
    $router->post('/enquiry', [\App\Controllers\Store\EnquiryController::class, 'store'])->name('store.enquiry');
    // S3 bag, calculator, saved (guest shortlist in localStorage; the server only renders its cards).
    $router->get('/cart', [\App\Controllers\Store\CartController::class, 'show'])->name('store.cart');
    $router->post('/cart/add', [\App\Controllers\Store\CartController::class, 'add'])->name('store.cart.add');
    $router->post('/cart/update', [\App\Controllers\Store\CartController::class, 'update'])->name('store.cart.update');
    $router->post('/cart/remove', [\App\Controllers\Store\CartController::class, 'remove'])->name('store.cart.remove');
    $router->get('/calculator', [\App\Controllers\Store\CartController::class, 'calculator'])->name('store.calculator');
    $router->get('/saved', [\App\Controllers\Store\SavedController::class, 'index'])->name('store.saved');
    $router->get('/saved/cards', [\App\Controllers\Store\SavedController::class, 'cards'])->name('store.saved.cards');
    // S5: saving to the account needs sign-in; guests keep the browser list (merged at sign-in).
    $router->post('/saved/toggle', [\App\Controllers\Store\SavedController::class, 'toggle'])->name('store.saved.toggle')->middleware(\App\Middleware\RequireCustomer::class);
    // S4 checkout → payment window → order; track / pay-a-balance lookup. S5: checkout needs an account
    // (sign in or create one on /signin?next=/checkout; the bag is kept). /checkout/done stays open so
    // a submitted payment always lands on its order page even if the session ran out meanwhile.
    $router->get('/checkout', [\App\Controllers\Store\CheckoutController::class, 'show'])->name('store.checkout')->middleware(\App\Middleware\RequireCustomer::class);
    $router->post('/checkout/start', [\App\Controllers\Store\CheckoutController::class, 'start'])->name('store.checkout.start')->middleware(\App\Middleware\RequireCustomer::class);
    $router->post('/checkout/done', [\App\Controllers\Store\CheckoutController::class, 'done'])->name('store.checkout.done');
    $router->get('/checkout/pincode/{code}', [\App\Controllers\Store\CheckoutController::class, 'pincode'])->name('store.checkout.pincode')->middleware('throttle:30,1');
    $router->get('/track', [\App\Controllers\Store\TrackController::class, 'show'])->name('store.track');
    $router->post('/track', [\App\Controllers\Store\TrackController::class, 'find'])->name('store.track.find');
    // S5 customer accounts (Store\CustomerAuth; staff never sign in here, they use /admin).
    $acct = \App\Controllers\Store\AccountController::class;
    $need = \App\Middleware\RequireCustomer::class;
    $router->get('/signin', [$acct, 'signin'])->name('store.signin');
    $router->post('/signin', [$acct, 'login'])->name('store.signin.post')->middleware('throttle:30,1');
    $router->post('/register', [$acct, 'register'])->name('store.register')->middleware('throttle:10,1');
    $router->post('/signin/forgot', [$acct, 'forgot'])->name('store.signin.forgot')->middleware('throttle:10,1');
    $router->post('/signin/reset', [$acct, 'reset'])->name('store.signin.reset')->middleware('throttle:20,1');
    $router->post('/signout', [$acct, 'logout'])->name('store.signout');
    $router->get('/account', [$acct, 'show'])->name('store.account')->middleware($need);
    $router->get('/account/{tab}', [$acct, 'show'])->name('store.account.tab')->middleware($need);
    $router->post('/account/profile', [$acct, 'profile'])->name('store.account.profile')->middleware($need);
    $router->post('/account/password', [$acct, 'password'])->name('store.account.password')->middleware([$need, 'throttle:10,1']);
    $router->post('/account/addresses', [$acct, 'saveAddress'])->name('store.account.address.add')->middleware($need);
    $router->post('/account/addresses/{id}', [$acct, 'saveAddress'])->name('store.account.address.update')->middleware($need);
    $router->post('/account/addresses/{id}/default', [$acct, 'defaultAddress'])->name('store.account.address.default')->middleware($need);
    $router->post('/account/addresses/{id}/delete', [$acct, 'deleteAddress'])->name('store.account.address.delete')->middleware($need);
});

$router->get('/hello/{name?}', [WelcomeController::class, 'hello'])->name('hello');

// Public "write a review" form (Stage 2). Rate limiting + spam guards live in ReviewSubmission.
$router->get('/review/{slug}', [ReviewFormController::class, 'show'])->name('review.show');
$router->post('/review/{slug}', [ReviewFormController::class, 'submit'])->name('review.submit');

// Public order pages (no login; unguessable tokens from QR codes / links sent to the customer).
$router->get('/invoice/{token}', [PublicOrderController::class, 'invoice'])->name('invoice.public');
$router->get('/order/{token}', [PublicOrderController::class, 'order'])->name('order.public')->middleware(\App\Middleware\StoreCustomer::class);

// Payment window (Stage 5): /pay/{order token} is the link sent by a payment request.
$router->get('/pay/attempt/{token}', [PayController::class, 'status'])->name('pay.status');
$router->post('/pay/attempt/{token}/submit', [PayController::class, 'submit'])->name('pay.submit');
$router->post('/pay/attempt/{token}/close', [PayController::class, 'close'])->name('pay.close');
$router->get('/pay/{token}', [PayController::class, 'show'])->name('pay.show')->middleware(\App\Middleware\StoreCustomer::class);
$router->post('/pay/{token}/start', [PayController::class, 'start'])->name('pay.start');

// Admin access (docs/admin-access.md): login / forgot password stay public; every other /admin route
// needs a staff login (AdminAuth). Fully-qualified names keep this block self-contained.
$router->group(['prefix' => 'admin'], function ($router): void {
    $auth = \App\Controllers\Admin\AuthController::class;
    $router->get('/login', [$auth, 'showLogin'])->name('admin.login');
    $router->post('/login', [$auth, 'login'])->name('admin.login.submit')->middleware('throttle:30,1');
    $router->get('/forgot', [$auth, 'showForgot'])->name('admin.forgot');
    $router->post('/forgot', [$auth, 'sendForgot'])->name('admin.forgot.send')->middleware('throttle:10,1');
    $router->get('/forgot/reset', [$auth, 'showReset'])->name('admin.forgot.reset');
    $router->post('/forgot/reset', [$auth, 'reset'])->name('admin.forgot.reset.submit')->middleware('throttle:20,1');
});
$router->group(['prefix' => 'admin', 'middleware' => [\App\Middleware\AdminAuth::class]], function ($router): void {
    $auth = \App\Controllers\Admin\AuthController::class;
    $account = \App\Controllers\Admin\AccountController::class;
    $staff = \App\Controllers\Admin\StaffController::class;
    $router->post('/logout', [$auth, 'logout'])->name('admin.logout');
    $router->get('/password/set', [$auth, 'showSetPassword'])->name('admin.password.set');
    $router->post('/password/set', [$auth, 'setPassword'])->name('admin.password.set.submit');
    $router->get('/account', [$account, 'show'])->name('admin.account');
    $router->post('/account/password/code', [$account, 'sendCode'])->name('admin.account.code')->middleware('throttle:10,1');
    $router->post('/account/password', [$account, 'changePassword'])->name('admin.account.password')->middleware('throttle:20,1');
    $router->post('/account/logout-others', [$account, 'logoutOthers'])->name('admin.account.logoutOthers');
    $router->get('/staff', [$staff, 'index'])->name('admin.staff');
    $router->post('/staff', [$staff, 'store'])->name('admin.staff.store');
    $router->post('/staff/{id}/update', [$staff, 'update'])->name('admin.staff.update');
    $router->post('/staff/{id}/resend', [$staff, 'resend'])->name('admin.staff.resend');
    $router->post('/staff/{id}/reset', [$staff, 'reset'])->name('admin.staff.reset');
    $router->post('/staff/{id}/disable', [$staff, 'disable'])->name('admin.staff.disable');
    $router->post('/staff/{id}/enable', [$staff, 'enable'])->name('admin.staff.enable');
});

// Admin — product catalog.
$router->group(['prefix' => 'admin', 'middleware' => [\App\Middleware\AdminAuth::class]], function ($router): void {
    $router->get('', [DashboardController::class, 'index'])->name('admin.dashboard');
    $router->get('/', [DashboardController::class, 'index'])->name('admin.dashboard.slash');

    $router->get('/brands', [BrandController::class, 'index'])->name('admin.brands');
    $router->post('/brands', [BrandController::class, 'store'])->name('admin.brands.store');
    $router->post('/brands/{id}/update', [BrandController::class, 'update'])->name('admin.brands.update');
    $router->post('/brands/{id}/delete', [BrandController::class, 'destroy'])->name('admin.brands.delete');
    $router->get('/categories', [CatalogController::class, 'categories'])->name('admin.categories');
    $router->post('/categories', [CatalogController::class, 'storeCategory'])->name('admin.categories.store');
    $router->post('/categories/{id}/update', [CatalogController::class, 'updateCategory'])->name('admin.categories.update');
    $router->post('/categories/{id}/delete', [CatalogController::class, 'deleteCategory'])->name('admin.categories.delete');
    $router->get('/units', [CatalogController::class, 'units'])->name('admin.units');
    $router->post('/units', [CatalogController::class, 'storeUnit'])->name('admin.units.store');
    $router->post('/units/{id}/delete', [CatalogController::class, 'deleteUnit'])->name('admin.units.delete');

    $router->get('/variants', [VariantController::class, 'index'])->name('admin.variants');
    $router->post('/variants/{id}/update', [VariantController::class, 'update'])->name('admin.variants.update');
    $router->post('/variants/{id}/delete', [VariantController::class, 'destroy'])->name('admin.variants.delete');
    $router->post('/options', [VariantController::class, 'storeGroup'])->name('admin.options.store');
    $router->post('/options/{id}/delete', [VariantController::class, 'deleteGroup'])->name('admin.options.delete');
    $router->post('/option-values/{id}/delete', [VariantController::class, 'deleteValue'])->name('admin.options.values.delete');
    $router->get('/tags', [TagController::class, 'index'])->name('admin.tags');
    $router->post('/tags', [TagController::class, 'store'])->name('admin.tags.store');
    $router->post('/tags/{id}/update', [TagController::class, 'update'])->name('admin.tags.update');
    $router->post('/tags/{id}/delete', [TagController::class, 'destroy'])->name('admin.tags.delete');

    $router->get('/attributes', [AttributeController::class, 'index'])->name('admin.attributes');
    $router->post('/attributes', [AttributeController::class, 'store'])->name('admin.attributes.store');
    $router->post('/attributes/{id}/update', [AttributeController::class, 'update'])->name('admin.attributes.update');
    $router->post('/attributes/{id}/delete', [AttributeController::class, 'destroy'])->name('admin.attributes.delete');

    $router->get('/reviews', [ReviewController::class, 'index'])->name('admin.reviews');
    $router->post('/reviews', [ReviewController::class, 'store'])->name('admin.reviews.store');
    $router->post('/reviews/bulk', [ReviewController::class, 'bulk'])->name('admin.reviews.bulk');
    $router->post('/reviews/{id}/update', [ReviewController::class, 'update'])->name('admin.reviews.update');
    $router->post('/reviews/{id}/delete', [ReviewController::class, 'destroy'])->name('admin.reviews.delete');

    // Storefront enquiries inbox (docs/storefront.md).
    $router->get('/enquiries', [\App\Controllers\Admin\EnquiryController::class, 'index'])->name('admin.enquiries');
    $router->post('/enquiries/{id}/update', [\App\Controllers\Admin\EnquiryController::class, 'update'])->name('admin.enquiries.update');

    // Bulk upload (Excel / CSV) — registered before the /products/{id} routes.
    $router->get('/products/import', [ImportController::class, 'show'])->name('admin.products.import');
    $router->post('/products/import', [ImportController::class, 'preview'])->name('admin.products.import.preview');
    $router->get('/products/import/template/{format}', [ImportController::class, 'template'])->name('admin.products.import.template');
    $router->post('/products/import/{token}/commit', [ImportController::class, 'commit'])->name('admin.products.import.commit');
    $router->get('/products/import/{token}/report', [ImportController::class, 'report'])->name('admin.products.import.report');

    // Media library: one record per file, shared by products, variants, brands… (MediaService).
    $router->get('/media', [MediaController::class, 'index'])->name('admin.media');
    $router->post('/media/upload', [MediaController::class, 'upload'])->name('admin.media.upload');
    $router->post('/media/bulk-delete', [MediaController::class, 'bulkDelete'])->name('admin.media.bulkDelete');
    $router->get('/media/{id}', [MediaController::class, 'show'])->name('admin.media.show');
    $router->get('/media/{id}/file', [MediaController::class, 'file'])->name('admin.media.file');
    $router->post('/media/{id}/update', [MediaController::class, 'update'])->name('admin.media.update');
    $router->post('/media/{id}/delete', [MediaController::class, 'destroy'])->name('admin.media.delete');

    // Notifications — topbar bell panel + full page.
    $router->get('/notifications', [NotificationController::class, 'index'])->name('admin.notifications');
    $router->get('/notifications/feed', [NotificationController::class, 'feed'])->name('admin.notifications.feed');
    $router->post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('admin.notifications.readAll');
    $router->post('/notifications/bulk', [NotificationController::class, 'bulk'])->name('admin.notifications.bulk');
    $router->get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('admin.notifications.open');
    $router->post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('admin.notifications.read');
    $router->post('/notifications/{id}/delete', [NotificationController::class, 'destroy'])->name('admin.notifications.delete');

    // Inventory — stock register (tracked per product).
    $router->get('/inventory', [InventoryController::class, 'overview'])->name('admin.inventory');
    $router->get('/inventory/movements', [InventoryController::class, 'movements'])->name('admin.inventory.movements');
    $router->get('/inventory/alerts', [InventoryController::class, 'alerts'])->name('admin.inventory.alerts');
    $router->post('/inventory/alerts/check', [InventoryController::class, 'check'])->name('admin.inventory.check');
    $router->post('/inventory/settings', [InventoryController::class, 'saveSettings'])->name('admin.inventory.settings');
    $router->get('/inventory/stock', [InventoryController::class, 'stock'])->name('admin.inventory.stock');
    $router->post('/inventory/track', [InventoryController::class, 'track'])->name('admin.inventory.track');
    $router->post('/inventory/opening', [InventoryController::class, 'opening'])->name('admin.inventory.opening');
    $router->get('/inventory/stock/{id}/history', [InventoryController::class, 'history'])->name('admin.inventory.history');
    $router->post('/inventory/stock/{id}/receive', [InventoryController::class, 'receive'])->name('admin.inventory.receive');
    $router->post('/inventory/stock/{id}/adjust', [InventoryController::class, 'adjust'])->name('admin.inventory.adjust');
    $router->post('/inventory/stock/{id}/threshold', [InventoryController::class, 'threshold'])->name('admin.inventory.threshold');
    $router->post('/inventory/stock/{id}/stop', [InventoryController::class, 'stop'])->name('admin.inventory.stop');

    // Customers (Stage 3) — segments/tags/create are registered before /customers/{id}.
    $router->get('/customers', [CustomerController::class, 'index'])->name('admin.customers');
    $router->get('/customers/create', [CustomerController::class, 'create'])->name('admin.customers.create');
    $router->post('/customers', [CustomerController::class, 'store'])->name('admin.customers.store');
    $router->get('/customers/segments', [CustomerSegmentController::class, 'index'])->name('admin.customers.segments');
    $router->get('/customers/segments/create', [CustomerSegmentController::class, 'create'])->name('admin.customers.segments.create');
    $router->post('/customers/segments', [CustomerSegmentController::class, 'store'])->name('admin.customers.segments.store');
    $router->post('/customers/segments/preview', [CustomerSegmentController::class, 'preview'])->name('admin.customers.segments.preview');
    $router->get('/customers/segments/{id}', [CustomerSegmentController::class, 'show'])->name('admin.customers.segments.show');
    $router->get('/customers/segments/{id}/edit', [CustomerSegmentController::class, 'edit'])->name('admin.customers.segments.edit');
    $router->post('/customers/segments/{id}/update', [CustomerSegmentController::class, 'update'])->name('admin.customers.segments.update');
    $router->post('/customers/segments/{id}/refresh', [CustomerSegmentController::class, 'refresh'])->name('admin.customers.segments.refresh');
    $router->post('/customers/segments/{id}/members', [CustomerSegmentController::class, 'addMembers'])->name('admin.customers.segments.members.add');
    $router->post('/customers/segments/{id}/members/{customerId}/delete', [CustomerSegmentController::class, 'removeMember'])->name('admin.customers.segments.members.delete');
    $router->post('/customers/segments/{id}/delete', [CustomerSegmentController::class, 'destroy'])->name('admin.customers.segments.delete');
    $router->get('/customers/tags', [CustomerTagController::class, 'index'])->name('admin.customers.tags');
    $router->post('/customers/tags', [CustomerTagController::class, 'store'])->name('admin.customers.tags.store');
    $router->post('/customers/tags/{id}/update', [CustomerTagController::class, 'update'])->name('admin.customers.tags.update');
    $router->post('/customers/tags/{id}/delete', [CustomerTagController::class, 'destroy'])->name('admin.customers.tags.delete');
    $router->get('/customers/{id}', [CustomerController::class, 'show'])->name('admin.customers.show');
    $router->get('/customers/{id}/edit', [CustomerController::class, 'edit'])->name('admin.customers.edit');
    $router->post('/customers/{id}/update', [CustomerController::class, 'update'])->name('admin.customers.update');
    $router->post('/customers/{id}/delete', [CustomerController::class, 'destroy'])->name('admin.customers.delete');

    // Orders (Stage 4) — fixed paths before /orders/{id}.
    $router->get('/orders', [OrderController::class, 'index'])->name('admin.orders');
    $router->get('/orders/create', [OrderController::class, 'create'])->name('admin.orders.create');
    $router->post('/orders', [OrderController::class, 'store'])->name('admin.orders.store');
    $router->post('/orders/bulk', [OrderController::class, 'bulk'])->name('admin.orders.bulk');
    $router->get('/orders/lookup/customers', [OrderController::class, 'lookupCustomers'])->name('admin.orders.lookup.customers');
    $router->get('/orders/lookup/customers/{id}/addresses', [OrderController::class, 'lookupAddresses'])->name('admin.orders.lookup.addresses');
    $router->get('/orders/lookup/products', [OrderController::class, 'lookupProducts'])->name('admin.orders.lookup.products');
    $router->get('/orders/{id}', [OrderController::class, 'show'])->name('admin.orders.show');
    $router->post('/orders/{id}/status', [OrderController::class, 'transition'])->name('admin.orders.transition');
    $router->post('/orders/{id}/otp', [OrderController::class, 'resendOtp'])->name('admin.orders.otp');
    $router->get('/orders/{id}/invoice', [OrderDocumentController::class, 'invoice'])->name('admin.orders.invoice');
    $router->get('/orders/{id}/packing-slip', [OrderDocumentController::class, 'packingSlip'])->name('admin.orders.slip');

    // Payments (Stage 5) — fixed paths before /payments/{id}.
    $router->get('/payments', [PaymentController::class, 'index'])->name('admin.payments');
    $router->get('/payments/accounts', [PaymentAccountController::class, 'index'])->name('admin.payments.accounts');
    $router->post('/payments/accounts/unlock', [PaymentAccountController::class, 'unlock'])->name('admin.payments.accounts.unlock');
    $router->post('/payments/accounts', [PaymentAccountController::class, 'store'])->name('admin.payments.accounts.store');
    $router->post('/payments/accounts/{id}/update', [PaymentAccountController::class, 'update'])->name('admin.payments.accounts.update');
    $router->post('/payments/accounts/{id}/default', [PaymentAccountController::class, 'makeDefault'])->name('admin.payments.accounts.default');
    $router->post('/payments/accounts/{id}/delete', [PaymentAccountController::class, 'destroy'])->name('admin.payments.accounts.delete');
    $router->get('/payments/attempts/{id}', [PaymentController::class, 'attempt'])->name('admin.payments.attempts.show');
    $router->post('/payments/attempts/{id}/verify', [PaymentController::class, 'verify'])->name('admin.payments.attempts.verify');
    $router->post('/payments/attempts/{id}/reject', [PaymentController::class, 'reject'])->name('admin.payments.attempts.reject');
    $router->post('/payments/refunds/{id}/void', [PaymentController::class, 'voidRefund'])->name('admin.payments.refunds.void');
    $router->get('/payments/{id}/receipt', [PaymentController::class, 'receipt'])->name('admin.payments.receipt');
    $router->post('/payments/{id}/void', [PaymentController::class, 'void'])->name('admin.payments.void');
    $router->post('/orders/{id}/payments', [PaymentController::class, 'record'])->name('admin.orders.payments.store');
    $router->post('/orders/{id}/refunds', [PaymentController::class, 'refund'])->name('admin.orders.refunds.store');
    $router->post('/orders/{id}/payment-request', [PaymentController::class, 'request'])->name('admin.orders.payment-request');

    // Stage 6: returns — returns & replacements (ReturnService) + product return policies.
    $router->get('/returns', [\App\Controllers\Admin\ReturnController::class, 'index'])->name('admin.returns');
    $router->get('/returns/{id}', [\App\Controllers\Admin\ReturnController::class, 'show'])->name('admin.returns.show');
    $router->post('/returns/{id}/approve', [\App\Controllers\Admin\ReturnController::class, 'approve'])->name('admin.returns.approve');
    $router->post('/returns/{id}/reject', [\App\Controllers\Admin\ReturnController::class, 'reject'])->name('admin.returns.reject');
    $router->post('/returns/{id}/receive', [\App\Controllers\Admin\ReturnController::class, 'receive'])->name('admin.returns.receive');
    $router->post('/returns/{id}/resolve', [\App\Controllers\Admin\ReturnController::class, 'resolve'])->name('admin.returns.resolve');
    $router->post('/returns/{id}/refund', [\App\Controllers\Admin\ReturnController::class, 'refund'])->name('admin.returns.refund');
    $router->post('/orders/{id}/returns', [\App\Controllers\Admin\ReturnController::class, 'store'])->name('admin.orders.returns.store');
    $router->get('/return-policies', [\App\Controllers\Admin\ReturnPolicyController::class, 'index'])->name('admin.return-policies');
    $router->post('/return-policies', [\App\Controllers\Admin\ReturnPolicyController::class, 'save'])->name('admin.return-policies.save');

    // Internal JSON lookups (pincode → district/state/post offices, cached).
    $router->get('/lookup/pincode/{code}', [LookupController::class, 'pincode'])->name('admin.lookup.pincode');

    $router->get('/products', [ProductController::class, 'index'])->name('admin.products.index');
    $router->get('/products/create', [ProductController::class, 'create'])->name('admin.products.create');
    $router->post('/products', [ProductController::class, 'store'])->name('admin.products.store');
    $router->get('/products/{id}', [ProductController::class, 'show'])->name('admin.products.show');
    $router->get('/products/{id}/edit', [ProductController::class, 'edit'])->name('admin.products.edit');
    $router->post('/products/{id}/update', [ProductController::class, 'update'])->name('admin.products.update');
    $router->post('/products/{id}/media/attach', [ProductController::class, 'attachMedia'])->name('admin.products.media.attach');
    $router->post('/products/{id}/media/reorder', [ProductController::class, 'reorderMedia'])->name('admin.products.media.reorder');
    $router->post('/products/{id}/media/{mediaId}/cover', [ProductController::class, 'coverMedia'])->name('admin.products.media.cover');
    $router->post('/products/{id}/media/{mediaId}/variant', [ProductController::class, 'variantMedia'])->name('admin.products.media.variant');
    $router->post('/products/{id}/media/{mediaId}/delete', [ProductController::class, 'deleteMedia'])->name('admin.products.media.delete');
    $router->post('/products/{id}/delete', [ProductController::class, 'destroy'])->name('admin.products.destroy');
});

// WhatsApp (Stage 7, docs/whatsapp.md) — public Meta webhook (CSRF-exempt via the "webhooks" prefix,
// verify token / HMAC signature, 404 while dormant) + the read-only admin status page.
$router->get('/webhooks/whatsapp', [\App\Controllers\WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
$router->post('/webhooks/whatsapp', [\App\Controllers\WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.receive');
$router->group(['prefix' => 'admin', 'middleware' => [\App\Middleware\AdminAuth::class]], function ($router): void {
    $router->get('/settings/whatsapp', [\App\Controllers\Admin\WhatsAppController::class, 'index'])->name('admin.settings.whatsapp');
    $router->post('/settings/whatsapp/check', [\App\Controllers\Admin\WhatsAppController::class, 'check'])->name('admin.settings.whatsapp.check');
    $router->post('/settings/whatsapp/test', [\App\Controllers\Admin\WhatsAppController::class, 'test'])->name('admin.settings.whatsapp.test');
});
