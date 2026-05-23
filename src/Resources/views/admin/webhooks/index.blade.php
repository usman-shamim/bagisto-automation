<x-admin::layouts>
    <x-slot:title>
        Automation — Webhooks
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold dark:text-white">Outbound Webhooks</p>
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

    {{-- One-shot secret display — only visible on the redirect after create. --}}
    @if ($plaintextSecret)
        <div class="my-4 rounded border-2 border-yellow-400 bg-yellow-50 p-4">
            <p class="mb-2 font-bold text-yellow-900">
                Copy this signing secret now — it will never be shown again.
            </p>
            <p class="mb-2 text-sm text-yellow-900">
                Secret for <em>{{ $createdName }}</em>:
            </p>
            <div class="flex items-center gap-2">
                <input
                    type="text"
                    value="{{ $plaintextSecret }}"
                    readonly
                    onclick="this.select();"
                    class="flex-1 rounded border border-gray-300 bg-white px-2 py-1 font-mono text-sm"
                >
                <button
                    type="button"
                    onclick="navigator.clipboard.writeText('{{ $plaintextSecret }}'); this.innerText='Copied';"
                    class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700"
                >
                    Copy
                </button>
            </div>
            <p class="mt-2 text-xs text-yellow-800">
                Use this secret to verify the <code>X-Bagisto-Signature</code> header on incoming
                webhook requests at your endpoint. To rotate, delete this webhook and create a new one.
            </p>
        </div>
    @endif

    <div class="mt-4 rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Register a new webhook</p>

        <form method="POST"
              action="{{ route('admin.automation.webhooks.store') }}"
              class="flex flex-wrap items-end gap-3">
            @csrf

            <div class="flex flex-col">
                <label class="text-xs">Name</label>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    maxlength="64"
                    required
                    placeholder="n8n product updates"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <div class="flex flex-1 flex-col">
                <label class="text-xs">Target URL</label>
                <input
                    type="url"
                    name="target_url"
                    value="{{ old('target_url') }}"
                    maxlength="2048"
                    required
                    placeholder="https://n8n.example.com/webhook/bagisto-product-updated"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <div class="flex flex-col">
                <label class="text-xs">Event</label>
                <select name="event" class="rounded border border-gray-300 px-2 py-1 text-sm">
                    @foreach ($eventOptions as $event)
                        <option value="{{ $event }}" @selected(old('event') === $event)>{{ $event }}</option>
                    @endforeach
                </select>
            </div>

            <label class="inline-flex items-center gap-1 text-sm">
                <input type="checkbox" name="is_active" value="1" checked>
                Active
            </label>

            <button type="submit"
                    class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                Register webhook
            </button>
        </form>
    </div>

    <div class="mt-4 overflow-x-auto rounded bg-white dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b">
                <tr>
                    <th class="p-2">ID</th>
                    <th class="p-2">Name</th>
                    <th class="p-2">Event</th>
                    <th class="p-2">URL</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Created</th>
                    <th class="p-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($webhooks as $webhook)
                    <tr class="border-b last:border-0">
                        <td class="p-2">{{ $webhook->id }}</td>
                        <td class="p-2 font-medium">{{ $webhook->name }}</td>
                        <td class="p-2 text-xs">
                            <span class="rounded bg-gray-100 px-1.5 py-0.5">{{ $webhook->event }}</span>
                        </td>
                        <td class="p-2 max-w-md truncate font-mono text-xs">
                            {{ $webhook->target_url }}
                        </td>
                        <td class="p-2">
                            @if ($webhook->is_active)
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">active</span>
                            @else
                                <span class="rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-700">inactive</span>
                            @endif
                        </td>
                        <td class="p-2 text-xs text-gray-500">{{ optional($webhook->created_at)->diffForHumans() }}</td>
                        <td class="p-2 whitespace-nowrap">
                            <form method="POST"
                                  action="{{ route('admin.automation.webhooks.toggle', ['id' => $webhook->id]) }}"
                                  class="inline">
                                @csrf
                                <button type="submit"
                                        class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50">
                                    {{ $webhook->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                            <form method="POST"
                                  action="{{ route('admin.automation.webhooks.destroy', ['id' => $webhook->id]) }}"
                                  class="inline"
                                  onsubmit="return confirm('Delete this webhook and all its delivery history?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-4 text-center italic text-gray-500">
                            No webhooks registered yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $webhooks->links() }}
    </div>

    <div class="mt-6">
        <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">Recent deliveries</p>
        <div class="overflow-x-auto rounded bg-white dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead class="border-b">
                    <tr>
                        <th class="p-2">ID</th>
                        <th class="p-2">Webhook</th>
                        <th class="p-2">Event</th>
                        <th class="p-2">Attempt</th>
                        <th class="p-2">Response</th>
                        <th class="p-2">Outcome</th>
                        <th class="p-2">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentDeliveries as $delivery)
                        <tr class="border-b last:border-0">
                            <td class="p-2">{{ $delivery->id }}</td>
                            <td class="p-2">{{ optional($delivery->webhook)->name ?? '—' }}</td>
                            <td class="p-2 text-xs">{{ $delivery->event }}</td>
                            <td class="p-2 text-xs">{{ $delivery->attempt }}</td>
                            <td class="p-2 text-xs">{{ $delivery->response_status ?? '—' }}</td>
                            <td class="p-2 text-xs">
                                @if ($delivery->succeeded_at)
                                    <span class="text-green-700">delivered</span>
                                @elseif ($delivery->next_retry_at)
                                    <span class="text-yellow-700">retry @ {{ $delivery->next_retry_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-red-700">failed</span>
                                @endif
                            </td>
                            <td class="p-2 text-xs text-gray-500">{{ optional($delivery->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-4 text-center italic text-gray-500">
                                No deliveries yet — they appear here after products are updated.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
