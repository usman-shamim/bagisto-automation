<?php

use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

function bearerFor(array $scopes = ['read']): string
{
    $admin = AdminProxy::modelClass()::first();

    return app(TokenIssuer::class)->issue($admin, 'test', $scopes)['plaintext'];
}

it('rejects a missing bearer token with 401', function () {
    $this->getJson('/api/automation/v1/products')
        ->assertStatus(401)
        ->assertJson(['error' => ['code' => 'missing_token']]);
});

it('rejects a token without the read scope with 403', function () {
    $bearer = bearerFor(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products')
        ->assertStatus(403)
        ->assertJson(['error' => ['code' => 'insufficient_scope']]);
});

it('returns a paginated product listing with the documented shape', function () {
    $bearer = bearerFor(['read']);

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?per_page=5&page=1');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'sku', 'name', 'price', 'stock_total',
                    'status', 'updated_at', 'competitor_mappings',
                ],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    $meta = $response->json('meta');
    expect($meta['per_page'])->toBe(5)
        ->and($meta['current_page'])->toBe(1);
});

it('respects pagination by returning the right slice', function () {
    $bearer = bearerFor(['read']);

    $total = ProductProxy::modelClass()::query()->whereNull('parent_id')->count();

    if ($total < 2) {
        $this->markTestSkipped('Need at least 2 root products in the seeded DB.');
    }

    $first = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?per_page=1&page=1')
        ->assertStatus(200)->json();

    $second = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?per_page=1&page=2')
        ->assertStatus(200)->json();

    expect($first['meta']['total'])->toBe($total)
        ->and($first['meta']['last_page'])->toBe($total)
        ->and($first['data'][0]['id'])->not->toBe($second['data'][0]['id']);
});

it('caps per_page at 100 even if the client asks for more', function () {
    $bearer = bearerFor(['read']);

    $meta = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?per_page=9999')
        ->assertStatus(200)
        ->json('meta');

    expect($meta['per_page'])->toBe(100);
});

it('rejects an invalid updated_since with 422', function () {
    $bearer = bearerFor(['read']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?updated_since=not-a-date')
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('filters by updated_since', function () {
    $bearer = bearerFor(['read']);

    $future = urlencode(now()->addYear()->toIso8601String());

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson("/api/automation/v1/products?updated_since={$future}")
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(0)
        ->and($response->json('data'))->toBe([]);
});

it('includes competitor_mappings populated from competitor_product_mappings', function () {
    $bearer = bearerFor(['read']);

    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();

    if (! $product) {
        $this->markTestSkipped('No seeded products available.');
    }

    CompetitorProductMappingProxy::modelClass()::create([
        'product_id' => $product->id,
        'competitor_name' => 'daraz',
        'competitor_url' => 'https://daraz.pk/listing-step7',
    ]);

    $rows = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products?per_page=100')
        ->assertStatus(200)
        ->json('data');

    $row = collect($rows)->firstWhere('id', $product->id);

    expect($row)->not->toBeNull()
        ->and($row['competitor_mappings'])->toHaveCount(1)
        ->and($row['competitor_mappings'][0])->toMatchArray([
            'competitor_name' => 'daraz',
            'competitor_url' => 'https://daraz.pk/listing-step7',
        ])
        ->and($row['competitor_mappings'][0])->toHaveKeys(['id', 'last_scraped_at']);
});
