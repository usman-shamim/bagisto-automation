<?php

namespace Webkul\Automation\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Product\Models\ProductProxy;

class ProductsController extends Controller
{
    /**
     * GET /api/automation/v1/products
     *
     * Query params:
     *   per_page       int 1..100 (default 50)
     *   page           int (default 1)
     *   updated_since  ISO-8601 datetime (optional) — return only products
     *                  whose updated_at is at or after this moment
     *
     * Response shape:
     *   {
     *     "data": [
     *       {"id":..., "sku":..., "name":..., "price":..., "stock_total":...,
     *        "status":..., "updated_at":...,
     *        "competitor_mappings":[
     *           {"id":..., "competitor_name":..., "competitor_url":..., "last_scraped_at":...}
     *        ]}
     *     ],
     *     "meta": {"current_page":..., "last_page":..., "per_page":..., "total":...}
     *   }
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        $query = ProductProxy::modelClass()::query()
            ->whereNull('parent_id')
            ->with('inventories')
            ->orderBy('id');

        if ($since = $request->input('updated_since')) {
            try {
                $query->where('updated_at', '>=', Carbon::parse($since));
            } catch (\Exception $e) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'invalid_parameter',
                        'message' => 'updated_since must be a valid ISO-8601 datetime.',
                    ],
                ], 422);
            }
        }

        $paginator = $query->paginate($perPage);

        $productIds = $paginator->getCollection()->pluck('id')->all();

        $mappingsByProduct = CompetitorProductMappingProxy::modelClass()::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $data = $paginator->getCollection()->map(function ($product) use ($mappingsByProduct) {
            $mappings = $mappingsByProduct->get($product->id, collect());

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->price !== null ? (float) $product->price : null,
                'stock_total' => (int) $product->inventories->sum('qty'),
                'status' => (bool) $product->status,
                'updated_at' => optional($product->updated_at)->toIso8601String(),
                'competitor_mappings' => $mappings->map(fn ($m) => [
                    'id' => $m->id,
                    'competitor_name' => $m->competitor_name,
                    'competitor_url' => $m->competitor_url,
                    'last_scraped_at' => optional($m->last_scraped_at)->toIso8601String(),
                ])->values()->toArray(),
            ];
        })->values();

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
