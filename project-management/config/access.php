<?php

/*
|--------------------------------------------------------------------------
| Permissions and system roles
|--------------------------------------------------------------------------
|
| The app's own permission catalog (it replaces platform/catalog.json, which
| the former central platform read). `php artisan access:sync` writes it to
| the permissions / roles tables; run it after every deploy. Keys are what
| routes and code check: Route::...->can('projects.view').
|
| Roles are assigned per organization membership (role_assignments). An
| organization owner holds every permission there.
|
*/

$projects = [
    'projects.view', 'projects.manage', 'projects.tasks.manage', 'projects.time.log',
    'projects.time.manage', 'projects.finance.view', 'projects.people.manage', 'projects.expenses.request', 'projects.changes.approve',
];

// Each step of spending (request, approve, pay) is held apart: whoever
// requested spending never approves or pays it (App\Actions\Spending).
$approvals = ['finance.budgets.approve', 'finance.expenses.approve', 'finance.payments.record'];

// The books (accounting): each area viewed and changed apart.
$accounting = ['finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post', 'finance.journals.approve', 'finance.payables.view', 'finance.payables.manage', 'finance.receivables.view', 'finance.receivables.manage', 'finance.banking.view', 'finance.banking.manage', 'finance.banking.reconcile', 'finance.tax.manage', 'finance.currencies.manage', 'finance.budgets.manage', 'finance.assets.manage', 'finance.reports.view', 'finance.periods.manage'];

return [

    'permissions' => [
        'finance.accounts.view' => ['name' => 'View the chart of accounts and the ledger', 'sensitivity' => 2],
        'finance.accounts.manage' => ['name' => 'Add and change accounts', 'sensitivity' => 3],
        'finance.journals.view' => ['name' => 'View journals', 'sensitivity' => 2],
        'finance.journals.post' => ['name' => 'Post and reverse manual journals', 'sensitivity' => 3],
        'finance.journals.approve' => ['name' => 'Approve other people\'s manual and recurring journals, and set when approval is needed', 'sensitivity' => 3],
        'finance.payables.view' => ['name' => 'View suppliers, bills and supplier payments', 'sensitivity' => 2],
        'finance.payables.manage' => ['name' => 'Record bills and pay suppliers', 'sensitivity' => 3],
        'finance.receivables.view' => ['name' => 'View invoices and customer payments', 'sensitivity' => 2],
        'finance.receivables.manage' => ['name' => 'Raise invoices, issue credit notes and record customer payments', 'sensitivity' => 3],
        'finance.banking.view' => ['name' => 'View bank and cash accounts', 'sensitivity' => 2],
        'finance.banking.manage' => ['name' => 'Record money in, money out and transfers', 'sensitivity' => 3],
        'finance.banking.reconcile' => ['name' => 'Import bank statements, match and reconcile them', 'sensitivity' => 3],
        'finance.tax.manage' => ['name' => 'Manage tax codes, file and settle tax returns', 'sensitivity' => 3],
        'finance.currencies.manage' => ['name' => 'Set exchange rates and revalue foreign-currency balances', 'sensitivity' => 3],
        'finance.budgets.manage' => ['name' => 'Add, change and delete budgets', 'sensitivity' => 2],
        'finance.assets.manage' => ['name' => 'Register and dispose of fixed assets, run depreciation', 'sensitivity' => 3],
        'finance.reports.view' => ['name' => 'View financial statements', 'sensitivity' => 2],
        'finance.periods.manage' => ['name' => 'Lock months, close years and enter opening balances', 'sensitivity' => 3],
        'projects.view' => ['name' => 'View projects, tasks, timelines, risks and the team', 'sensitivity' => 1],
        'projects.manage' => ['name' => 'Set up and change projects, their team, milestones, risks and budget', 'sensitivity' => 2],
        'projects.tasks.manage' => ['name' => 'Add and change tasks, move them on the board, comment', 'sensitivity' => 1],
        'projects.time.log' => ['name' => 'Log your own time', 'sensitivity' => 1],
        'projects.time.manage' => ['name' => "Log and remove anyone's time", 'sensitivity' => 2],
        'projects.finance.view' => ['name' => 'See budgets, costs and hourly rates', 'sensitivity' => 2],
        'projects.people.manage' => ['name' => 'Add people and set what their time costs', 'sensitivity' => 2],
        'projects.expenses.request' => ['name' => 'Request spending on projects they work on, and attach receipts', 'sensitivity' => 1],
        'projects.changes.approve' => ['name' => 'Approve or reject requests to change a project, except their own', 'sensitivity' => 2],
        'work.structure.manage' => ['name' => 'Organize work: workspaces, spaces, folders and lists', 'sensitivity' => 1],
        'finance.pettycash.view' => ['name' => 'See petty cash boxes, their payments and receipts', 'sensitivity' => 2],
        'finance.pettycash.record' => ['name' => 'Hold a petty cash box, and pay from one they hold', 'sensitivity' => 2],
        'finance.pettycash.manage' => ['name' => 'Set up, count and close petty cash boxes, and change who holds them', 'sensitivity' => 3],
        'finance.pettycash.approve' => ['name' => 'Check receipts and approve petty cash top-ups, except their own', 'sensitivity' => 3],
        'finance.advances.request' => ['name' => 'Ask for a staff advance, and account for it', 'sensitivity' => 1],
        'finance.advances.view' => ['name' => "See everyone's staff advances and how late they are", 'sensitivity' => 2],
        'finance.advances.approve' => ['name' => 'Approve advances, their receipts and write-offs, except their own', 'sensitivity' => 3],
        'finance.advances.disburse' => ['name' => 'Pay out advances, record cash returned, and ask for write-offs', 'sensitivity' => 3],
        'finance.commitments.view' => ['name' => 'See all spending approved and not yet paid, across projects', 'sensitivity' => 2],
        'finance.budgets.approve' => ['name' => 'Approve or return submitted budget versions', 'sensitivity' => 3],
        'finance.expenses.approve' => ['name' => 'Approve or reject spending requests against the budget and grants', 'sensitivity' => 3],
        'finance.payments.record' => ['name' => 'Record payments of approved spending, and void payments', 'sensitivity' => 3],
        'grants.view' => ['name' => 'See funders and grants: amounts, allocations, payments and reports', 'sensitivity' => 2],
        'grants.manage' => ['name' => 'Record funders and grants: awards, amendments, allocations, payments, reports, closeout', 'sensitivity' => 3],
        'procurement.view' => ['name' => "See everyone's purchase requests, quotes and orders", 'sensitivity' => 2],
        'procurement.request' => ['name' => 'Ask to buy something for a project, and follow their own requests', 'sensitivity' => 1],
        'procurement.approve' => ['name' => "Approve or reject other people's purchase requests", 'sensitivity' => 2],
        'procurement.manage' => ['name' => 'Compare quotes, raise purchase orders, receive goods and record invoices', 'sensitivity' => 2],
        'documents.view' => ['name' => 'See the organization\'s documents, other than confidential or archived ones', 'sensitivity' => 1],
        'documents.manage' => ['name' => 'Add, change and archive documents, and see confidential ones', 'sensitivity' => 2],
        'organization.manage' => ['name' => 'Manage the organization: settings, members, invitations and roles', 'sensitivity' => 3],
        'organization.audit.view' => ['name' => "See the organization's audit log", 'sensitivity' => 2],
        'marketplace.publish' => ['name' => 'Edit and publish the public profile and the projects listed as seeking funding', 'sensitivity' => 2],
    ],

    'roles' => [
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Accountant',
            'permissions' => ['finance.commitments.view', 'finance.advances.request', 'finance.advances.view', 'finance.advances.approve', 'finance.advances.disburse', 'finance.pettycash.view', 'finance.pettycash.manage', 'finance.pettycash.approve', 'documents.view', 'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post', 'finance.journals.approve', 'finance.payables.view', 'finance.payables.manage', 'finance.receivables.view', 'finance.receivables.manage', 'finance.banking.view', 'finance.banking.manage', 'finance.banking.reconcile', 'finance.reports.view', 'finance.periods.manage', 'finance.tax.manage', 'finance.currencies.manage', 'finance.budgets.manage', 'finance.assets.manage'],
        ],
        'bookkeeper' => [
            'name' => 'Bookkeeper',
            'description' => 'Bookkeeper',
            'permissions' => ['finance.commitments.view', 'finance.advances.request', 'finance.advances.view', 'finance.advances.disburse', 'finance.pettycash.view', 'finance.pettycash.record', 'documents.view', 'finance.accounts.view', 'finance.journals.view', 'finance.payables.view', 'finance.payables.manage', 'finance.receivables.view', 'finance.receivables.manage', 'finance.banking.view', 'finance.banking.manage', 'finance.banking.reconcile'],
        ],
        'auditor' => [
            'name' => 'Auditor (read only)',
            'description' => 'Auditor (read only)',
            'permissions' => ['finance.commitments.view', 'finance.advances.view', 'finance.pettycash.view', 'documents.view', 'finance.accounts.view', 'finance.journals.view', 'finance.payables.view', 'finance.receivables.view', 'finance.banking.view', 'finance.reports.view'],
        ],
        'admin' => [
            'name' => 'Organization Administrator',
            'description' => 'Runs the organization: members, roles and settings, and every project.',
            'permissions' => ['finance.commitments.view', 'finance.advances.request', 'finance.advances.view', 'finance.advances.approve', 'finance.advances.disburse', 'finance.pettycash.view', 'finance.pettycash.manage', 'finance.pettycash.approve', 'documents.view', 'documents.manage', ...$accounting, ...$projects, ...$approvals, 'work.structure.manage', 'grants.view', 'grants.manage', 'procurement.view', 'procurement.request', 'procurement.approve', 'procurement.manage', 'organization.manage', 'organization.audit.view', 'marketplace.publish'],
        ],
        'manager' => [
            'name' => 'Project Manager',
            'description' => 'Everything on projects; sees the grants funding them.',
            'permissions' => ['finance.commitments.view', 'finance.advances.request', 'documents.view', ...$projects, 'work.structure.manage', 'grants.view', 'procurement.view', 'procurement.request', 'procurement.approve'],
        ],
        'member' => [
            'name' => 'Team Member',
            'description' => 'Views projects, works tasks, logs their own time, requests spending.',
            'permissions' => ['finance.advances.request', 'documents.view', 'projects.view', 'projects.tasks.manage', 'projects.time.log', 'projects.expenses.request', 'procurement.request'],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Views projects only.',
            'permissions' => ['documents.view', 'projects.view'],
        ],
        'controller' => [
            'name' => 'Finance Controller',
            'description' => "Views projects, budgets and costs; approves budgets and spending; records payments; sets people's costs; manages funders and grants.",
            'permissions' => ['finance.commitments.view', 'documents.view', 'documents.manage', 'projects.view', 'projects.finance.view', 'projects.people.manage', 'projects.changes.approve', 'finance.advances.request', 'finance.advances.view', 'finance.advances.approve', 'finance.pettycash.view', 'finance.pettycash.approve', ...$approvals, 'grants.view', 'grants.manage', 'procurement.view', 'procurement.approve'],
        ],
        'finance_officer' => [
            'name' => 'Finance Officer',
            'description' => 'Views projects, budgets and grants; records payments of approved spending.',
            'permissions' => ['finance.commitments.view', 'finance.advances.request', 'finance.advances.view', 'finance.advances.disburse', 'finance.pettycash.view', 'finance.pettycash.record', 'documents.view', 'projects.view', 'projects.finance.view', 'finance.payments.record', 'grants.view', 'procurement.view'],
        ],
        'cash_custodian' => [
            'name' => 'Petty Cash Custodian',
            'description' => 'Holds a petty cash box: pays from it with receipts and asks for top-ups.',
            'permissions' => ['finance.advances.request', 'finance.pettycash.view', 'finance.pettycash.record'],
        ],
        'procurement_officer' => [
            'name' => 'Procurement Officer',
            'description' => 'Compares quotes, raises purchase orders, receives goods and records invoices. Finance still approves and pays.',
            'permissions' => ['finance.advances.request', 'documents.view', 'projects.view', 'procurement.view', 'procurement.request', 'procurement.manage'],
        ],
    ],

    // Who may create a new organization: "anyone" (any verified user, who
    // becomes its owner) or "platform_admins".
    'organization_creation' => env('ORGANIZATION_CREATION', 'anyone'),

    // How long an invitation (and its activation link) stays valid.
    'invitation_days' => (int) env('INVITATION_DAYS', 7),

    // Passwords for the accounts the Asaas seeders create. Never in the code;
    // they can be taken out of .env again once the seeders have run.
    'demo' => [
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'team_password' => env('SEED_TEAM_PASSWORD'),
    ],

];
