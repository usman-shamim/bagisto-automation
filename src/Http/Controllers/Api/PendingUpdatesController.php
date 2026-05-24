<?php

namespace Webkul\Automation\Http\Controllers\Api;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Webkul\Automation\Models\PendingProductUpdate;
use Webkul\Automation\Models\PendingProductUpdateProxy;
use Webkul\Product\Models\ProductProxy;

class PendingUpdatesController extends Controller
{
    /**
     * POST /api/automation/v1/pending-updates
     *
     * Payload:
     *   product_id          int      required, must exist in products
     *   source_url          string   required, URL (the page the agent scraped)
     *   proposed_price      decimal  optional, >= 0 — required if proposed_stock missing
     *   proposed_stock      int      optional, >= 0 — required if proposed_price missing
     *   flags               array    optional list of short string tags
     *   confidence          float    optional, 0..1
     *   external_request_id string   optional, idempotency key. Same value from the
     *                                same token returns the original row with 200.
     *
     * Behaviour:
     *   - Captures current price (product attribute) and stock (sum across inventories)
     *     at insert time as the snapshot used later for drift checks at apply time.
     *   - Row is created with status='pending'.
     *   - external_request_id is unique per (token, value); duplicates return the
     *     existing row with HTTP 200 so client retries are safe.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer'],
            'source_url' => ['required', 'url', 'max:2048'],
            'proposed_price' => ['nullable', 'numeric', 'min:0', 'required_without:proposed_stock'],
            'proposed_stock' => ['nullable', 'integer', 'min:0', 'required_without:proposed_price'],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['string', 'max:64'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'external_request_id' => ['nullable', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'invalid_parameter',
                    'message' => 'One or more fields failed validation.',
                    'details' => $validator->errors()->toArray(),
                ],
            ], 422);
        }

        $productId = (int) $request->input('product_id');

        $product = ProductProxy::modelClass()::query()
            ->with('inventories')
            ->whereKey($productId)
            ->first();

        if (! $product) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_found',
                    'message' => "Product {$productId} not found.",
                ],
            ], 404);
        }

        $token = $request->attributes->get('automation_token');

        // Normalize the idempotency key: an empty string is treated as "no key"
        // so we don't collide multiple "" submissions on the unique index.
        $externalRequestId = $request->input('external_request_id');
        if ($externalRequestId === '') {
            $externalRequestId = null;
        }

        if ($externalRequestId !== null && $token !== null) {
            $existing = PendingProductUpdateProxy::modelClass()::query()
                ->where('submitted_by_token_id', $token->id)
                ->where('external_request_id', $externalRequestId)
                ->first();

            if ($existing) {
                return new JsonResponse(['data' => $this->present($existing)], 200);
            }
        }

        try {
            $row = PendingProductUpdateProxy::modelClass()::create([
                'product_id' => $product->id,
                'submitted_by_token_id' => $token?->id,
                'external_request_id' => $externalRequestId,
                'source_url' => $request->input('source_url'),
                'proposed_price' => $request->input('proposed_price'),
                'proposed_stock' => $request->input('proposed_stock'),
                'current_price_snapshot' => $product->price !== null ? (float) $product->price : null,
                'current_stock_snapshot' => (int) $product->inventories->sum('qty'),
                'flags' => $request->input('flags'),
                'confidence' => $request->input('confidence'),
                'status' => PendingProductUpdate::STATUS_PENDING,
            ]);
        } catch (QueryException $e) {
            // Race condition: a concurrent request with the same idempotency key won.
            // Re-query and return the row that landed first.
            if ($externalRequestId !== null && $token !== null && $this->isDuplicateKey($e)) {
                $existing = PendingProductUpdateProxy::modelClass()::query()
                    ->where('submitted_by_token_id', $token->id)
                    ->where('external_request_id', $externalRequestId)
                    ->first();

                if ($existing) {
                    return new JsonResponse(['data' => $this->present($existing)], 200);
                }
            }

            throw $e;
        }

        return new JsonResponse(['data' => $this->present($row)], 201);
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // MySQL: 1062 / SQLSTATE 23000.
        return $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
    }

    private function present($row): array
    {
        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'submitted_by_token_id' => $row->submitted_by_token_id,
            'external_request_id' => $row->external_request_id,
            'source_url' => $row->source_url,
            'proposed_price' => $row->proposed_price,
            'proposed_stock' => $row->proposed_stock,
            'current_price_snapshot' => $row->current_price_snapshot,
            'current_stock_snapshot' => $row->current_stock_snapshot,
            'flags' => $row->flags,
            'confidence' => $row->confidence,
            'status' => $row->status,
            'created_at' => optional($row->created_at)->toIso8601String(),
        ];
    }
}
