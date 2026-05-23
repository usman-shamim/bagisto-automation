<?php

namespace Webkul\Automation\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Automation\Models\CompetitorProductMappingProxy;
use Webkul\Product\Models\ProductProxy;

class MappingsController extends Controller
{
    /**
     * POST /admin/automation/products/{productId}/mappings
     */
    public function store(Request $request, int $productId): RedirectResponse
    {
        $product = ProductProxy::modelClass()::query()->find($productId);

        if (! $product) {
            return back()->withErrors(['mapping' => "Product {$productId} not found."]);
        }

        $request->validate([
            'competitor_name' => ['required', 'string', 'max:64'],
            'competitor_url' => ['required', 'url', 'max:512'],
        ]);

        $exists = CompetitorProductMappingProxy::modelClass()::query()
            ->where('product_id', $productId)
            ->where('competitor_url', $request->input('competitor_url'))
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'competitor_url' => 'This competitor URL is already mapped to the product.',
            ])->withInput();
        }

        CompetitorProductMappingProxy::modelClass()::create([
            'product_id' => $productId,
            'competitor_name' => $request->input('competitor_name'),
            'competitor_url' => $request->input('competitor_url'),
        ]);

        return back()->with('success', 'Competitor mapping added.');
    }

    /**
     * DELETE /admin/automation/products/{productId}/mappings/{mappingId}
     */
    public function destroy(int $productId, int $mappingId): RedirectResponse
    {
        $row = CompetitorProductMappingProxy::modelClass()::query()
            ->where('id', $mappingId)
            ->where('product_id', $productId)
            ->first();

        if ($row) {
            $row->delete();

            return back()->with('success', 'Competitor mapping removed.');
        }

        return back()->withErrors([
            'mapping' => "Mapping {$mappingId} not found for product {$productId}.",
        ]);
    }
}
