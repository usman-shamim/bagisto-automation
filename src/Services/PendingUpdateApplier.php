<?php

namespace Webkul\Automation\Services;

use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductInventoryRepository;
use Webkul\Product\Repositories\ProductRepository;

class PendingUpdateApplier
{
    public const DRIFT_THRESHOLD = 0.10;

    public const DEFAULT_INVENTORY_SOURCE_ID = 1;

    public function __construct(
        protected ProductRepository $products,
        protected ProductInventoryRepository $inventories,
    ) {}

    /**
     * Apply an approved pending update to the underlying product.
     *
     * Behaviour:
     *   - Only pending rows are eligible. Terminal rows are no-ops.
     *   - Re-reads current price/stock; if either snapshot has drifted by
     *     more than DRIFT_THRESHOLD since staging, the row is marked
     *     failed with an explanatory error_message — the agent must
     *     re-scrape and re-stage.
     *   - On success, mutates the product through Bagisto's own repositories
     *     (which fire catalog.product.update.after for webhook delivery).
     *
     * Returns the (now refreshed) PendingProductUpdate row.
     */
    public function apply(PendingProductUpdate $row, ?int $reviewerAdminId = null, ?string $note = null): PendingProductUpdate
    {
        if (! $row->isPending()) {
            return $row->fresh();
        }

        $product = ProductProxy::modelClass()::query()
            ->with('inventories')
            ->whereKey($row->product_id)
            ->first();

        if (! $product) {
            return $this->fail($row, $reviewerAdminId, $note, "Product {$row->product_id} no longer exists.");
        }

        $currentPrice = $product->price !== null ? (float) $product->price : null;
        $currentStock = (int) $product->inventories->sum('qty');

        if ($row->proposed_price !== null && $this->driftedBeyond((float) $row->current_price_snapshot, $currentPrice)) {
            return $this->fail(
                $row, $reviewerAdminId, $note,
                "Price drift exceeded threshold: snapshot={$row->current_price_snapshot}, current={$currentPrice}.",
            );
        }

        if ($row->proposed_stock !== null && $this->driftedBeyond((float) $row->current_stock_snapshot, (float) $currentStock)) {
            return $this->fail(
                $row, $reviewerAdminId, $note,
                "Stock drift exceeded threshold: snapshot={$row->current_stock_snapshot}, current={$currentStock}.",
            );
        }

        if ($row->proposed_price !== null) {
            $this->products->update(
                ['price' => (float) $row->proposed_price],
                $product->id,
                ['price'],
            );
        }

        if ($row->proposed_stock !== null) {
            $this->inventories->saveInventories([
                'inventories' => [self::DEFAULT_INVENTORY_SOURCE_ID => (int) $row->proposed_stock],
            ], $product);
        }

        $row->forceFill([
            'status' => PendingProductUpdate::STATUS_APPLIED,
            'reviewed_by_admin_id' => $reviewerAdminId,
            'reviewed_at' => now(),
            'review_note' => $note,
            'applied_at' => now(),
            'error_message' => null,
        ])->save();

        return $row->fresh();
    }

    /**
     * Mark a pending row as rejected by an admin.
     */
    public function reject(PendingProductUpdate $row, ?int $reviewerAdminId, ?string $note): PendingProductUpdate
    {
        if (! $row->isPending()) {
            return $row->fresh();
        }

        $row->forceFill([
            'status' => PendingProductUpdate::STATUS_REJECTED,
            'reviewed_by_admin_id' => $reviewerAdminId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        return $row->fresh();
    }

    /**
     * A snapshot of zero (or null) and a current of zero (or null) is treated
     * as "no drift". A null snapshot with a non-zero current is a no-baseline
     * case — we accept the apply rather than block it (an explicit policy
     * choice: we'd rather mutate state that the admin has reviewed than block
     * over missing telemetry).
     */
    protected function driftedBeyond(?float $snapshot, ?float $current): bool
    {
        if ($snapshot === null || $current === null) {
            return false;
        }

        if ($snapshot == 0.0) {
            return $current != 0.0;
        }

        return abs(($current - $snapshot) / $snapshot) > self::DRIFT_THRESHOLD;
    }

    protected function fail(PendingProductUpdate $row, ?int $reviewerAdminId, ?string $note, string $message): PendingProductUpdate
    {
        $row->forceFill([
            'status' => PendingProductUpdate::STATUS_FAILED,
            'reviewed_by_admin_id' => $reviewerAdminId,
            'reviewed_at' => now(),
            'review_note' => $note,
            'error_message' => $message,
        ])->save();

        return $row->fresh();
    }
}
