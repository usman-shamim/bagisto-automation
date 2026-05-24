<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Webkul\Automation\Jobs\DispatchWebhook;
use Webkul\Automation\Listeners\DispatchWebhooksForProductUpdate;
use Webkul\Automation\Models\AutomationWebhook;
use Webkul\Automation\Models\AutomationWebhookDelivery;
use Webkul\Automation\Models\AutomationWebhookDeliveryProxy;
use Webkul\Automation\Models\AutomationWebhookProxy;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

function makeWebhookForDispatch(array $overrides = []): AutomationWebhook
{
    return AutomationWebhookProxy::modelClass()::create(array_merge([
        'admin_id' => AdminProxy::modelClass()::first()->id,
        'name' => 'dispatch-test',
        'target_url' => 'https://hook.example.test/in',
        'event' => 'product.updated',
        'secret' => 'super-secret-signing-key',
        'is_active' => true,
    ], $overrides));
}

function aProduct(): Product
{
    return ProductProxy::modelClass()::query()->whereNull('parent_id')->firstOrFail();
}

it('dispatches one job per active matching webhook when the listener fires', function () {
    Bus::fake([DispatchWebhook::class]);

    $w1 = makeWebhookForDispatch(['name' => 'one']);
    $w2 = makeWebhookForDispatch(['name' => 'two', 'target_url' => 'https://hook.example.test/two']);
    makeWebhookForDispatch(['name' => 'off', 'is_active' => false]);
    makeWebhookForDispatch(['name' => 'other-event', 'event' => 'order.placed']);

    (new DispatchWebhooksForProductUpdate)->handle(aProduct());

    Bus::assertDispatchedTimes(DispatchWebhook::class, 2);
    Bus::assertDispatched(DispatchWebhook::class, fn ($j) => $j->webhookId === $w1->id);
    Bus::assertDispatched(DispatchWebhook::class, fn ($j) => $j->webhookId === $w2->id);
});

it('builds product.updated payloads stamped with the schema version and event name', function () {
    Bus::fake([DispatchWebhook::class]);

    makeWebhookForDispatch();

    (new DispatchWebhooksForProductUpdate)->handle(aProduct());

    Bus::assertDispatched(DispatchWebhook::class, function ($job) {
        return ($job->payload['version'] ?? null) === 'v1'
            && ($job->payload['event'] ?? null) === 'product.updated'
            && array_key_exists('product_id', $job->payload)
            && array_key_exists('stock_total', $job->payload)
            && array_key_exists('occurred_at', $job->payload);
    });
});

it('records a successful delivery row with HMAC signature on 2xx response', function () {
    Http::fake([
        'hook.example.test/*' => Http::response('OK', 200),
    ]);

    $webhook = makeWebhookForDispatch();
    $product = aProduct();

    $payload = [
        'version' => 'v1',
        'event' => 'product.updated',
        'product_id' => (int) $product->id,
        'sku' => $product->sku,
        'price' => 49.99,
        'stock_total' => 5,
        'occurred_at' => now()->toIso8601String(),
    ];

    (new DispatchWebhook($webhook->id, 'product.updated', $payload))->handle();

    $delivery = AutomationWebhookDeliveryProxy::modelClass()::query()
        ->where('webhook_id', $webhook->id)->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->succeeded_at)->not->toBeNull()
        ->and($delivery->next_retry_at)->toBeNull();

    Http::assertSent(function ($request) use ($payload) {
        if (! $request->hasHeader('X-Bagisto-Event', 'product.updated')) {
            return false;
        }
        if (! $request->hasHeader('X-Bagisto-Timestamp') || ! $request->hasHeader('X-Bagisto-Signature')) {
            return false;
        }

        $timestamp = $request->header('X-Bagisto-Timestamp')[0];
        $sigHeader = $request->header('X-Bagisto-Signature')[0];

        // Recompute the signature against "{timestamp}.{body}" to confirm the
        // dispatcher binds the timestamp into the HMAC (replay protection).
        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.json_encode($payload, JSON_UNESCAPED_SLASHES),
            'super-secret-signing-key',
        );

        return hash_equals($expected, $sigHeader);
    });
});

it('schedules a retry with backoff on a 5xx response and records the failure', function () {
    Bus::fake([DispatchWebhook::class]);

    Http::fake([
        'hook.example.test/*' => Http::response('boom', 503),
    ]);

    $webhook = makeWebhookForDispatch();

    (new DispatchWebhook($webhook->id, 'product.updated', ['event' => 'product.updated', 'product_id' => 1]))->handle();

    $delivery = AutomationWebhookDeliveryProxy::modelClass()::query()->first();

    expect($delivery->response_status)->toBe(503)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->succeeded_at)->toBeNull()
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->isExhausted())->toBeFalse();

    Bus::assertDispatched(DispatchWebhook::class, function ($j) use ($webhook, $delivery) {
        return $j->webhookId === $webhook->id && $j->deliveryId === $delivery->id;
    });
});

it('does not re-dispatch after the final attempt is exhausted', function () {
    Bus::fake([DispatchWebhook::class]);

    Http::fake([
        'hook.example.test/*' => Http::response('boom', 503),
    ]);

    $webhook = makeWebhookForDispatch();

    // Seed a delivery already at attempt MAX-1 so the next handle() makes it MAX.
    $existing = AutomationWebhookDeliveryProxy::modelClass()::create([
        'webhook_id' => $webhook->id,
        'event' => 'product.updated',
        'payload' => ['event' => 'product.updated'],
        'attempt' => AutomationWebhookDelivery::MAX_ATTEMPTS - 1,
    ]);

    (new DispatchWebhook(
        $webhook->id,
        'product.updated',
        ['event' => 'product.updated'],
        $existing->id,
    ))->handle();

    $existing->refresh();

    expect($existing->attempt)->toBe(AutomationWebhookDelivery::MAX_ATTEMPTS)
        ->and($existing->succeeded_at)->toBeNull()
        ->and($existing->next_retry_at)->toBeNull()
        ->and($existing->isExhausted())->toBeTrue();

    Bus::assertNotDispatched(DispatchWebhook::class);
});

it('skips delivery silently when the webhook has been deactivated since enqueue', function () {
    Http::fake();
    Bus::fake([DispatchWebhook::class]);

    $webhook = makeWebhookForDispatch(['is_active' => false]);

    (new DispatchWebhook($webhook->id, 'product.updated', ['event' => 'product.updated']))->handle();

    expect(AutomationWebhookDeliveryProxy::modelClass()::query()->count())->toBe(0);

    Http::assertNothingSent();
    Bus::assertNotDispatched(DispatchWebhook::class);
});

it('records a failure when the HTTP call throws (e.g. connection refused)', function () {
    Bus::fake([DispatchWebhook::class]);

    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    $webhook = makeWebhookForDispatch();

    (new DispatchWebhook($webhook->id, 'product.updated', ['event' => 'product.updated']))->handle();

    $delivery = AutomationWebhookDeliveryProxy::modelClass()::query()->first();

    expect($delivery->response_status)->toBeNull()
        ->and($delivery->response_body)->toContain('connection refused')
        ->and($delivery->next_retry_at)->not->toBeNull();

    Bus::assertDispatched(DispatchWebhook::class);
});
