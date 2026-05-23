<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Webkul\Automation\Contracts\CompetitorProductMapping as CompetitorProductMappingContract;
use Webkul\Automation\Models\CompetitorProductMapping;
use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Automation\Repositories\CompetitorProductMappingRepository;
use Webkul\Product\Models\ProductProxy;

it('resolves the proxy to the concrete model class', function () {
    expect(CompetitorProductMappingProxy::modelClass())->toBe(CompetitorProductMapping::class);
});

it('binds the contract to the concrete model in the repository', function () {
    $repo = app(CompetitorProductMappingRepository::class);

    expect($repo->model())->toBe(CompetitorProductMappingContract::class);
});

it('persists a mapping and casts last_scraped_at to a Carbon instance', function () {
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();

    if (! $product) {
        $this->markTestSkipped('No seeded products available.');
    }

    $mapping = CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product->id,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/test-product-xyz',
        'last_scraped_at' => now(),
    ]);

    $fresh = CompetitorProductMappingProxy::modelClass()::find($mapping->id);

    expect($fresh->competitor_name)->toBe('daraz')
        ->and($fresh->competitor_url)->toBe('https://daraz.pk/test-product-xyz')
        ->and($fresh->last_scraped_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->product->id)->toBe($product->id);
});

it('enforces the unique constraint on (product_id, competitor_url)', function () {
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();

    if (! $product) {
        $this->markTestSkipped('No seeded products available.');
    }

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product->id,
        'competitor_name' => 'priceoye',
        'competitor_url' => 'https://priceoye.pk/dup',
    ]);

    expect(fn () => CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product->id,
        'competitor_name' => 'priceoye',
        'competitor_url' => 'https://priceoye.pk/dup',
    ]))->toThrow(QueryException::class);
});

it('rejects an orphan product_id via the foreign key', function () {
    expect(fn () => CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => 9999999,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/orphan',
    ]))->toThrow(QueryException::class);
});
