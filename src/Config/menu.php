<?php

return [
    [
        'key'   => 'automation',
        'name'  => 'Automation',
        'route' => 'admin.automation.pending-updates.index',
        'sort'  => 8,
        'icon'  => 'icon-promotion',
    ], [
        'key'   => 'automation.pending-updates',
        'name'  => 'Pending Updates',
        'route' => 'admin.automation.pending-updates.index',
        'sort'  => 1,
        'icon'  => '',
    ], [
        'key'   => 'automation.tokens',
        'name'  => 'API Tokens',
        'route' => 'admin.automation.tokens.index',
        'sort'  => 2,
        'icon'  => '',
    ], [
        'key'   => 'automation.webhooks',
        'name'  => 'Webhooks',
        'route' => 'admin.automation.webhooks.index',
        'sort'  => 3,
        'icon'  => '',
    ],
];
