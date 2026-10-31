<?php

/*
| CRM access-control catalogue.
| Modules and actions listed here are created by PermissionSeeder (safe to
| re-run). Role defaults are applied only when a permission is first created,
| so changes the admin makes in Settings are never overwritten.
*/
return [
    'roles' => ['admin', 'employee', 'client'],

    'statuses' => ['pending_onboarding', 'pending_approval', 'active', 'inactive'],

    'actions' => ['view', 'create', 'edit', 'delete', 'export', 'approve'],

    // key => [name, client_allowed]
    'modules' => [
        'dashboard' => ['Dashboard', true],
        'leads' => ['Leads', false],
        'clients' => ['Clients', false],
        'employees' => ['Employees', false],
        'leave' => ['Leave Management', false],
        'campaigns' => ['Campaigns', true],
        'invoices' => ['Invoices & Payments', true],
        'tickets' => ['Tickets', true],
        'expenses' => ['Expenses', false],
        'notifications' => ['Notifications', false],
        'settings' => ['Settings', false],
    ],

    // Admins always have every permission; these are the starting points for the other roles.
    'role_defaults' => [
        'employee' => [
            'dashboard.view',
            'employees.view',   // own profile, payslips
            'leave.view', 'leave.create',
            'notifications.view',
        ],
        'client' => [
            'dashboard.view',
            'campaigns.view',
            'invoices.view',
            'tickets.view', 'tickets.create',
        ],
    ],
];
