<?php

namespace Webkul\Automation\Listeners;

use Webkul\Automation\Jobs\DispatchWebhook;
use Webkul\Automation\Models\AutomationWebhookProxy;

class DispatchWebhooksForProductUpdate
{
    public const EVENT_NAME = 'product.updated';

    /**
     * Payload version. Bump on any breaking change to the keys/types below so
     * receivers can detect schema drift without parsing.
     */
    public const PAYLOAD_VERSION = 'v1';

    public function handle(mixed $product): void
    {
        if (! $product || ! isset($product->id)) {
            return;
        }

        $webhooks = AutomationWebhookProxy::modelClass()::query()
            ->where('event', self::EVENT_NAME)
            ->where('is_active', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $product->loadMissing('inventories');

        $payload = [
            'version' => self::PAYLOAD_VERSION,
            'event' => self::EVENT_NAME,
            'product_id' => (int) $product->id,
            'sku' => $product->sku ?? null,
            'price' => $product->price !== null ? (float) $product->price : null,
            'stock_total' => (int) ($product->inventories?->sum('qty') ?? 0),
            'occurred_at' => now()->toIso8601String(),
        ];

        foreach ($webhooks as $webhook) {
            DispatchWebhook::dispatch($webhook->id, self::EVENT_NAME, $payload);
        }
    }
}
