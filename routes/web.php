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
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\ReviewController;
use App\Controllers\Admin\TagController;
use App\Controllers\Admin\VariantController;
use App\Controllers\ContactController;
use App\Controllers\ReviewFormController;
use App\Controllers\WelcomeController;

$router->get('/', [WelcomeController::class, 'index'])->name('home');

$router->get('/hello/{name?}', [WelcomeController::class, 'hello'])->name('hello');

// CSRF + FormRequest validation + flash demo.
$router->get('/contact', [ContactController::class, 'show'])->name('contact.show');
$router->post('/contact', [ContactController::class, 'submit'])->name('contact.submit');

// Public "write a review" form (Stage 2). Rate limiting + spam guards live in ReviewSubmission.
$router->get('/review/{slug}', [ReviewFormController::class, 'show'])->name('review.show');
$router->post('/review/{slug}', [ReviewFormController::class, 'submit'])->name('review.submit');

// Admin — product catalog.
$router->group(['prefix' => 'admin'], function ($router): void {
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
