<?php

namespace Webkul\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Automation\Contracts\PendingProductUpdate as PendingProductUpdateContract;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

class PendingProductUpdate extends Model implements PendingProductUpdateContract
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $table = 'pending_product_updates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'submitted_by_token_id',
        'external_request_id',
        'source_url',
        'proposed_price',
        'proposed_stock',
        'current_price_snapshot',
        'current_stock_snapshot',
        'flags',
        'confidence',
        'status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'review_note',
        'applied_at',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'proposed_price' => 'decimal:4',
        'proposed_stock' => 'integer',
        'current_price_snapshot' => 'decimal:4',
        'current_stock_snapshot' => 'integer',
        'flags' => 'array',
        'confidence' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /**
     * Target product.
     */
    public function product()
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    /**
     * Token that submitted the proposed update.
     */
    public function submittedByToken()
    {
        return $this->belongsTo(AdminTokenProxy::modelClass(), 'submitted_by_token_id');
    }

    /**
     * Admin who reviewed the update (approve/reject).
     */
    public function reviewedByAdmin()
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'reviewed_by_admin_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_REJECTED,
            self::STATUS_APPLIED,
            self::STATUS_FAILED,
        ], true);
    }
}
