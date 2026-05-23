<x-admin::layouts>
    <x-slot:title>
        Automation — API Tokens
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold dark:text-white">Automation API Tokens</p>
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

    {{-- One-shot plaintext display. The token will never be shown again. --}}
    @if ($plaintext)
        <div class="my-4 rounded border-2 border-yellow-400 bg-yellow-50 p-4">
            <p class="mb-2 font-bold text-yellow-900">
                Copy this token now — it will never be shown again.
            </p>
            <p class="mb-2 text-sm text-yellow-900">
                Token for <em>{{ $createdName }}</em>:
            </p>
            <div class="flex items-center gap-2">
                <input
                    type="text"
                    value="{{ $plaintext }}"
                    readonly
                    onclick="this.select();"
                    class="flex-1 rounded border border-gray-300 bg-white px-2 py-1 font-mono text-sm"
                >
                <button
                    type="button"
                    onclick="navigator.clipboard.writeText('{{ $plaintext }}'); this.innerText='Copied';"
                    class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700"
                >
                    Copy
                </button>
            </div>
            <p class="mt-2 text-xs text-yellow-800">
                Bagisto stores only the SHA-256 hash; we cannot recover the
                plaintext after you leave this page.
            </p>
        </div>
    @endif

    {{-- Create form --}}
    <div class="mt-4 rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Create a new token</p>

        <form method="POST"
              action="{{ route('admin.automation.tokens.store') }}"
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
                    placeholder="n8n agent"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <div class="flex flex-col">
                <label class="mb-1 text-xs">Scopes</label>
                <div class="flex flex-wrap gap-3 text-sm">
                    @foreach ($scopeOptions as $scope)
                        <label class="inline-flex items-center gap-1">
                            <input
                                type="checkbox"
                                name="scopes[]"
                                value="{{ $scope }}"
                                @checked(in_array($scope, (array) old('scopes', [])))
                            >
                            <span>{{ $scope }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col">
                <label class="text-xs">Expires at (optional)</label>
                <input
                    type="datetime-local"
                    name="expires_at"
                    value="{{ old('expires_at') }}"
                    class="rounded border border-gray-300 px-2 py-1 text-sm"
                >
            </div>

            <button type="submit"
                    class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                Generate token
            </button>
        </form>
    </div>

    <div class="mt-4 overflow-x-auto rounded bg-white dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b">
                <tr>
                    <th class="p-2">ID</th>
                    <th class="p-2">Name</th>
                    <th class="p-2">Scopes</th>
                    <th class="p-2">Last 4</th>
                    <th class="p-2">Last used</th>
                    <th class="p-2">Expires</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Created</th>
                    <th class="p-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tokens as $token)
                    <tr class="border-b last:border-0">
                        <td class="p-2">{{ $token->id }}</td>
                        <td class="p-2 font-medium">{{ $token->name }}</td>
                        <td class="p-2 text-xs">
                            @foreach ((array) $token->scopes as $s)
                                <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5">{{ $s }}</span>
                            @endforeach
                        </td>
                        <td class="p-2 font-mono text-xs">…{{ $token->last_four }}</td>
                        <td class="p-2 text-xs text-gray-500">
                            {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : '—' }}
                        </td>
                        <td class="p-2 text-xs text-gray-500">
                            {{ $token->expires_at ? $token->expires_at->toDayDateTimeString() : 'never' }}
                        </td>
                        <td class="p-2">
                            @if ($token->isRevoked())
                                <span class="rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-700">revoked</span>
                            @elseif ($token->isExpired())
                                <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">expired</span>
                            @else
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">active</span>
                            @endif
                        </td>
                        <td class="p-2 text-xs text-gray-500">{{ optional($token->created_at)->diffForHumans() }}</td>
                        <td class="p-2">
                            @if (! $token->isRevoked())
                                <form
                                    method="POST"
                                    action="{{ route('admin.automation.tokens.revoke', ['id' => $token->id]) }}"
                                    onsubmit="return confirm('Revoke this token? Any agent using it will lose access immediately.');"
                                >
                                    @csrf
                                    <button type="submit"
                                            class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50">
                                        Revoke
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="p-4 text-center italic text-gray-500">
                            No tokens have been issued yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $tokens->links() }}
    </div>
</x-admin::layouts>
