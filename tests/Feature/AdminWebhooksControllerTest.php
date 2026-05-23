<?php

use Webkul\Automation\Models\AutomationWebhookProxy;
use Webkul\User\Models\AdminProxy;

function loginAsAdminForWebhooks(): object
{
    $admin = AdminProxy::modelClass()::first();
    test()->actingAs($admin, 'admin');

    return $admin;
}

it('redirects unauthenticated visitors away from the webhooks page', function () {
    $this->get('/admin/automation/webhooks')->assertRedirect();
});

it('renders the webhooks page with the create form', function () {
    loginAsAdminForWebhooks();

    $html = $this->get('/admin/automation/webhooks')
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('Outbound Webhooks')
        ->and($html)->toContain('Register webhook')
        ->and($html)->toContain('product.updated');
});

it('creates a webhook with a generated secret and flashes it once', function () {
    loginAsAdminForWebhooks();

    $response = $this->post('/admin/automation/webhooks', [
        'name' => 'n8n agent',
        'target_url' => 'https://n8n.example.test/hook',
        'event' => 'product.updated',
        'is_active' => '1',
    ])->assertRedirect('/admin/automation/webhooks');

    $secret = $response->getSession()->get('automation.created_webhook_secret');
    expect($secret)->not->toBeNull()
        ->and(strlen($secret))->toBe(64);   // hex of 32 bytes

    $row = AutomationWebhookProxy::modelClass()::query()->where('name', 'n8n agent')->first();
    expect($row)->not->toBeNull()
        ->and($row->target_url)->toBe('https://n8n.example.test/hook')
        ->and($row->event)->toBe('product.updated')
        ->and($row->is_active)->toBeTrue()
        ->and($row->secret)->toBe($secret);

    $html = $this->get('/admin/automation/webhooks')->assertStatus(200)->getContent();
    expect($html)->toContain($secret)->toContain('will never be shown again');

    // The next render must not include the secret again
    $html2 = $this->get('/admin/automation/webhooks')->assertStatus(200)->getContent();
    expect($html2)->not->toContain($secret);
});

it('rejects creating a webhook with an unknown event', function () {
    loginAsAdminForWebhooks();

    $this->post('/admin/automation/webhooks', [
        'name' => 'bad-event',
        'target_url' => 'https://example.test/hook',
        'event' => 'order.placed',
    ])->assertRedirect()->assertSessionHasErrors('event');

    expect(AutomationWebhookProxy::modelClass()::query()
        ->where('name', 'bad-event')->exists())->toBeFalse();
});

it('rejects creating a webhook with a non-URL target', function () {
    loginAsAdminForWebhooks();

    $this->post('/admin/automation/webhooks', [
        'name' => 'bad-url',
        'target_url' => 'not-a-url',
        'event' => 'product.updated',
    ])->assertRedirect()->assertSessionHasErrors('target_url');
});

it('toggles a webhook between active and inactive', function () {
    $admin = loginAsAdminForWebhooks();

    $row = AutomationWebhookProxy::modelClass()::create([
        'admin_id' => $admin->id,
        'name' => 'toggle-me',
        'target_url' => 'https://example.test/x',
        'event' => 'product.updated',
        'secret' => 'plain-toggle-secret',
        'is_active' => true,
    ]);

    $this->post("/admin/automation/webhooks/{$row->id}/toggle")->assertRedirect();
    expect($row->fresh()->is_active)->toBeFalse();

    $this->post("/admin/automation/webhooks/{$row->id}/toggle")->assertRedirect();
    expect($row->fresh()->is_active)->toBeTrue();
});

it('deletes a webhook', function () {
    $admin = loginAsAdminForWebhooks();

    $row = AutomationWebhookProxy::modelClass()::create([
        'admin_id' => $admin->id,
        'name' => 'delete-me',
        'target_url' => 'https://example.test/x',
        'event' => 'product.updated',
        'secret' => 'plain-delete-secret',
    ]);

    $this->delete("/admin/automation/webhooks/{$row->id}")->assertRedirect();

    expect(AutomationWebhookProxy::modelClass()::find($row->id))->toBeNull();
});

it('flashes errors for toggling and deleting nonexistent webhooks', function () {
    loginAsAdminForWebhooks();

    $this->post('/admin/automation/webhooks/9999999/toggle')
        ->assertRedirect()
        ->assertSessionHasErrors('webhook');

    $this->delete('/admin/automation/webhooks/9999999')
        ->assertRedirect()
        ->assertSessionHasErrors('webhook');
});
