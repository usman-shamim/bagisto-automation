<?php

namespace Webkul\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Automation\Contracts\AutomationWebhookDelivery as AutomationWebhookDeliveryContract;

class AutomationWebhookDelivery extends Model implements AutomationWebhookDeliveryContract
{
    public const MAX_ATTEMPTS = 5;

    protected $table = 'automation_webhook_deliveries';

    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'signature',
        'attempt',
        'response_status',
        'response_body',
        'succeeded_at',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt' => 'integer',
        'response_status' => 'integer',
        'succeeded_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function webhook()
    {
        return $this->belongsTo(AutomationWebhookProxy::modelClass(), 'webhook_id');
    }

    public function hasSucceeded(): bool
    {
        return $this->succeeded_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempt >= self::MAX_ATTEMPTS && ! $this->hasSucceeded();
    }
}
