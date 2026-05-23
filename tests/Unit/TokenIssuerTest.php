<?php

use Webkul\Automation\Models\AdminTokenProxy;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\User\Models\AdminProxy;

it('issues a token with the bag_ prefix and 64-char length', function () {
    $admin = AdminProxy::modelClass()::first();

    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    expect($result['plaintext'])
        ->toStartWith('bag_')
        ->and(strlen($result['plaintext']))->toBe(64);
});

it('stores the sha256 hash, never the plaintext', function () {
    $admin = AdminProxy::modelClass()::first();

    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $stored = AdminTokenProxy::modelClass()::find($result['token']->id);

    expect($stored->token_hash)
        ->toBe(hash('sha256', $result['plaintext']))
        ->not->toBe($result['plaintext']);
});

it('finds an active token by plaintext', function () {
    $admin = AdminProxy::modelClass()::first();

    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read']);

    $found = app(TokenIssuer::class)->find($result['plaintext']);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($result['token']->id);
});

it('returns null for a wrong plaintext', function () {
    expect(app(TokenIssuer::class)->find('bag_not_a_real_token'))->toBeNull();
});

it('returns null for an empty plaintext', function () {
    expect(app(TokenIssuer::class)->find(''))->toBeNull();
});

it('returns null after a token is revoked', function () {
    $admin = AdminProxy::modelClass()::first();

    $issuer = app(TokenIssuer::class);
    $result = $issuer->issue($admin, 'test', ['read']);

    $issuer->revoke($result['token']);

    expect($issuer->find($result['plaintext']))->toBeNull();
});

it('returns null after a token has expired', function () {
    $admin = AdminProxy::modelClass()::first();

    $issuer = app(TokenIssuer::class);
    $result = $issuer->issue($admin, 'test', ['read'], now()->subSecond());

    expect($issuer->find($result['plaintext']))->toBeNull();
});

it('rejects unknown scopes', function () {
    $admin = AdminProxy::modelClass()::first();

    app(TokenIssuer::class)->issue($admin, 'test', ['read', 'bogus:scope']);
})->throws(InvalidArgumentException::class, 'Unknown scopes: bogus:scope');

it('exposes a stable list of allowed scopes', function () {
    expect(TokenIssuer::SCOPES)->toBe(['read', 'write:staged', 'write:approved']);
});

it('checks scope membership on the token model', function () {
    $admin = AdminProxy::modelClass()::first();

    $result = app(TokenIssuer::class)->issue($admin, 'test', ['read', 'write:staged']);

    expect($result['token']->hasScope('read'))->toBeTrue()
        ->and($result['token']->hasScope('write:staged'))->toBeTrue()
        ->and($result['token']->hasScope('write:approved'))->toBeFalse();
});
