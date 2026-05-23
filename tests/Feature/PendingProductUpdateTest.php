<?php

use Illuminate\Database\QueryException;
use Webkul\Automation\Contracts\PendingProductUpdate as PendingProductUpdateContract;
use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Automation\Models\PendingProductUpdateProxy;
use Webkul\Automation\Repositories\PendingProductUpdateRepository;
use Webkul\Automation\Services\TokenIssuer;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\AdminProxy;

it('resolves the proxy to the concrete model class', function () {
    expect(PendingProductUpdateProxy::modelClass())->toBe(PendingProductUpdate::class);
});

it('binds the contract to the concrete model in the repository', function () {
    $repo = app(PendingProductUpdateRepository::class);

    expect($repo->model())->toBe(PendingProductUpdateContract::class);
});

it('persists a row with the default pending status and casts flags to array', function () {
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();
    $admin = AdminProxy::modelClass()::first();
    $token = app(TokenIssuer::class)->issue($admin, 'test', ['write:staged'])['token'];

    $row = PendingProductUpdateProxy::modelClass()::create([
        'product_id' => $product->id,
        'submitted_by_token_id' => $token->id,
        'source_url' => 'https://daraz.pk/listing-abc',
        'proposed_price' => 4999.5000,
        'proposed_stock' => 12,
        'current_price_snapshot' => 5499.0000,
        'current_stock_snapshot' => 10,
        'flags' => ['price_drop_50pct'],
        'confidence' => 0.92,
    ]);

    $fresh = PendingProductUpdateProxy::modelClass()::find($row->id);

    expect($fresh->status)->toBe(PendingProductUpdate::STATUS_PENDING)
        ->and($fresh->flags)->toBe(['price_drop_50pct'])
        ->and((float) $fresh->proposed_price)->toBe(4999.5)
        ->and((float) $fresh->confidence)->toBe(0.92)
        ->and($fresh->isPending())->toBeTrue()
        ->and($fresh->isTerminal())->toBeFalse();
});

it('marks rejected/applied/failed rows as terminal', function () {
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();

    foreach ([
        PendingProductUpdate::STATUS_REJECTED,
        PendingProductUpdate::STATUS_APPLIED,
        PendingProductUpdate::STATUS_FAILED,
    ] as $terminalStatus) {
        $row = PendingProductUpdateProxy::modelClass()::create([
            'product_id' => $product->id,
            'status' => $terminalStatus,
        ]);

        expect($row->isPending())->toBeFalse()
            ->and($row->isTerminal())->toBeTrue();
    }
});

it('rejects an orphan product_id via the foreign key', function () {
    expect(fn () => PendingProductUpdateProxy::modelClass()::create([
        'product_id' => 9999999,
        'status' => PendingProductUpdate::STATUS_PENDING,
    ]))->toThrow(QueryException::class);
});

it('nullifies submitted_by_token_id when the token is deleted', function () {
    $product = ProductProxy::modelClass()::query()->whereNull('parent_id')->first();
    $admin = AdminProxy::modelClass()::first();
    $token = app(TokenIssuer::class)->issue($admin, 'test', ['write:staged'])['token'];

    $row = PendingProductUpdateProxy::modelClass()::create([
        'product_id' => $product->id,
        'submitted_by_token_id' => $token->id,
        'status' => PendingProductUpdate::STATUS_PENDING,
    ]);

    $token->delete();

    $fresh = PendingProductUpdateProxy::modelClass()::find($row->id);

    expect($fresh->submitted_by_token_id)->toBeNull();
});
