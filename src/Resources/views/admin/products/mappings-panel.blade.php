@php
    $mappings = \Webkul\Automation\Models\CompetitorProductMappingProxy::modelClass()::query()
        ->where('product_id', $product->id)
        ->orderBy('id')
        ->get();
@endphp

<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    <p class="mb-4 flex justify-between text-base font-semibold text-gray-800 dark:text-white">
        Competitor Mappings
    </p>

    <div class="text-sm text-gray-600 dark:text-gray-300">
        @if ($errors->any())
            <div class="mb-3 rounded border border-red-200 bg-red-50 p-2 text-red-700">
                @foreach ($errors->all() as $msg)
                    <div>{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        @if (session('success'))
            <div class="mb-3 rounded border border-green-200 bg-green-50 p-2 text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if ($mappings->isEmpty())
            <p class="mb-4 italic text-gray-500">No competitor URLs are mapped to this product yet.</p>
        @else
            <table class="mb-4 w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-1 pr-2">Competitor</th>
                        <th class="py-1 pr-2">URL</th>
                        <th class="py-1 pr-2">Last scraped</th>
                        <th class="py-1 pr-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mappings as $mapping)
                        <tr class="border-b last:border-0">
                            <td class="py-1 pr-2">{{ $mapping->competitor_name }}</td>
                            <td class="py-1 pr-2 truncate" style="max-width: 320px;">
                                <a href="{{ $mapping->competitor_url }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                    {{ $mapping->competitor_url }}
                                </a>
                            </td>
                            <td class="py-1 pr-2">
                                {{ $mapping->last_scraped_at ? $mapping->last_scraped_at->diffForHumans() : '—' }}
                            </td>
                            <td class="py-1 pr-2">
                                <form
                                    method="POST"
                                    action="{{ route('admin.automation.products.mappings.destroy', ['productId' => $product->id, 'mappingId' => $mapping->id]) }}"
                                    onsubmit="return confirm('Remove this competitor mapping?');"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50">
                                        Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form
            method="POST"
            action="{{ route('admin.automation.products.mappings.store', ['productId' => $product->id]) }}"
            class="flex flex-wrap items-end gap-2"
        >
            @csrf

            <div class="flex flex-col">
                <label for="automation_competitor_name" class="text-xs">Competitor</label>
                <input
                    id="automation_competitor_name"
                    type="text"
                    name="competitor_name"
                    value="{{ old('competitor_name') }}"
                    maxlength="64"
                    required
                    placeholder="daraz"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <div class="flex flex-1 flex-col">
                <label for="automation_competitor_url" class="text-xs">Competitor URL</label>
                <input
                    id="automation_competitor_url"
                    type="url"
                    name="competitor_url"
                    value="{{ old('competitor_url') }}"
                    maxlength="512"
                    required
                    placeholder="https://daraz.pk/product-xyz"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <button type="submit" class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                Add mapping
            </button>
        </form>
    </div>
</div>
