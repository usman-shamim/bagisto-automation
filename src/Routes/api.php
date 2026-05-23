<?php

use Illuminate\Support\Facades\Route;
use Webkul\Automation\Http\Controllers\Api\MappingsController;
use Webkul\Automation\Http\Controllers\Api\PendingUpdatesController;
use Webkul\Automation\Http\Controllers\Api\ProductsController;

Route::prefix('api/automation/v1')
    ->middleware(['api', 'automation.api-boundary'])
    ->group(function () {
        Route::get('products', [ProductsController::class, 'index'])
            ->middleware('auth.api-token:read')
            ->name('automation.api.products.index');

        Route::get('products/{productId}/mappings', [MappingsController::class, 'index'])
            ->whereNumber('productId')
            ->middleware('auth.api-token:read')
            ->name('automation.api.products.mappings.index');

        Route::post('products/{productId}/mappings', [MappingsController::class, 'store'])
            ->whereNumber('productId')
            ->middleware('auth.api-token:write:staged')
            ->name('automation.api.products.mappings.store');

        Route::delete('products/{productId}/mappings/{mappingId}', [MappingsController::class, 'destroy'])
            ->whereNumber('productId')
            ->whereNumber('mappingId')
            ->middleware('auth.api-token:write:staged')
            ->name('automation.api.products.mappings.destroy');

        Route::post('pending-updates', [PendingUpdatesController::class, 'store'])
            ->middleware('auth.api-token:write:staged')
            ->name('automation.api.pending-updates.store');
    });
