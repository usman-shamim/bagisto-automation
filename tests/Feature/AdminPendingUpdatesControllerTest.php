<?php

use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Automation\Models\PendingProductUpdateProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\User\Models\AdminProxy;

function pendingProduct()
{
    $p = ProductProxy::modelClass()::query()->whereNull('parent_id')->with('inventories')->first();
    if (! $p) {
        test()->markTestSkipped('No seeded products available.');
    }

    return $p;
}

function stagePendingViaModel(array $overrides = []): PendingProductUpdate
{
    $p = pendingProduct();

    return PendingProductUpdateProxy::modelClass()::create(array_merge([
        'product_id' => $p->id,
        'source_url' => 'https://daraz.pk/admin-pending-test',
        'proposed_price' => $p->price !== null ? (float) $p->price + 25 : 99.0,
        'proposed_stock' => (int) $p->inventories->sum('qty') + 3,
        'current_price_snapshot' => $p->price !== null ? (float) $p->price : null,
        'current_stock_snapshot' => (int) $p->inventories->sum('qty'),
        'status' => PendingProductUpdate::STATUS_PENDING,
    ], $overrides));
}

function loginAsAdminUser(): object
{
    $admin = AdminProxy::modelClass()::first();
    test()->actingAs($admin, 'admin');

    return $admin;
}

it('redirects unauthenticated admins away from the pending-updates list', function () {
    $this->get('/admin/automation/pending-updates')->assertRedirect();
});

it('lists pending updates filtered by status', function () {
    loginAsAdminUser();
    stagePendingViaModel();

    $html = $this->get('/admin/automation/pending-updates')
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('Pending Product Updates')
        ->and($html)->toContain('https://daraz.pk/admin-pending-test')
        ->and($html)->toContain('Approve')
        ->and($html)->toContain('Reject');
});

it('approves a pending update and mutates the product', function () {
    $admin = loginAsAdminUser();
    $row = stagePendingViaModel();

    $this->post("/admin/automation/pending-updates/{$row->id}/approve", [
        'review_note' => 'auto-applied via test',
    ])->assertRedirect();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(PendingProductUpdate::STATUS_APPLIED)
        ->and($fresh->reviewed_by_admin_id)->toBe($admin->id)
        ->and($fresh->applied_at)->not->toBeNull();

    $product = app(ProductRepository::class)->find($row->product_id);
    expect((float) $product->price)->toBe((float) $row->proposed_price);
    expect((int) $product->inventories->sum('qty'))->toBe((int) $row->proposed_stock);
});

it('rejects a pending update without touching the product', function () {
    $admin = loginAsAdminUser();
    $product = pendingProduct();
    $originalPrice = $product->price !== null ? (float) $product->price : null;
    $originalStock = (int) $product->inventories->sum('qty');

    $row = stagePendingViaModel();

    $this->post("/admin/automation/pending-updates/{$row->id}/reject", [
        'review_note' => 'not credible',
    ])->assertRedirect();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(PendingProductUpdate::STATUS_REJECTED)
        ->and($fresh->reviewed_by_admin_id)->toBe($admin->id)
        ->and($fresh->review_note)->toBe('not credible');

    $current = app(ProductRepository::class)->find($row->product_id);
    if ($originalPrice !== null) {
        expect((float) $current->price)->toBe($originalPrice);
    }
    expect((int) $current->inventories->sum('qty'))->toBe($originalStock);
});

it('refuses to approve a row that is already terminal', function () {
    loginAsAdminUser();

    $row = stagePendingViaModel(['status' => PendingProductUpdate::STATUS_REJECTED]);

    $this->post("/admin/automation/pending-updates/{$row->id}/approve")
        ->assertRedirect()
        ->assertSessionHasErrors('pending_update');
});

it('returns a session error when approving a row that no longer exists', function () {
    loginAsAdminUser();

    $this->post('/admin/automation/pending-updates/9999999/approve')
        ->assertRedirect()
        ->assertSessionHasErrors('pending_update');
});

it('flashes a drift failure when the price has drifted since staging', function () {
    loginAsAdminUser();
    $product = pendingProduct();
    $repo = app(ProductRepository::class);

    $repo->update(['price' => 200.0], $product->id, ['price']);

    $row = stagePendingViaModel([
        'proposed_price' => 50.0,
        'current_price_snapshot' => 100.0,   // live price is now 200 — 100% drift
        'proposed_stock' => null,
    ]);

    $this->post("/admin/automation/pending-updates/{$row->id}/approve")
        ->assertRedirect()
        ->assertSessionHasErrors('pending_update');

    expect($row->fresh()->status)->toBe(PendingProductUpdate::STATUS_FAILED);
});
