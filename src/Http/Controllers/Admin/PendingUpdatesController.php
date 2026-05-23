<?php

namespace Webkul\Automation\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Automation\Models\PendingProductUpdateProxy;
use Webkul\Automation\Services\PendingUpdateApplier;

class PendingUpdatesController extends Controller
{
    public function __construct(protected PendingUpdateApplier $applier) {}

    /**
     * GET /admin/automation/pending-updates
     */
    public function index(Request $request): View
    {
        $status = $request->input('status', PendingProductUpdate::STATUS_PENDING);
        $productId = $request->input('product_id');
        $flag = $request->input('flag');

        $query = PendingProductUpdateProxy::modelClass()::query()
            ->with('product')
            ->orderByDesc('id');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($productId) {
            $query->where('product_id', (int) $productId);
        }

        if ($flag) {
            // flags is a JSON column of strings; whereJsonContains works on MySQL 5.7+
            $query->whereJsonContains('flags', $flag);
        }

        $rows = $query->paginate(25)->appends($request->query());

        return view('automation::admin.pending-updates.index', [
            'rows' => $rows,
            'filterStatus' => $status,
            'filterProductId' => $productId,
            'filterFlag' => $flag,
            'statusOptions' => [
                'pending', 'approved', 'rejected', 'applied', 'failed', 'all',
            ],
        ]);
    }

    /**
     * POST /admin/automation/pending-updates/{id}/approve
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $row = PendingProductUpdateProxy::modelClass()::find($id);

        if (! $row) {
            return back()->withErrors(['pending_update' => "Pending update {$id} not found."]);
        }

        if (! $row->isPending()) {
            return back()->withErrors(['pending_update' => "Update {$id} is no longer pending (status={$row->status})."]);
        }

        $note = $request->input('review_note');
        $adminId = optional(auth('admin')->user())->id;

        $result = $this->applier->apply($row, $adminId, $note);

        if ($result->status === PendingProductUpdate::STATUS_FAILED) {
            return back()->withErrors([
                'pending_update' => "Approval failed: {$result->error_message}",
            ]);
        }

        return back()->with('success', "Pending update {$id} applied to product {$row->product_id}.");
    }

    /**
     * POST /admin/automation/pending-updates/{id}/reject
     */
    public function reject(Request $request, int $id): RedirectResponse
    {
        $row = PendingProductUpdateProxy::modelClass()::find($id);

        if (! $row) {
            return back()->withErrors(['pending_update' => "Pending update {$id} not found."]);
        }

        if (! $row->isPending()) {
            return back()->withErrors(['pending_update' => "Update {$id} is no longer pending (status={$row->status})."]);
        }

        $note = $request->input('review_note');
        $adminId = optional(auth('admin')->user())->id;

        $this->applier->reject($row, $adminId, $note);

        return back()->with('success', "Pending update {$id} rejected.");
    }
}
