<?php

namespace Webkul\Automation\Repositories;

use Webkul\Automation\Contracts\CompetitorProductMapping;
use Webkul\Core\Eloquent\Repository;

class CompetitorProductMappingRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return CompetitorProductMapping::class;
    }
}
