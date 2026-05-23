<?php

namespace Webkul\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Automation\Contracts\AutomationWebhook as AutomationWebhookContract;
use Webkul\User\Models\AdminProxy;

class AutomationWebhook extends Model implements AutomationWebhookContract
{
    /**
     * Events that webhooks may subscribe to. Adding an event here is the
     * single source of truth for the admin UI and the dispatcher.
     */
    public const EVENTS = ['product.updated'];

    protected $table = 'automation_webhooks';

    protected $fillable = [
        'admin_id',
        'name',
        'target_url',
        'event',
        'secret',
        'is_active',
    ];

    protected $casts = [
        // Secret is stored encrypted-at-rest with the app key. Plaintext
        // is needed at signing time, so unlike API tokens we can't hash.
        'secret' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * Don't accidentally leak the secret into JSON/log output.
     */
    protected $hidden = ['secret'];

    public function admin()
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'admin_id');
    }

    public function deliveries()
    {
        return $this->hasMany(AutomationWebhookDeliveryProxy::modelClass(), 'webhook_id');
    }
}
