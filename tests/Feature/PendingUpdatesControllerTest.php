<?php

use Webkul\Automation\Models\PendingProductUpdateProxy;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

function pendingUpdatesBearer(array $scopes): string
{
    $admin = AdminProxy::modelClass()::first();

    return app(TokenIssuer::class)->issue($admin, 'test', $scopes)['plaintext'];
}

function aProductId(): int
{
    $p = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();
    if (! $p) {
        test()->markTestSkipped('No seeded products available.');
    }

    return $p->id;
}

it('rejects a missing bearer on POST /pending-updates with 401', function () {
    $this->postJson('/api/automation/v1/pending-updates', [
        'product_id' => 1,
        'source_url' => 'https://daraz.pk/x',
        'proposed_price' => 1234.50,
    ])->assertStatus(401)
        ->assertJson(['error' => ['code' => 'missing_token']]);
});

it('rejects a token without write:staged scope with 403', function () {
    $bearer = pendingUpdatesBearer(['read']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/no-scope',
            'proposed_price' => 1234.50,
        ])->assertStatus(403)
        ->assertJson(['error' => ['code' => 'insufficient_scope']]);
});

it('creates a pending update with snapshots and records the submitting token', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);
    $productId = aProductId();

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => $productId,
            'source_url' => 'https://daraz.pk/listing-step8',
            'proposed_price' => 4999.00,
            'proposed_stock' => 12,
            'flags' => ['price_drop_50pct'],
            'confidence' => 0.91,
        ])->assertStatus(201);

    $data = $response->json('data');

    expect($data)->toMatchArray([
        'product_id' => $productId,
        'source_url' => 'https://daraz.pk/listing-step8',
        'status' => 'pending',
        'flags' => ['price_drop_50pct'],
    ])
        ->and((float) $data['proposed_price'])->toBe(4999.00)
        ->and((int) $data['proposed_stock'])->toBe(12)
        ->and((float) $data['confidence'])->toBe(0.91)
        ->and($data)->toHaveKeys([
            'id', 'current_price_snapshot', 'current_stock_snapshot',
            'submitted_by_token_id', 'created_at',
        ]);

    $row = PendingProductUpdateProxy::modelClass()::find($data['id']);
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->product_id)->toBe($productId)
        ->and($row->submitted_by_token_id)->not->toBeNull();
});

it('accepts an update with only a proposed_price', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/price-only',
            'proposed_price' => 100.00,
        ])->assertStatus(201);
});

it('accepts an update with only a proposed_stock', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/stock-only',
            'proposed_stock' => 7,
        ])->assertStatus(201);
});

it('rejects a payload missing product_id with 422', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'source_url' => 'https://daraz.pk/missing-product',
            'proposed_price' => 99.0,
        ])->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('rejects a payload missing source_url with 422', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'proposed_price' => 99.0,
        ])->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('rejects a payload with neither proposed_price nor proposed_stock with 422', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/neither',
        ])->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('rejects a negative proposed_price with 422', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/negative-price',
            'proposed_price' => -1,
        ])->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('rejects a confidence outside [0,1] with 422', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => aProductId(),
            'source_url' => 'https://daraz.pk/bad-confidence',
            'proposed_price' => 10.0,
            'confidence' => 1.5,
        ])->assertStatus(422)
        ->assertJson(['error' => ['code' => 'invalid_parameter']]);
});

it('returns 404 when posting against an unknown product', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', [
            'product_id' => 9999999,
            'source_url' => 'https://daraz.pk/no-product',
            'proposed_price' => 50.0,
        ])->assertStatus(404)
        ->assertJson(['error' => ['code' => 'not_found']]);
});

it('returns the original row with 200 when the same external_request_id is replayed', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);
    $productId = aProductId();

    $payload = [
        'product_id' => $productId,
        'source_url' => 'https://daraz.pk/idempotent',
        'proposed_price' => 1234.50,
        'external_request_id' => 'agent-run-2026-05-24-abc123',
    ];

    $first = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    $firstId = $first->json('data.id');
    expect($firstId)->not->toBeNull();

    // Replay the exact same request with the same bearer + external_request_id.
    $second = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(200);

    expect($second->json('data.id'))->toBe($firstId)
        ->and($second->json('data.external_request_id'))->toBe('agent-run-2026-05-24-abc123');

    // The DB should contain exactly one row for this (token, external_request_id) pair.
    $count = PendingProductUpdateProxy::modelClass()::query()
        ->where('external_request_id', 'agent-run-2026-05-24-abc123')
        ->count();
    expect($count)->toBe(1);
});

it('treats the same external_request_id from a different token as a separate request', function () {
    $bearerA = pendingUpdatesBearer(['write:staged']);
    $bearerB = pendingUpdatesBearer(['write:staged']);
    $productId = aProductId();

    $payload = [
        'product_id' => $productId,
        'source_url' => 'https://daraz.pk/cross-token',
        'proposed_price' => 50.0,
        'external_request_id' => 'shared-id-001',
    ];

    $first = $this->withHeader('Authorization', "Bearer {$bearerA}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$bearerB}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
});

it('allows multiple submissions without an external_request_id', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);
    $productId = aProductId();

    $payload = [
        'product_id' => $productId,
        'source_url' => 'https://daraz.pk/no-idempotency-key',
        'proposed_price' => 75.0,
    ];

    $first = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    // No idempotency key → two distinct rows.
    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
});

it('treats an empty external_request_id as no idempotency key', function () {
    $bearer = pendingUpdatesBearer(['write:staged']);
    $productId = aProductId();

    $payload = [
        'product_id' => $productId,
        'source_url' => 'https://daraz.pk/empty-key',
        'proposed_price' => 25.0,
        'external_request_id' => '',
    ];

    $first = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    // Empty string must NOT be treated as a real idempotency key — otherwise
    // a second "" submission from the same token would collide on the
    // (token, external_request_id) unique index.
    $second = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson('/api/automation/v1/pending-updates', $payload)
        ->assertStatus(201);

    expect($first->json('data.id'))->not->toBe($second->json('data.id'))
        ->and($first->json('data.external_request_id'))->toBeNull()
        ->and($second->json('data.external_request_id'))->toBeNull();
});
