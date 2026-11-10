import {
    BookOpenText,
    Building2,
    ClipboardCheck,
    FileCheck2,
    FileText,
    FolderKanban,
    HandCoins,
    Handshake,
    KeyRound,
    Landmark,
    MessagesSquare,
    ReceiptText,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Public-site copy and navigation. "available" is what the product does
 * today; "planned" is not described as live. When the product changes, this
 * file changes with it.
 */
export type Availability = 'available' | 'planned';

export type Card = {
    title: string;
    text: string;
    status: Availability;
    icon: LucideIcon;
};

export type Point = {
    text: string;
    status: Availability;
};

export type NavLink = {
    href: string;
    label: string;
};

export const NAV: NavLink[] = [
    { href: '/platform', label: 'Platform' },
    { href: '/features', label: 'Features' },
    { href: '/solutions', label: 'Who it is for' },
    { href: '/about', label: 'About' },
];

export const FOOTER: { title: string; links: NavLink[] }[] = [
    {
        title: 'Platform',
        links: [
            { href: '/features#donor-workspace', label: 'Donor workspace' },
            { href: '/features#grants', label: 'Grants' },
            { href: '/features#projects', label: 'Projects and tasks' },
            { href: '/features#budgets', label: 'Budgets and spending' },
            { href: '/features#procurement', label: 'Procurement' },
            { href: '/features#accounting', label: 'Accounting' },
            { href: '/features#planned', label: 'Planned' },
            { href: '/integrations', label: 'Planned connections' },
        ],
    },
    {
        title: 'Who it is for',
        links: [
            { href: '/solutions#donors', label: 'Donors' },
            {
                href: '/solutions#implementers',
                label: 'Beneficiaries and implementers',
            },
            { href: '/solutions#finance', label: 'Finance teams' },
            { href: '/solutions#projects', label: 'Project teams' },
        ],
    },
    {
        title: 'Company',
        links: [
            { href: '/about', label: 'About' },
            { href: '/contact', label: 'Contact' },
            { href: '/request-demo', label: 'Request a demo' },
        ],
    },
];

export function withProduct(text: string, name: string): string {
    return text.replaceAll('{name}', name);
}

/** The two ways in: the same product from either side of a grant. */
export const WORKSPACES: {
    id: string;
    icon: LucideIcon;
    eyebrow: string;
    title: string;
    text: string;
    points: string[];
    how: string;
}[] = [
    {
        id: 'donors',
        icon: Landmark,
        eyebrow: 'Donor workspace',
        title: 'For those who fund the work',
        text: 'One dashboard across every organization you fund, showing what they share and what is waiting for you.',
        points: [
            'Invite beneficiaries and implementing partners',
            'Follow grants, payments and funded projects',
            'Accept reports or ask for a revision',
            'Write to the organization on each grant',
        ],
        how: 'Any organization can turn this on.',
    },
    {
        id: 'implementers',
        icon: Building2,
        eyebrow: 'Organization workspace',
        title: 'For running your organization',
        text: 'The whole running of the organization: projects, budgets, spending, procurement, grants and the books.',
        points: [
            'Plan projects and run the work',
            'Approve spending, with duties kept apart',
            'Buy through request, quotes and order',
            'Keep double-entry books in the same place',
        ],
        how: 'Set it up yourself. No donor needed.',
    },
];

/** What a grant goes through, between the two workspaces. */
export const GRANT_JOURNEY: { who: string; title: string; text: string }[] = [
    {
        who: 'Donor',
        title: 'Invites the organization',
        text: 'By name and email, as a beneficiary or an implementer. Nothing connects until its administrator accepts.',
    },
    {
        who: 'Organization',
        title: 'Accepts and records the grant',
        text: 'Awards, numbered amendments, allocations to projects and a payment schedule, all in its own workspace.',
    },
    {
        who: 'Organization',
        title: 'Chooses what to share',
        text: 'Grant by grant: budget, spending, project progress and tasks. Each one stays off until it is turned on.',
    },
    {
        who: 'Organization',
        title: 'Delivers and spends',
        text: 'Tasks move, purchases are approved, and each paid expense posts a journal to the books.',
    },
    {
        who: 'Organization',
        title: 'Submits its reports',
        text: 'Each report has a due date and documents, and the donor sees them once it is submitted.',
    },
    {
        who: 'Donor',
        title: 'Accepts or asks for changes',
        text: 'A revision request sends the report back as owed. A grant closes only when no report is owed.',
    },
];

/** What a donor can be given, one switch per grant. */
export const SHARE_SWITCHES: {
    key: 'budget' | 'spending' | 'progress' | 'tasks';
    label: string;
    hint: string;
    sees: string[];
}[] = [
    {
        key: 'budget',
        label: 'Approved budget',
        hint: 'By category, once a budget is approved.',
        sees: ['Personnel 40,000', 'Equipment 35,000', 'Travel 25,000'],
    },
    {
        key: 'spending',
        label: 'Spending on this grant',
        hint: 'Paid lines and the committed total.',
        sees: ['12 Oct, Equipment, Solar pump 1,200', 'Committed 3,800'],
    },
    {
        key: 'progress',
        label: 'Project progress',
        hint: 'Status, percent done and milestones.',
        sees: ['Active, 62% done', 'Next milestone: Handover, 1 Mar'],
    },
    {
        key: 'tasks',
        label: 'Project tasks',
        hint: 'Number, title, status and dates.',
        sees: [
            'WASH-12 Procure pumps, In progress',
            'WASH-14 Train committee, To do',
        ],
    },
];

/** Stays with the organization whatever is switched on. */
export const NEVER_SHARED = [
    'Suppliers and receipts',
    'who requested or approved',
    'staff and the cost of people',
    'risks and internal notes',
    'who a task is assigned to',
];

export const PROJECT_POINTS: Point[] = [
    { text: 'Spaces, folders and lists', status: 'available' },
    { text: 'Tasks on a board, a list and a timeline', status: 'available' },
    { text: 'Subtasks, checklists and dependencies', status: 'available' },
    { text: 'Milestones, and a register of risks', status: 'available' },
    {
        text: 'Team allocation that shows who is overbooked',
        status: 'available',
    },
    {
        text: 'Time logged at cost, and a weekly timesheet',
        status: 'available',
    },
];

/** The money, in order: each step a different person's. */
export const MONEY_CARDS: Card[] = [
    {
        icon: Wallet,
        title: 'Budgets in versions',
        text: 'Drafted by category and approved by someone else. An approved version never changes: a revision is a new one.',
        status: 'available',
    },
    {
        icon: ClipboardCheck,
        title: 'Spending approvals',
        text: 'Checked against what is left of the budget and the grant. Bands you set require up to three approvers.',
        status: 'available',
    },
    {
        icon: ShoppingCart,
        title: 'Procurement',
        text: 'A purchase request, its approval, quotes compared, an order raised, goods received and the invoice recorded.',
        status: 'available',
    },
    {
        icon: BookOpenText,
        title: 'The books',
        text: 'Paying an expense or receiving a grant payment posts its journal in the same step, so the two cannot disagree.',
        status: 'available',
    },
];

export const LEDGER_GROUPS: { title: string; items: string[] }[] = [
    {
        title: 'Ledger',
        items: [
            'Chart of accounts',
            'Journals, with approval',
            'Recurring journals',
            'Periods and year end',
        ],
    },
    {
        title: 'Owed and owing',
        items: [
            'Invoices and customer payments',
            'Bills and supplier payments',
            'Aged balances',
        ],
    },
    {
        title: 'Cash and assets',
        items: [
            'Bank accounts and rules',
            'Statement reconciliation',
            'Fixed assets and depreciation',
            'Currencies and tax returns',
        ],
    },
    {
        title: 'Reports',
        items: [
            'Trial balance, general ledger',
            'Profit and loss, by dimension',
            'Balance sheet, cash flow',
            'Budget against actual',
        ],
    },
];

export const SECURITY: Card[] = [
    {
        icon: Building2,
        title: 'Organizations kept apart',
        text: 'Each organization’s records are closed to every other. A donor reads a beneficiary only through what its grants share.',
        status: 'available',
    },
    {
        icon: Users,
        title: 'Roles and permissions',
        text: 'Roles decide what a member sees and changes, and the server checks every action again.',
        status: 'available',
    },
    {
        icon: ShieldCheck,
        title: 'Duties held apart',
        text: 'Whoever asks for money never approves or pays it, and nobody approves the same request twice.',
        status: 'available',
    },
    {
        icon: ScrollText,
        title: 'An audit log',
        text: 'Approvals, role changes, sign-ins and settings are recorded. Spending is voided, not deleted.',
        status: 'available',
    },
    {
        icon: KeyRound,
        title: 'Two-step sign-in',
        text: 'A code from an authenticator app, one-time recovery codes, and signing out your other devices.',
        status: 'available',
    },
    {
        icon: FileText,
        title: 'Private documents',
        text: 'Receipts, quotes and report documents are stored privately and downloaded only through the app, by those allowed.',
        status: 'available',
    },
];

/** What is not built yet: said once, plainly. */
export const PLANNED: { title: string; text: string }[] = [
    {
        title: 'Monitoring and evaluation',
        text: 'Results frameworks, indicators with baselines and targets, and evaluations.',
    },
    {
        title: 'Records of people served',
        text: 'Registration, services delivered, feedback and complaints, with access by role.',
    },
    {
        title: 'Field data',
        text: 'Submissions from KoboToolbox and ODK, read into the project.',
    },
    {
        title: 'Programmes and templates',
        text: 'Grouping projects into programmes, and starting one from a template.',
    },
    {
        title: 'Tenders and contracts',
        text: 'Procurement plans, tender rounds and contract management.',
    },
    {
        title: 'An assistant',
        text: 'Summaries and drafts from records the person may already see. It would never approve anything.',
    },
];

export const FEATURE_CATEGORIES: {
    id: string;
    title: string;
    text: string;
    points: Point[];
}[] = [
    {
        id: 'donor-workspace',
        title: 'Donor workspace',
        text: 'A donor organization’s own sign-in, team and view across everyone it funds.',
        points: [
            { text: 'Dashboard across beneficiaries', status: 'available' },
            {
                text: 'Inviting beneficiaries and implementers',
                status: 'available',
            },
            {
                text: 'Shared grants, payments and funded projects',
                status: 'available',
            },
            { text: 'All reports, with their documents', status: 'available' },
            {
                text: 'Accepting a report or asking for a revision',
                status: 'available',
            },
            {
                text: 'Project status and tasks, when shared',
                status: 'available',
            },
            { text: 'A conversation on each grant', status: 'available' },
            {
                text: 'The donor’s own members, roles and audit log',
                status: 'available',
            },
        ],
    },
    {
        id: 'grants',
        title: 'Grants',
        text: 'The grant file, kept by the organization that holds the award.',
        points: [
            { text: 'Funder directory', status: 'available' },
            {
                text: 'Opportunity, application, award and close',
                status: 'available',
            },
            { text: 'Changes by numbered amendment only', status: 'available' },
            {
                text: 'Allocations across projects and currencies',
                status: 'available',
            },
            { text: 'Payment schedules', status: 'available' },
            { text: 'Reports owed, with documents', status: 'available' },
            { text: 'Sharing switches for each grant', status: 'available' },
            {
                text: 'Portal access for a funder’s contacts',
                status: 'available',
            },
        ],
    },
    {
        id: 'projects',
        title: 'Projects and tasks',
        text: 'Planning the work and seeing how it stands.',
        points: [
            {
                text: 'Projects with a code, manager, dates and status',
                status: 'available',
            },
            { text: 'Portfolio with a health reading', status: 'available' },
            { text: 'Spaces, folders and lists', status: 'available' },
            { text: 'Board, list and timeline', status: 'available' },
            {
                text: 'Subtasks, checklists and dependencies',
                status: 'available',
            },
            { text: 'Statuses for each list', status: 'available' },
            { text: 'Comments, mentions and attachments', status: 'available' },
            { text: 'Milestones and a risk register', status: 'available' },
            { text: 'Team allocation and overbooking', status: 'available' },
            { text: 'Time logs and a weekly timesheet', status: 'available' },
            { text: 'Calendar view', status: 'planned' },
            { text: 'Programmes and project templates', status: 'planned' },
        ],
    },
    {
        id: 'budgets',
        title: 'Budgets and spending',
        text: 'From the approved budget to the payment, with a different person at each step.',
        points: [
            { text: 'Budget versions by cost category', status: 'available' },
            {
                text: 'Approval by someone other than the drafter',
                status: 'available',
            },
            {
                text: 'Budget, committed, spent and available',
                status: 'available',
            },
            {
                text: 'Requests checked against budget and grant',
                status: 'available',
            },
            {
                text: 'Approval bands of one to three people',
                status: 'available',
            },
            { text: 'Receipts and quotes on the request', status: 'available' },
            {
                text: 'One place for everything awaiting approval',
                status: 'available',
            },
            { text: 'Funding by grant on each project', status: 'available' },
        ],
    },
    {
        id: 'procurement',
        title: 'Procurement',
        text: 'Buying, with each step held apart by permission.',
        points: [
            { text: 'Purchase requests with line items', status: 'available' },
            { text: 'Approval before going to market', status: 'available' },
            { text: 'Quotes compared and one chosen', status: 'available' },
            { text: 'Purchase orders', status: 'available' },
            { text: 'Goods received against the order', status: 'available' },
            { text: 'Supplier invoice, then spending', status: 'available' },
            { text: 'Procurement plans', status: 'planned' },
            { text: 'Tenders and contracts', status: 'planned' },
        ],
    },
    {
        id: 'accounting',
        title: 'Accounting',
        text: 'Double-entry books, connected to the projects and grants they record.',
        points: [
            { text: 'Chart of accounts and journals', status: 'available' },
            {
                text: 'Journal approval and recurring journals',
                status: 'available',
            },
            {
                text: 'Project spending posted automatically',
                status: 'available',
            },
            { text: 'Invoices, bills and payments', status: 'available' },
            { text: 'Bank rules and reconciliation', status: 'available' },
            { text: 'Fixed assets and depreciation', status: 'available' },
            { text: 'Currencies and revaluation', status: 'available' },
            { text: 'Tax codes and returns', status: 'available' },
            { text: 'Accounting periods and year end', status: 'available' },
            { text: 'Dimensions and budgets', status: 'available' },
            {
                text: 'Trial balance, P&L, balance sheet, cash flow',
                status: 'available',
            },
            {
                text: 'Aged balances, ratios, budget against actual',
                status: 'available',
            },
        ],
    },
    {
        id: 'administration',
        title: 'Administration and security',
        text: 'Who can do what, and the record of what they did.',
        points: [
            {
                text: 'Several organizations on one account',
                status: 'available',
            },
            { text: 'Invitations by one-time link', status: 'available' },
            { text: 'Roles and permissions', status: 'available' },
            { text: 'Audit log', status: 'available' },
            {
                text: 'Two-step sign-in and recovery codes',
                status: 'available',
            },
            {
                text: 'Signed-in devices, signed out remotely',
                status: 'available',
            },
            { text: 'Search and notifications', status: 'available' },
        ],
    },
    {
        id: 'planned',
        title: 'Planned',
        text: 'None of this is in the product yet.',
        points: [
            { text: 'Results frameworks and indicators', status: 'planned' },
            { text: 'Evaluations and evidence', status: 'planned' },
            { text: 'Records of people served', status: 'planned' },
            { text: 'Feedback and complaints', status: 'planned' },
            { text: 'KoboToolbox and ODK', status: 'planned' },
            { text: 'An assistant', status: 'planned' },
        ],
    },
];

export const PLATFORM_AREAS: Card[] = [
    {
        icon: Handshake,
        title: 'Partnerships',
        text: 'An organization that funds others invites them. Each keeps its own workspace, members and records.',
        status: 'available',
    },
    {
        icon: HandCoins,
        title: 'Grants',
        text: 'Award, amendments, allocations, payments and reports, with sharing chosen for each grant.',
        status: 'available',
    },
    {
        icon: FolderKanban,
        title: 'Projects',
        text: 'Tasks, milestones, risks, the team and time, with progress and a health reading on each project.',
        status: 'available',
    },
    {
        icon: Wallet,
        title: 'Budgets and spending',
        text: 'Versioned budgets and approved spending, checked against what is left of the budget and the grant.',
        status: 'available',
    },
    {
        icon: ShoppingCart,
        title: 'Procurement',
        text: 'Request, approval, quotes, order, goods received and the supplier’s invoice.',
        status: 'available',
    },
    {
        icon: BookOpenText,
        title: 'Accounting',
        text: 'Ledger, receivables, payables, banking, assets, tax and financial statements.',
        status: 'available',
    },
    {
        icon: FileCheck2,
        title: 'Reporting to donors',
        text: 'Reports fall due, are submitted with documents, and are accepted or sent back for revision.',
        status: 'available',
    },
    {
        icon: MessagesSquare,
        title: 'Conversation',
        text: 'A thread on each grant between the organization and its donor, with notifications on both sides.',
        status: 'available',
    },
    {
        icon: ReceiptText,
        title: 'A record of it all',
        text: 'An audit log, and spending that is voided rather than deleted, so the history stays.',
        status: 'available',
    },
];

export const SOLUTIONS: {
    id: string;
    icon: LucideIcon;
    preview: 'donor' | 'portfolio' | 'finance' | 'views';
    title: string;
    text: string;
    benefits: string[];
    note?: string;
}[] = [
    {
        id: 'donors',
        icon: Landmark,
        preview: 'donor',
        title: 'For donors',
        text: 'Turn on funding, invite the organizations you fund, and follow every grant they share from one dashboard.',
        benefits: [
            'Totals kept per currency, never added across them',
            'Reports to decide and reports still owed, across beneficiaries',
            'Payments expected, and overdue items marked',
            'Project status and tasks, when the grant shares them',
        ],
        note: 'You see what each organization chooses to share with you. That is the arrangement, on purpose.',
    },
    {
        id: 'implementers',
        icon: Building2,
        preview: 'portfolio',
        title: 'For organizations running their own work',
        text: 'A complete workspace to run your organization, with or without donors. If you have them, you decide, grant by grant, what each one sees.',
        benefits: [
            'Your own members, roles and records',
            'Projects, budgets, procurement and books together',
            'Several donors, each seeing only their own grants',
            'Reports and the conversation with the donor on the grant',
        ],
        note: 'Set up your organization yourself. A donor’s invitation also works, and you can connect later.',
    },
    {
        id: 'finance',
        icon: Wallet,
        preview: 'finance',
        title: 'For finance teams',
        text: 'Budgets someone else approves, spending checked before it is committed, and double-entry books that post as payments are made.',
        benefits: [
            'Committed kept apart from spent, by category',
            'Spending held within the grant’s allocation to the project',
            'Reconciliation, tax returns and fixed assets',
            'Statements, and budget against actual',
        ],
    },
    {
        id: 'projects',
        icon: FolderKanban,
        preview: 'views',
        title: 'For project teams',
        text: 'See the work on a board, a list or a timeline, know what is due, and raise a purchase or expense without leaving the project.',
        benefits: [
            'Tasks numbered to their project',
            'A “My work” page and a weekly timesheet',
            'Allocation that shows who is overbooked',
            'A health reading from schedule and spend',
        ],
        note: 'It runs in a browser and needs a connection. Offline field collection is not built.',
    },
];

export const INTEGRATION_GROUPS: { title: string; items: string[] }[] = [
    {
        title: 'Field data',
        items: ['KoboToolbox', 'ODK Central'],
    },
    {
        title: 'Documents and files',
        items: ['Microsoft 365', 'Google Workspace'],
    },
    {
        title: 'Messages',
        items: ['Microsoft Teams', 'Slack'],
    },
    {
        title: 'Reporting',
        items: ['Power BI'],
    },
];
