<?php

namespace Webkul\Automation\Repositories;

use Webkul\Automation\Contracts\AutomationAuditLog;
use Webkul\Core\Eloquent\Repository;

class AutomationAuditLogRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return AutomationAuditLog::class;
    }
}
