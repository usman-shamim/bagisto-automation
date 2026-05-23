<?php

namespace Webkul\Automation\Repositories;

use Webkul\Automation\Contracts\PendingProductUpdate;
use Webkul\Core\Eloquent\Repository;

class PendingProductUpdateRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return PendingProductUpdate::class;
    }
}
