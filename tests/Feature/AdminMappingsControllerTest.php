<?php

use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

function adminProductId(): int
{
    $p = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();
    if (! $p) {
        test()->markTestSkipped('No seeded products available.');
    }

    return $p->id;
}

function loginAsAdmin(): void
{
    $admin = AdminProxy::modelClass()::first();
    test()->actingAs($admin, 'admin');
}

it('redirects unauthenticated admins to login when posting a mapping', function () {
    $productId = adminProductId();

    $this->post("/admin/automation/products/{$productId}/mappings", [
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/no-auth',
    ])->assertRedirect();

    expect(CompetitorProductMappingProxy::modelClass()::query()
        ->where('competitor_url', 'https://daraz.pk/no-auth')->exists())->toBeFalse();
});

it('creates a mapping via the admin POST endpoint', function () {
    loginAsAdmin();
    $productId = adminProductId();

    $this->post("/admin/automation/products/{$productId}/mappings", [
        'competitor_name' => 'priceoye',
        'competitor_url' => 'https://priceoye.pk/admin-created',
    ])->assertRedirect();

    expect(CompetitorProductMappingProxy::modelClass()::query()
        ->where('product_id', $productId)
        ->where('competitor_url', 'https://priceoye.pk/admin-created')
        ->exists())->toBeTrue();
});

it('rejects a duplicate URL with a flash error', function () {
    loginAsAdmin();
    $productId = adminProductId();

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $productId,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/dup-admin',
    ]);

    $response = $this->post("/admin/automation/products/{$productId}/mappings", [
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/dup-admin',
    ]);

    $response->assertRedirect()->assertSessionHasErrors('competitor_url');

    expect(CompetitorProductMappingProxy::modelClass()::query()
        ->where('product_id', $productId)
        ->where('competitor_url', 'https://daraz.pk/dup-admin')
        ->count())->toBe(1);
});

it('deletes a mapping via the admin DELETE endpoint', function () {
    loginAsAdmin();
    $productId = adminProductId();

    $mapping = CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $productId,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/admin-delete',
    ]);

    $this->delete("/admin/automation/products/{$productId}/mappings/{$mapping->id}")
        ->assertRedirect();

    expect(CompetitorProductMappingProxy::modelClass()::find($mapping->id))->toBeNull();
});
