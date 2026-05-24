<?php

namespace Webkul\Automation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Webkul\Automation\Models\AutomationWebhookDelivery;
use Webkul\Automation\Models\AutomationWebhookDeliveryProxy;
use Webkul\Automation\Models\AutomationWebhookProxy;

class DispatchWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Backoff schedule in seconds between attempts: 1m, 5m, 30m, 2h, 12h.
     */
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 43200];

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $webhookId,
        public string $event,
        public array $payload,
        public ?int $deliveryId = null,
    ) {}

    public function handle(): void
    {
        $webhook = AutomationWebhookProxy::modelClass()::query()->find($this->webhookId);

        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        $delivery = $this->deliveryId
            ? AutomationWebhookDeliveryProxy::modelClass()::query()->find($this->deliveryId)
            : null;

        $attempt = $delivery ? $delivery->attempt + 1 : 1;
        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);

        // Signed string is "{timestamp}.{body}" so receivers can reject stale captures.
        // Receivers MUST verify |now - timestamp| < SKEW_SECONDS (5 min) before
        // trusting the signature, otherwise an attacker who recorded one delivery
        // could replay it indefinitely.
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $webhook->secret);

        if (! $delivery) {
            $delivery = AutomationWebhookDeliveryProxy::modelClass()::create([
                'webhook_id' => $webhook->id,
                'event' => $this->event,
                'payload' => $this->payload,
                'signature' => $signature,
                'attempt' => $attempt,
            ]);
        } else {
            $delivery->forceFill([
                'signature' => $signature,
                'attempt' => $attempt,
            ])->save();
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Bagisto-Signature' => 'sha256='.$signature,
                'X-Bagisto-Timestamp' => $timestamp,
                'X-Bagisto-Event' => $this->event,
                'X-Bagisto-Delivery' => (string) $delivery->id,
            ])
                ->timeout(10)
                ->withBody($body, 'application/json')
                ->post($webhook->target_url);

            $delivery->forceFill([
                'response_status' => $response->status(),
                'response_body' => substr((string) $response->body(), 0, 2048),
            ]);

            if ($response->successful()) {
                $delivery->forceFill([
                    'succeeded_at' => now(),
                    'next_retry_at' => null,
                ])->save();

                return;
            }
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'response_status' => null,
                'response_body' => substr($e->getMessage(), 0, 2048),
            ]);
        }

        $this->scheduleRetryOrGiveUp($delivery, $attempt);
    }

    protected function scheduleRetryOrGiveUp(AutomationWebhookDelivery $delivery, int $attempt): void
    {
        if ($attempt >= AutomationWebhookDelivery::MAX_ATTEMPTS) {
            $delivery->forceFill(['next_retry_at' => null])->save();

            return;
        }

        $backoff = self::BACKOFF_SECONDS[$attempt] ?? end(self::BACKOFF_SECONDS);
        $delivery->forceFill(['next_retry_at' => now()->addSeconds($backoff)])->save();

        static::dispatch(
            $this->webhookId,
            $this->event,
            $this->payload,
            $delivery->id,
        )->delay(now()->addSeconds($backoff));
    }
}
