<?php

use Illuminate\Support\Facades\Route;
use Webkul\Automation\Http\Controllers\Admin\MappingsController;
use Webkul\Automation\Http\Controllers\Admin\PendingUpdatesController;
use Webkul\Automation\Http\Controllers\Admin\TokensController;
use Webkul\Automation\Http\Controllers\Admin\WebhooksController;

Route::group([
    'prefix' => config('app.admin_url').'/automation',
    'middleware' => ['web', 'admin'],
], function () {
    Route::post('products/{productId}/mappings', [MappingsController::class, 'store'])
        ->whereNumber('productId')
        ->name('admin.automation.products.mappings.store');

    Route::delete('products/{productId}/mappings/{mappingId}', [MappingsController::class, 'destroy'])
        ->whereNumber('productId')
        ->whereNumber('mappingId')
        ->name('admin.automation.products.mappings.destroy');

    Route::get('pending-updates', [PendingUpdatesController::class, 'index'])
        ->name('admin.automation.pending-updates.index');

    Route::post('pending-updates/{id}/approve', [PendingUpdatesController::class, 'approve'])
        ->whereNumber('id')
        ->name('admin.automation.pending-updates.approve');

    Route::post('pending-updates/{id}/reject', [PendingUpdatesController::class, 'reject'])
        ->whereNumber('id')
        ->name('admin.automation.pending-updates.reject');

    Route::get('tokens', [TokensController::class, 'index'])
        ->name('admin.automation.tokens.index');

    Route::post('tokens', [TokensController::class, 'store'])
        ->name('admin.automation.tokens.store');

    Route::post('tokens/{id}/revoke', [TokensController::class, 'revoke'])
        ->whereNumber('id')
        ->name('admin.automation.tokens.revoke');

    Route::get('webhooks', [WebhooksController::class, 'index'])
        ->name('admin.automation.webhooks.index');

    Route::post('webhooks', [WebhooksController::class, 'store'])
        ->name('admin.automation.webhooks.store');

    Route::post('webhooks/{id}/toggle', [WebhooksController::class, 'toggle'])
        ->whereNumber('id')
        ->name('admin.automation.webhooks.toggle');

    Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy'])
        ->whereNumber('id')
        ->name('admin.automation.webhooks.destroy');
});
