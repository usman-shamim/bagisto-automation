@php
    use Webkul\Automation\Models\PendingProductUpdate;
@endphp

<x-admin::layouts>
    <x-slot:title>
        Automation — Pending Updates
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold dark:text-white">Pending Product Updates</p>
    </div>

    @if ($errors->any())
        <div class="my-3 rounded border border-red-200 bg-red-50 p-3 text-red-700">
            @foreach ($errors->all() as $msg)
                <div>{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if (session('success'))
        <div class="my-3 rounded border border-green-200 bg-green-50 p-3 text-green-700">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET"
          action="{{ route('admin.automation.pending-updates.index') }}"
          class="mt-4 flex flex-wrap items-end gap-2 rounded bg-white p-3 dark:bg-gray-900">
        <div class="flex flex-col">
            <label class="text-xs">Status</label>
            <select name="status" class="rounded border border-gray-300 px-2 py-1 text-sm">
                @foreach ($statusOptions as $opt)
                    <option value="{{ $opt }}" @selected($filterStatus === $opt)>{{ $opt }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col">
            <label class="text-xs">Product ID</label>
            <input type="number"
                   name="product_id"
                   value="{{ $filterProductId }}"
                   class="rounded border border-gray-300 px-2 py-1 text-sm"
                   placeholder="e.g. 17">
        </div>

        <div class="flex flex-col">
            <label class="text-xs">Flag</label>
            <input type="text"
                   name="flag"
                   value="{{ $filterFlag }}"
                   maxlength="64"
                   class="rounded border border-gray-300 px-2 py-1 text-sm"
                   placeholder="e.g. price_drop_50pct">
        </div>

        <button type="submit"
                class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
            Filter
        </button>

        <a href="{{ route('admin.automation.pending-updates.index') }}"
           class="rounded border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50">
            Reset
        </a>
    </form>

    <div class="mt-4 overflow-x-auto rounded bg-white dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b">
                <tr>
                    <th class="p-2">ID</th>
                    <th class="p-2">Product</th>
                    <th class="p-2">Source</th>
                    <th class="p-2">Proposed price</th>
                    <th class="p-2">Proposed stock</th>
                    <th class="p-2">Snapshots</th>
                    <th class="p-2">Flags</th>
                    <th class="p-2">Conf.</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Submitted</th>
                    <th class="p-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b last:border-0">
                        <td class="p-2">{{ $row->id }}</td>
                        <td class="p-2">
                            @if ($row->product)
                                <div class="font-medium">{{ $row->product->name ?? $row->product->sku }}</div>
                                <div class="text-xs text-gray-500">#{{ $row->product->id }} {{ $row->product->sku }}</div>
                            @else
                                <span class="italic text-red-500">deleted (#{{ $row->product_id }})</span>
                            @endif
                        </td>
                        <td class="p-2 max-w-xs truncate">
                            @if ($row->source_url)
                                <a href="{{ $row->source_url }}" target="_blank" rel="noopener"
                                   class="text-blue-600 hover:underline">{{ $row->source_url }}</a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="p-2">{{ $row->proposed_price ?? '—' }}</td>
                        <td class="p-2">{{ $row->proposed_stock ?? '—' }}</td>
                        <td class="p-2 text-xs text-gray-500">
                            p: {{ $row->current_price_snapshot ?? '—' }}<br>
                            s: {{ $row->current_stock_snapshot ?? '—' }}
                        </td>
                        <td class="p-2">
                            @if (! empty($row->flags))
                                @foreach ((array) $row->flags as $f)
                                    <span class="inline-block rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">{{ $f }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="p-2">{{ $row->confidence ?? '—' }}</td>
                        <td class="p-2">
                            @php
                                $color = match ($row->status) {
                                    PendingProductUpdate::STATUS_PENDING => 'bg-blue-100 text-blue-800',
                                    PendingProductUpdate::STATUS_APPLIED => 'bg-green-100 text-green-800',
                                    PendingProductUpdate::STATUS_REJECTED => 'bg-gray-200 text-gray-800',
                                    PendingProductUpdate::STATUS_FAILED => 'bg-red-100 text-red-800',
                                    default => 'bg-yellow-100 text-yellow-800',
                                };
                            @endphp
                            <span class="rounded px-2 py-0.5 text-xs {{ $color }}">{{ $row->status }}</span>
                            @if ($row->status === PendingProductUpdate::STATUS_FAILED && $row->error_message)
                                <div class="mt-1 text-xs text-red-600">{{ $row->error_message }}</div>
                            @endif
                        </td>
                        <td class="p-2 text-xs text-gray-500">{{ optional($row->created_at)->diffForHumans() }}</td>
                        <td class="p-2 whitespace-nowrap">
                            @if ($row->isPending())
                                <form method="POST"
                                      action="{{ route('admin.automation.pending-updates.approve', ['id' => $row->id]) }}"
                                      class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="rounded bg-green-600 px-2 py-0.5 text-xs text-white hover:bg-green-700">
                                        Approve
                                    </button>
                                </form>
                                <form method="POST"
                                      action="{{ route('admin.automation.pending-updates.reject', ['id' => $row->id]) }}"
                                      class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50">
                                        Reject
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="p-4 text-center italic text-gray-500">
                            No pending updates match these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $rows->links() }}
    </div>
</x-admin::layouts>
