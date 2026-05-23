<?php

namespace Webkul\Automation\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'catalog.product.update.after' => [
            'Webkul\Automation\Listeners\DispatchWebhooksForProductUpdate@handle',
        ],
    ];
}
