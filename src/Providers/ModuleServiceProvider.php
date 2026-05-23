<?php

namespace Webkul\Automation\Providers;

use Webkul\Automation\Models\AdminToken;
use Webkul\Automation\Models\AutomationAuditLog;
use Webkul\Automation\Models\AutomationWebhook;
use Webkul\Automation\Models\AutomationWebhookDelivery;
use Webkul\Automation\Models\CompetitorProductMapping;
use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    /**
     * Models.
     *
     * @var array
     */
    protected $models = [
        AdminToken::class,
        AutomationAuditLog::class,
        AutomationWebhook::class,
        AutomationWebhookDelivery::class,
        CompetitorProductMapping::class,
        PendingProductUpdate::class,
    ];
}
