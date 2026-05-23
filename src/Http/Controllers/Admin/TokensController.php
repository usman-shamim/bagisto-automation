<?php

namespace Webkul\Automation\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Webkul\Automation\Models\AdminTokenProxy;
use Webkul\Automation\Services\TokenIssuer;

class TokensController extends Controller
{
    public function __construct(protected TokenIssuer $issuer) {}

    /**
     * GET /admin/automation/tokens
     */
    public function index(): View
    {
        $tokens = AdminTokenProxy::modelClass()::query()
            ->orderByDesc('id')
            ->paginate(50);

        return view('automation::admin.tokens.index', [
            'tokens' => $tokens,
            'scopeOptions' => TokenIssuer::SCOPES,
            'plaintext' => session('automation.created_token_plaintext'),
            'createdName' => session('automation.created_token_name'),
        ]);
    }

    /**
     * POST /admin/automation/tokens
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', TokenIssuer::SCOPES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $admin = auth('admin')->user();

        $expiresAt = ! empty($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : null;

        $result = $this->issuer->issue($admin, $data['name'], $data['scopes'], $expiresAt);

        // The plaintext token is flashed to session and shown ONCE on the
        // next page render — never persisted, never logged.
        return redirect()
            ->route('admin.automation.tokens.index')
            ->with('automation.created_token_plaintext', $result['plaintext'])
            ->with('automation.created_token_name', $data['name']);
    }

    /**
     * POST /admin/automation/tokens/{id}/revoke
     */
    public function revoke(int $id): RedirectResponse
    {
        $token = AdminTokenProxy::modelClass()::find($id);

        if (! $token) {
            return back()->withErrors(['token' => "Token {$id} not found."]);
        }

        if ($token->isRevoked()) {
            return back()->withErrors(['token' => "Token {$id} is already revoked."]);
        }

        $this->issuer->revoke($token);

        return back()->with('success', "Token \"{$token->name}\" revoked.");
    }
}
