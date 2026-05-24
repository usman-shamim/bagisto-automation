<?php

/*
|--------------------------------------------------------------------------
| Automation ACL
|--------------------------------------------------------------------------
|
| ACL nodes for the Automation package. Bagisto's admin middleware
| (Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized) looks up the
| current route name in acl()->getRoles() and aborts 401 if the admin's
| role lacks the matching permission key — so simply registering routes
| here is enough to gate them. Sibling menu entries in Config/menu.php
| use the same keys so they hide when an admin lacks access.
|
*/

return [
    [
        'key' => 'automation',
        'name' => 'Automation',
        'route' => 'admin.automation.pending-updates.index',
        'sort' => 8,
    ], [
        'key' => 'automation.pending-updates',
        'name' => 'Pending Updates',
        'route' => 'admin.automation.pending-updates.index',
        'sort' => 1,
    ], [
        'key' => 'automation.pending-updates.approve',
        'name' => 'Approve',
        'route' => 'admin.automation.pending-updates.approve',
        'sort' => 1,
    ], [
        'key' => 'automation.pending-updates.reject',
        'name' => 'Reject',
        'route' => 'admin.automation.pending-updates.reject',
        'sort' => 2,
    ], [
        'key' => 'automation.tokens',
        'name' => 'API Tokens',
        'route' => 'admin.automation.tokens.index',
        'sort' => 2,
    ], [
        'key' => 'automation.tokens.create',
        'name' => 'Create',
        'route' => 'admin.automation.tokens.store',
        'sort' => 1,
    ], [
        'key' => 'automation.tokens.revoke',
        'name' => 'Revoke',
        'route' => 'admin.automation.tokens.revoke',
        'sort' => 2,
    ], [
        'key' => 'automation.webhooks',
        'name' => 'Webhooks',
        'route' => 'admin.automation.webhooks.index',
        'sort' => 3,
    ], [
        'key' => 'automation.webhooks.create',
        'name' => 'Create',
        'route' => 'admin.automation.webhooks.store',
        'sort' => 1,
    ], [
        'key' => 'automation.webhooks.toggle',
        'name' => 'Toggle',
        'route' => 'admin.automation.webhooks.toggle',
        'sort' => 2,
    ], [
        'key' => 'automation.webhooks.delete',
        'name' => 'Delete',
        'route' => 'admin.automation.webhooks.destroy',
        'sort' => 3,
    ], [
        'key' => 'automation.products-mappings',
        'name' => 'Competitor Mappings',
        'route' => [
            'admin.automation.products.mappings.store',
            'admin.automation.products.mappings.destroy',
        ],
        'sort' => 4,
    ],
];
