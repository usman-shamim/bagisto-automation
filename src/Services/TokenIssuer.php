<?php

namespace Webkul\Automation\Services;

use Illuminate\Support\Carbon;
use Webkul\Automation\Contracts\AdminToken;
use Webkul\Automation\Models\AdminTokenProxy;
use Webkul\User\Contracts\Admin as AdminContract;

class TokenIssuer
{
    /**
     * Token prefix — lets secret scanners spot a leaked token in logs / repos.
     */
    public const PREFIX = 'bag_';

    /**
     * Allowed scope identifiers. Adding a new scope here is the single
     * source of truth; the admin UI and middleware read this list.
     */
    public const SCOPES = ['read', 'write:staged', 'write:approved'];

    /**
     * Mint a new bearer token for the given admin.
     *
     * The returned array contains the plaintext token under the `plaintext`
     * key — this is shown to the admin ONCE at creation time and never
     * stored in the database. The persisted row holds only the SHA-256
     * hash plus the last four characters for UI display.
     *
     * @param  array<int, string>  $scopes
     * @return array{plaintext: string, token: AdminToken}
     */
    public function issue(AdminContract $admin, string $name, array $scopes, ?Carbon $expiresAt = null): array
    {
        $invalid = array_diff($scopes, self::SCOPES);

        if (! empty($invalid)) {
            throw new \InvalidArgumentException('Unknown scopes: '.implode(', ', $invalid));
        }

        $plaintext = self::PREFIX.bin2hex(random_bytes(30));

        $token = AdminTokenProxy::modelClass()::create([
            'admin_id' => $admin->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
            'last_four' => substr($plaintext, -4),
            'scopes' => array_values($scopes),
            'expires_at' => $expiresAt,
        ]);

        return [
            'plaintext' => $plaintext,
            'token' => $token,
        ];
    }

    /**
     * Resolve a plaintext bearer to an active token row, or null.
     *
     * Lookup is by SHA-256 hash through a unique index — constant-time
     * at the database level, no row-by-row comparison.
     */
    public function find(string $plaintext): ?object
    {
        if ($plaintext === '') {
            return null;
        }

        $token = AdminTokenProxy::modelClass()::query()
            ->where('token_hash', hash('sha256', $plaintext))
            ->first();

        if (! $token || ! $token->isActive()) {
            return null;
        }

        return $token;
    }

    /**
     * Mark a token revoked.
     */
    public function revoke(object $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Update last_used_at for audit/observability.
     */
    public function touch(object $token): void
    {
        $token->forceFill(['last_used_at' => now()])->save();
    }
}
