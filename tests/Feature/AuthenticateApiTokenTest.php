<?php

use Illuminate\Support\Facades\Route;
use Webkul\Automation\Models\AutomationAuditLogProxy;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\User\Models\AdminProxy;

beforeEach(function () {
    Route::middleware('auth.api-token:read')->get('/_test/automation/read', fn () => response()->json(['ok' => true]));
    Route::middleware('auth.api-token:write:staged')->post('/_test/automation/staged', fn () => response()->json(['ok' => true]));
    Route::middleware('auth.api-token')->get('/_test/automation/any', fn () => response()->json(['ok' => true]));
});

it('rejects a request with no Authorization header', function () {
    $response = $this->getJson('/_test/automation/read');

    $response->assertStatus(401)
        ->assertJson(['error' => ['code' => 'missing_token']]);
});

it('rejects a request with an unknown bearer token', function () {
    $response = $this->withHeader('Authorization', 'Bearer bag_does_not_exist')
        ->getJson('/_test/automation/read');

    $response->assertStatus(401)
        ->assertJson(['error' => ['code' => 'invalid_token']]);
});

it('rejects a token that lacks the required scope', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $response = $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->postJson('/_test/automation/staged', ['hello' => 'world']);

    $response->assertStatus(403)
        ->assertJson(['error' => ['code' => 'insufficient_scope']]);

    expect(AutomationAuditLogProxy::modelClass()::where('token_id', $result['token']->id)->where('status_code', 403)->exists())
        ->toBeTrue();
});

it('allows a token with the required scope and records an audit row', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $response = $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->getJson('/_test/automation/read');

    $response->assertStatus(200)->assertJson(['ok' => true]);

    $row = AutomationAuditLogProxy::modelClass()::where('token_id', $result['token']->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->status_code)->toBe(200)
        ->and($row->method)->toBe('GET')
        ->and($row->endpoint)->toBe('/_test/automation/read')
        ->and($row->admin_id)->toBe($admin->id);
});

it('leaves payload_hash null when the request body is empty', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->get('/_test/automation/read')
        ->assertStatus(200);

    $row = AutomationAuditLogProxy::modelClass()::where('token_id', $result['token']->id)->first();

    expect($row->payload_hash)->toBeNull();
});

it('updates last_used_at on a successful request', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    expect($result['token']->last_used_at)->toBeNull();

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->getJson('/_test/automation/read')
        ->assertStatus(200);

    expect($result['token']->fresh()->last_used_at)->not->toBeNull();
});

it('stores a sha256 payload hash for non-empty request bodies', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['write:staged']);
    $body = ['proposed_price' => 1234.56];

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->postJson('/_test/automation/staged', $body)
        ->assertStatus(200);

    $row = AutomationAuditLogProxy::modelClass()::where('token_id', $result['token']->id)->first();

    expect($row->payload_hash)
        ->not->toBeNull()
        ->toHaveLength(64)
        ->toBe(hash('sha256', json_encode($body)));
});

it('rejects a revoked token', function () {
    $admin = AdminProxy::modelClass()::first();
    $issuer = app(TokenIssuer::class);
    $result = $issuer->issue($admin, 'test', ['read']);

    $issuer->revoke($result['token']);

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->getJson('/_test/automation/read')
        ->assertStatus(401)
        ->assertJson(['error' => ['code' => 'invalid_token']]);
});

it('rejects an expired token', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read'], now()->subMinute());

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->getJson('/_test/automation/read')
        ->assertStatus(401)
        ->assertJson(['error' => ['code' => 'invalid_token']]);
});

it('allows scope-less middleware to accept any active token', function () {
    $admin = AdminProxy::modelClass()::first();
    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $this->withHeader('Authorization', "Bearer {$result['plaintext']}")
        ->getJson('/_test/automation/any')
        ->assertStatus(200);
});
