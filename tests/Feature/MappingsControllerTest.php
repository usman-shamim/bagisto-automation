<?php

use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

function mappingsBearer(array $scopes): string
{
    $admin = AdminProxy::modelClass()::first();

    return app(TokenIssuer::class)->issue($admin, 'test', $scopes)['plaintext'];
}

function firstProductId(): int
{
    $p = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();
    if (! $p) {
        test()->markTestSkipped('No seeded products available.');
    }

    return $p->id;
}

it('requires the read scope to list mappings', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson("/api/automation/v1/products/{$product}/mappings")
        ->assertStatus(403)
        ->assertJson(['error' => ['code' => 'insufficient_scope']]);
});

it('lists mappings for a product', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['read']);

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product,
        'competitor_name' => 'priceoye',
        'competitor_url' => 'https://priceoye.pk/abc',
    ]);

    $data = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson("/api/automation/v1/products/{$product}/mappings")
        ->assertStatus(200)
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray([
            'product_id' => $product,
            'competitor_name' => 'priceoye',
            'competitor_url' => 'https://priceoye.pk/abc',
        ])
        ->and($data[0])->toHaveKeys(['id', 'created_at', 'last_scraped_at']);
});

it('returns 404 when listing mappings for an unknown product', function () {
    $bearer = mappingsBearer(['read']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products/9999999/mappings')
        ->assertStatus(404)
        ->assertJson(['error' => ['code' => 'not_found']]);
});

it('requires the write:staged scope to create a mapping', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['read']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson("/api/automation/v1/products/{$product}/mappings", [
            'competitor_name' => 'daraz',
            'competitor_url' => 'https://daraz.pk/scope-test',
        ])
        ->assertStatus(403)
        ->assertJson(['error' => ['code' => 'insufficient_scope']]);
});

it('creates a mapping and returns the resource', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson("/api/automation/v1/products/{$product}/mappings", [
            'competitor_name' => 'daraz',
            'competitor_url' => 'https://daraz.pk/created',
        ])
        ->assertStatus(201);

    $resource = $response->json('data');

    expect($resource)->toMatchArray([
        'product_id' => $product,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/created',
    ]);

    expect(CompetitorProductMappingProxy::modelClass()::find($resource['id']))->not->toBeNull();
});

it('rejects a missing competitor_url with 422', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson("/api/automation/v1/products/{$product}/mappings", [
            'competitor_name' => 'daraz',
        ])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('rejects a non-URL competitor_url with 422', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson("/api/automation/v1/products/{$product}/mappings", [
            'competitor_name' => 'daraz',
            'competitor_url' => 'not a url',
        ])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('returns 409 when the (product, url) pair is already mapped', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/dup-409',
    ]);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson("/api/automation/v1/products/{$product}/mappings", [
            'competitor_name' => 'daraz',
            'competitor_url' => 'https://daraz.pk/dup-409',
        ])
        ->assertStatus(409)
        ->assertJson(['error' => ['code' => 'conflict']]);
});

it('returns 404 when creating a mapping for an unknown product', function () {
    $bearer = mappingsBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/products/9999999/mappings', [
            'competitor_name' => 'daraz',
            'competitor_url' => 'https://daraz.pk/no-product',
        ])
        ->assertStatus(404)
        ->assertJson(['error' => ['code' => 'not_found']]);
});

it('deletes a mapping', function () {
    $product = firstProductId();
    $bearer = mappingsBearer(['write:staged']);

    $mapping = CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/to-delete',
    ]);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->deleteJson("/api/automation/v1/products/{$product}/mappings/{$mapping->id}")
        ->assertStatus(204);

    expect(CompetitorProductMappingProxy::modelClass()::find($mapping->id))->toBeNull();
});

it('returns 404 deleting a mapping that does not belong to the product', function () {
    $bearer = mappingsBearer(['write:staged']);

    $products = ProductProxy::modelClass()::query()->whereNull('parent_id')->limit(2)->get();
    if ($products->count() < 2) {
        $this->markTestSkipped('Need at least 2 seeded products.');
    }

    $mapping = CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $products[0]->id,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/wrong-parent',
    ]);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->deleteJson("/api/automation/v1/products/{$products[1]->id}/mappings/{$mapping->id}")
        ->assertStatus(404)
        ->assertJson(['error' => ['code' => 'not_found']]);
});
