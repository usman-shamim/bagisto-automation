<?php

namespace Webkul\Automation\Http\Controllers\Api;

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
     *   product_id      int      required, must exist in products
     *   source_url      string   required, URL (the page the agent scraped)
     *   proposed_price  decimal  optional, >= 0 — required if proposed_stock missing
     *   proposed_stock  int      optional, >= 0 — required if proposed_price missing
     *   flags           array    optional list of short string tags
     *   confidence      float    optional, 0..1
     *
     * Behaviour:
     *   - Captures current price (product attribute) and stock (sum across inventories)
     *     at insert time as the snapshot used later for drift checks at apply time.
     *   - Row is created with status='pending'.
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

        $row = PendingProductUpdateProxy::modelClass()::create([
            'product_id' => $product->id,
            'submitted_by_token_id' => $token?->id,
            'source_url' => $request->input('source_url'),
            'proposed_price' => $request->input('proposed_price'),
            'proposed_stock' => $request->input('proposed_stock'),
            'current_price_snapshot' => $product->price !== null ? (float) $product->price : null,
            'current_stock_snapshot' => (int) $product->inventories->sum('qty'),
            'flags' => $request->input('flags'),
            'confidence' => $request->input('confidence'),
            'status' => PendingProductUpdate::STATUS_PENDING,
        ]);

        return new JsonResponse(['data' => $this->present($row)], 201);
    }

    private function present($row): array
    {
        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'submitted_by_token_id' => $row->submitted_by_token_id,
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
