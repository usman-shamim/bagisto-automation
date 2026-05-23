<?php

use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

it('injects the competitor-mappings panel into the admin product edit page', function () {
    $admin = AdminProxy::modelClass()::first();
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();

    if (! $product) {
        $this->markTestSkipped('No seeded products available.');
    }

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product->id,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/edit-page-render',
    ]);

    $adminUrl = config('app.admin_url');

    $response = $this->actingAs($admin, 'admin')
        ->get("/{$adminUrl}/catalog/products/edit/{$product->id}");

    $response->assertStatus(200);

    $html = $response->getContent();

    expect($html)->toContain('Competitor Mappings')
        ->and($html)->toContain('https://daraz.pk/edit-page-render')
        ->and($html)->toContain("/automation/products/{$product->id}/mappings");
});
