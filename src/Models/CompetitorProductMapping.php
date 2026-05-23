<?php

namespace Webkul\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Automation\Contracts\CompetitorProductMapping as CompetitorProductMappingContract;
use Webkul\Product\Models\ProductProxy;

class CompetitorProductMapping extends Model implements CompetitorProductMappingContract
{
    protected $table = 'competitor_product_mappings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'competitor_name',
        'competitor_url',
        'last_scraped_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'last_scraped_at' => 'datetime',
    ];

    /**
     * Mapped Bagisto product.
     */
    public function product()
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }
}
