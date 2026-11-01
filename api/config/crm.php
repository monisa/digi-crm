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
        // Company-wide figures on the dashboard: revenue, expenses, all leads, tickets, leave.
        'overview' => ['Business Overview', false],
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

    // Days of the week that are always off (0 = Sunday … 6 = Saturday). Leave
    // days skip these and public holidays.
    'weekly_offs' => [0],

    // Back-dated leave (e.g. sick leave after returning) is allowed this many days back.
    'leave_backdate_days' => 30,

    // Company details printed on payslips (no bank details: those stay out of the repo).
    'company' => [
        'name' => env('COMPANY_NAME', 'DIGIFOX PRO SOLUTIONS LLP'),
        'address' => env('COMPANY_ADDRESS', 'Kakkanad, Kochi, Kerala'),
        'email' => env('COMPANY_EMAIL', 'info@digifoxprosolutions.com'),
    ],

    // Lead pipeline stages (keys are fixed: the dashboard funnel uses them; labels can change).
    'lead_statuses' => [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Interested',
        'proposal' => 'Proposal sent',
        'won' => 'Won',
        'lost' => 'Not interested',
    ],

    // Default dropdown choices for leads; the admin can change them in the app.
    'lead_options' => [
        'call_statuses' => ['Not called', 'Connected', 'Not answering', 'Busy', 'Switched off', 'Call back later', 'Wrong number'],
        'packages' => ['Starter', 'Growth', 'Premium', 'Custom'],
        'followup_statuses' => ['Follow-up scheduled', 'Follow-up done', 'Waiting for client', 'No follow-up needed'],
        'lost_reasons' => ['Budget too low', 'Chose another agency', 'Not reachable', 'Not interested now', 'Wrong number / spam'],
        'auto_assign' => false,     // share new Meta leads round-robin…
        'assign_pool' => [],        // …among these user IDs
    ],

    // A new lead counts as "uncontacted" once it has waited this long.
    'lead_sla_minutes' => (int) env('CRM_LEAD_SLA_MINUTES', 30),

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
