<?php

use Illuminate\Support\Facades\DB;
use Webkul\Automation\Models\AutomationWebhook;
use Webkul\Automation\Models\AutomationWebhookDelivery;
use Webkul\Automation\Models\AutomationWebhookDeliveryProxy;
use Webkul\Automation\Models\AutomationWebhookProxy;
use Webkul\User\Models\AdminProxy;

function makeWebhook(array $overrides = []): AutomationWebhook
{
    return AutomationWebhookProxy::modelClass()::create(array_merge([
        'admin_id' => AdminProxy::modelClass()::first()->id,
        'name' => 'test-webhook',
        'target_url' => 'https://example.test/hook',
        'event' => 'product.updated',
        'secret' => 'plain-secret-xyz',
    ], $overrides));
}

it('proxies AutomationWebhook through Concord to the configured model class', function () {
    $class = AutomationWebhookProxy::modelClass();

    expect($class)->toBe(AutomationWebhook::class);
});

it('proxies AutomationWebhookDelivery through Concord to the configured model class', function () {
    $class = AutomationWebhookDeliveryProxy::modelClass();

    expect($class)->toBe(AutomationWebhookDelivery::class);
});

it('encrypts the webhook secret at rest', function () {
    $webhook = makeWebhook(['secret' => 'super-secret-token']);

    // Eloquent reads it decrypted
    expect($webhook->fresh()->secret)->toBe('super-secret-token');

    // The raw column value in the DB must NOT match the plaintext
    $raw = DB::table('automation_webhooks')->where('id', $webhook->id)->value('secret');
    expect($raw)->not->toBe('super-secret-token')
        ->and($raw)->not->toBeNull()
        ->and(strlen($raw))->toBeGreaterThan(20);  // encrypted payload is much longer
});

it('hides the secret from array/JSON serialization', function () {
    $webhook = makeWebhook(['secret' => 'hidden-from-json']);

    expect($webhook->toArray())->not->toHaveKey('secret');
});

it('defaults is_active to true and casts boolean', function () {
    $webhook = makeWebhook()->fresh();

    expect($webhook->is_active)->toBeTrue();
});

it('cascades deletes from webhook to deliveries', function () {
    $webhook = makeWebhook();

    AutomationWebhookDeliveryProxy::modelClass()::create([
        'webhook_id' => $webhook->id,
        'event' => 'product.updated',
        'payload' => ['hello' => 'world'],
        'signature' => str_repeat('a', 64),
        'attempt' => 1,
    ]);

    $webhook->delete();

    expect(AutomationWebhookDeliveryProxy::modelClass()::query()
        ->where('webhook_id', $webhook->id)->count())->toBe(0);
});

it('serializes the delivery payload as JSON and casts attempt to int', function () {
    $webhook = makeWebhook();

    $delivery = AutomationWebhookDeliveryProxy::modelClass()::create([
        'webhook_id' => $webhook->id,
        'event' => 'product.updated',
        'payload' => ['product_id' => 17, 'sku' => 'abc-123', 'price' => 4999],
        'attempt' => 0,
    ]);

    $fresh = $delivery->fresh();

    expect($fresh->payload)->toEqualCanonicalizing(['product_id' => 17, 'sku' => 'abc-123', 'price' => 4999])
        ->and($fresh->attempt)->toBe(0);
});

it('flags an exhausted delivery once attempt count hits the cap and no success was recorded', function () {
    $webhook = makeWebhook();

    $delivery = AutomationWebhookDeliveryProxy::modelClass()::create([
        'webhook_id' => $webhook->id,
        'event' => 'product.updated',
        'payload' => [],
        'attempt' => AutomationWebhookDelivery::MAX_ATTEMPTS,
    ]);

    expect($delivery->isExhausted())->toBeTrue()
        ->and($delivery->hasSucceeded())->toBeFalse();

    $delivery->forceFill(['succeeded_at' => now()])->save();

    expect($delivery->fresh()->isExhausted())->toBeFalse()
        ->and($delivery->fresh()->hasSucceeded())->toBeTrue();
});
