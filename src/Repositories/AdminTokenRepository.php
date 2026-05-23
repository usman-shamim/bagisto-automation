<?php

namespace Webkul\Automation\Repositories;

use Webkul\Automation\Contracts\AdminToken;
use Webkul\Core\Eloquent\Repository;

class AdminTokenRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return AdminToken::class;
    }
}
