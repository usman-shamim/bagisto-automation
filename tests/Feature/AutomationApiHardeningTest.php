<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\User\Models\AdminProxy;

function bearerForHardening(array $scopes = ['read']): string
{
    $admin = AdminProxy::modelClass()::first();

    return app(TokenIssuer::class)->issue($admin, 'hardening-test', $scopes)['plaintext'];
}

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('automation-token:1');
    RateLimiter::clear('automation-token:2');
    config(['automation.api.rate_limit_per_minute' => 60]);
});

it('echoes an X-Request-ID provided by the client on successful responses', function () {
    $bearer = bearerForHardening();

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->withHeader('X-Request-ID', 'req-abc-123')
        ->getJson('/api/automation/v1/products');

    $response->assertStatus(200);

    expect($response->headers->get('X-Request-ID'))->toBe('req-abc-123');
});

it('generates an X-Request-ID when the client does not send one', function () {
    $bearer = bearerForHardening();

    $response = $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products')
        ->assertStatus(200);

    $id = $response->headers->get('X-Request-ID');

    expect($id)->not->toBeNull()
        ->and(strlen($id))->toBeGreaterThan(8);
});

it('echoes X-Request-ID on error responses too (e.g. 401 from missing bearer)', function () {
    $response = $this->withHeader('X-Request-ID', 'err-trace-9')
        ->getJson('/api/automation/v1/products')
        ->assertStatus(401);

    expect($response->headers->get('X-Request-ID'))->toBe('err-trace-9');
});

it('returns a 429 with the documented error shape when the rate limit is exceeded', function () {
    config(['automation.api.rate_limit_per_minute' => 2]);

    $bearer = bearerForHardening();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products')->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products')->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->getJson('/api/automation/v1/products')
        ->assertStatus(429)
        ->assertJson([
            'error' => ['code' => 'rate_limited'],
        ]);
});

it('keeps rate-limit buckets separate per token', function () {
    config(['automation.api.rate_limit_per_minute' => 1]);

    $tokenA = bearerForHardening();
    $tokenB = bearerForHardening();

    // First call on each token both succeed — they're in different buckets.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/automation/v1/products')->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/automation/v1/products')->assertStatus(200);

    // Second call on tokenA is rate-limited; tokenB is unaffected.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/automation/v1/products')->assertStatus(429);
});

it('rejects malformed JSON bodies with a 400 and the documented error shape', function () {
    $bearer = bearerForHardening(['write:staged']);

    $response = $this->call(
        'POST',
        '/api/automation/v1/pending-updates',
        [],
        [],
        [],
        [
            'HTTP_AUTHORIZATION' => "Bearer {$bearer}",
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        '{not valid json'
    );

    $response->assertStatus(400)
        ->assertJson(['error' => ['code' => 'invalid_json']]);

    expect($response->headers->get('X-Request-ID'))->not->toBeNull();
});

it('does not enforce malformed-JSON checks on GET requests with no body', function () {
    $bearer = bearerForHardening();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->withHeader('Content-Type', 'application/json')
        ->getJson('/api/automation/v1/products')
        ->assertStatus(200);
});
