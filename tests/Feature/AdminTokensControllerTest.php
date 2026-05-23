<?php

use Illuminate\Support\Carbon;
use Webkul\Automation\Models\AdminTokenProxy;
use Webkul\User\Models\AdminProxy;

function asAdmin(): object
{
    $admin = AdminProxy::modelClass()::first();
    test()->actingAs($admin, 'admin');

    return $admin;
}

it('redirects unauthenticated visitors away from the tokens page', function () {
    $this->get('/admin/automation/tokens')->assertRedirect();
});

it('renders the tokens page with the create form and the seeded list', function () {
    asAdmin();

    AdminTokenProxy::modelClass()::create([
        'admin_id' => AdminProxy::modelClass()::first()->id,
        'name' => 'existing-token',
        'token_hash' => hash('sha256', 'whatever-plain'),
        'last_four' => 'lain',
        'scopes' => ['read'],
    ]);

    $html = $this->get('/admin/automation/tokens')
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('Automation API Tokens')
        ->and($html)->toContain('Generate token')
        ->and($html)->toContain('existing-token')
        ->and($html)->toContain('…lain')
        ->and($html)->toContain('read')
        // The scope checkboxes for all known scopes appear in the form
        ->and($html)->toContain('value="write:staged"')
        ->and($html)->toContain('value="write:approved"');
});

it('mints a token, redirects, and shows the plaintext exactly once', function () {
    asAdmin();

    $response = $this->post('/admin/automation/tokens', [
        'name' => 'n8n agent',
        'scopes' => ['read', 'write:staged'],
    ])->assertRedirect('/admin/automation/tokens');

    // Pull the flashed plaintext out of the session
    $plaintext = $response->getSession()->get('automation.created_token_plaintext');
    expect($plaintext)->toStartWith('bag_')->and(strlen($plaintext))->toBe(64);

    // The hash is what's in the DB — plaintext is not
    $row = AdminTokenProxy::modelClass()::query()->where('name', 'n8n agent')->first();
    expect($row)->not->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $plaintext))
        ->and($row->scopes)->toEqualCanonicalizing(['read', 'write:staged']);

    // Following the redirect shows the plaintext banner — and then session is cleared
    $html = $this->get('/admin/automation/tokens')->assertStatus(200)->getContent();
    expect($html)->toContain($plaintext)->toContain('will never be shown again');

    // A second GET no longer shows the plaintext
    $html2 = $this->get('/admin/automation/tokens')->assertStatus(200)->getContent();
    expect($html2)->not->toContain($plaintext);
});

it('rejects a token creation with no scopes selected', function () {
    asAdmin();

    $this->post('/admin/automation/tokens', [
        'name' => 'no-scope',
    ])->assertRedirect()->assertSessionHasErrors('scopes');

    expect(AdminTokenProxy::modelClass()::query()->where('name', 'no-scope')->exists())->toBeFalse();
});

it('rejects a token creation with an unknown scope', function () {
    asAdmin();

    $this->post('/admin/automation/tokens', [
        'name' => 'bad-scope',
        'scopes' => ['delete:everything'],
    ])->assertRedirect()->assertSessionHasErrors('scopes.0');

    expect(AdminTokenProxy::modelClass()::query()->where('name', 'bad-scope')->exists())->toBeFalse();
});

it('rejects a token creation with an expires_at in the past', function () {
    asAdmin();

    $this->post('/admin/automation/tokens', [
        'name' => 'expired',
        'scopes' => ['read'],
        'expires_at' => Carbon::now()->subDay()->format('Y-m-d\TH:i'),
    ])->assertRedirect()->assertSessionHasErrors('expires_at');
});

it('revokes a token via the admin endpoint', function () {
    $admin = asAdmin();

    $row = AdminTokenProxy::modelClass()::create([
        'admin_id' => $admin->id,
        'name' => 'to-revoke',
        'token_hash' => hash('sha256', 'plain-for-revoke'),
        'last_four' => 'voke',
        'scopes' => ['read'],
    ]);

    $this->post("/admin/automation/tokens/{$row->id}/revoke")->assertRedirect();

    expect($row->fresh()->revoked_at)->not->toBeNull();
});

it('flashes an error when revoking a token that does not exist', function () {
    asAdmin();

    $this->post('/admin/automation/tokens/9999999/revoke')
        ->assertRedirect()
        ->assertSessionHasErrors('token');
});

it('exercises the end-to-end loop: mint via UI, call API, revoke via UI, API now 401', function () {
    asAdmin();

    // 1) Mint via the admin UI
    $response = $this->post('/admin/automation/tokens', [
        'name' => 'e2e',
        'scopes' => ['read'],
    ])->assertRedirect();

    $plaintext = $response->getSession()->get('automation.created_token_plaintext');
    $row = AdminTokenProxy::modelClass()::query()->where('name', 'e2e')->first();

    // 2) Use it against the bearer-protected products endpoint
    $this->withHeader('Authorization', "Bearer {$plaintext}")
        ->getJson('/api/automation/v1/products?per_page=1')
        ->assertStatus(200);

    // 3) Revoke it via the admin UI
    $this->post("/admin/automation/tokens/{$row->id}/revoke")->assertRedirect();

    // 4) Same plaintext now rejected
    $this->withHeader('Authorization', "Bearer {$plaintext}")
        ->getJson('/api/automation/v1/products?per_page=1')
        ->assertStatus(401)
        ->assertJson(['error' => ['code' => 'invalid_token']]);
});
