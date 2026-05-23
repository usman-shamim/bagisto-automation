<?php

namespace Webkul\Automation\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Product\Models\ProductProxy;

class MappingsController extends Controller
{
    /**
     * GET /api/automation/v1/products/{productId}/mappings
     */
    public function index(int $productId): JsonResponse
    {
        if (! ProductProxy::modelClass()::query()->whereKey($productId)->exists()) {
            return $this->notFound($productId);
        }

        $mappings = CompetitorProductMappingProxy::modelClass()::query()
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->present($m))
            ->values();

        return new JsonResponse(['data' => $mappings]);
    }

    /**
     * POST /api/automation/v1/products/{productId}/mappings
     */
    public function store(Request $request, int $productId): JsonResponse
    {
        if (! ProductProxy::modelClass()::query()->whereKey($productId)->exists()) {
            return $this->notFound($productId);
        }

        $validator = Validator::make($request->all(), [
            'competitor_name' => ['required', 'string', 'max:64'],
            'competitor_url' => ['required', 'url', 'max:512'],
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

        $exists = CompetitorProductMappingProxy::modelClass()::query()
            ->where('product_id', $productId)
            ->where('competitor_url', $request->input('competitor_url'))
            ->exists();

        if ($exists) {
            return new JsonResponse([
                'error' => [
                    'code' => 'conflict',
                    'message' => 'This competitor URL is already mapped to the product.',
                ],
            ], 409);
        }

        $mapping = CompetitorProductMappingProxy::modelClass()::create([
            'product_id' => $productId,
            'competitor_name' => $request->input('competitor_name'),
            'competitor_url' => $request->input('competitor_url'),
        ]);

        return new JsonResponse(['data' => $this->present($mapping)], 201);
    }

    /**
     * DELETE /api/automation/v1/products/{productId}/mappings/{mappingId}
     */
    public function destroy(int $productId, int $mappingId): JsonResponse
    {
        $row = CompetitorProductMappingProxy::modelClass()::query()
            ->where('id', $mappingId)
            ->where('product_id', $productId)
            ->first();

        if (! $row) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_found',
                    'message' => "No mapping {$mappingId} for product {$productId}.",
                ],
            ], 404);
        }

        $row->delete();

        return new JsonResponse(null, 204);
    }

    private function present($mapping): array
    {
        return [
            'id' => $mapping->id,
            'product_id' => $mapping->product_id,
            'competitor_name' => $mapping->competitor_name,
            'competitor_url' => $mapping->competitor_url,
            'last_scraped_at' => optional($mapping->last_scraped_at)->toIso8601String(),
            'created_at' => optional($mapping->created_at)->toIso8601String(),
        ];
    }

    private function notFound(int $productId): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'not_found',
                'message' => "Product {$productId} not found.",
            ],
        ], 404);
    }
}
