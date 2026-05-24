<?php

use Webkul\User\Models\AdminProxy;
use Webkul\User\Models\RoleProxy;

/**
 * Verifies the ACL wiring for the Automation package.
 *
 * Bagisto's admin Bouncer middleware calls bouncer()->allow($key) for every
 * route whose name appears in acl()->getRoles(), so a role with permission_type
 * 'custom' and an empty permissions array MUST be rejected with 401 on each
 * registered Automation route.
 */
function makeCustomRoleAdmin(array $permissions = []): object
{
    $role = RoleProxy::modelClass()::create([
        'name' => 'automation-acl-test-'.uniqid(),
        'description' => 'test role',
        'permission_type' => 'custom',
        'permissions' => $permissions,
    ]);

    $admin = AdminProxy::modelClass()::create([
        'name' => 'ACL Test Admin',
        'email' => 'acl-'.uniqid().'@example.test',
        'password' => bcrypt('secret-1234'),
        'role_id' => $role->id,
        'status' => 1,
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

it('registers the expected automation ACL keys in the merged acl config', function () {
    $keys = collect(config('acl'))->pluck('key')->all();

    foreach ([
        'automation',
        'automation.pending-updates',
        'automation.pending-updates.approve',
        'automation.pending-updates.reject',
        'automation.tokens',
        'automation.tokens.create',
        'automation.tokens.revoke',
        'automation.webhooks',
        'automation.webhooks.create',
        'automation.webhooks.toggle',
        'automation.webhooks.delete',
        'automation.products-mappings',
    ] as $key) {
        expect($keys)->toContain($key);
    }
});

it('blocks an admin without automation.pending-updates from the pending-updates index', function () {
    makeCustomRoleAdmin(['dashboard']);

    $this->get('/admin/automation/pending-updates')->assertStatus(401);
});

it('lets an admin with automation.pending-updates view the pending-updates index', function () {
    makeCustomRoleAdmin(['dashboard', 'automation', 'automation.pending-updates']);

    $this->get('/admin/automation/pending-updates')->assertStatus(200);
});

it('blocks an admin without automation.tokens from the tokens page', function () {
    makeCustomRoleAdmin(['dashboard']);

    $this->get('/admin/automation/tokens')->assertStatus(401);
});

it('blocks an admin without automation.webhooks from the webhooks page', function () {
    makeCustomRoleAdmin(['dashboard']);

    $this->get('/admin/automation/webhooks')->assertStatus(401);
});
