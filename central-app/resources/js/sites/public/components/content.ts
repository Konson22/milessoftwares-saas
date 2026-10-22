import {
    BookOpenCheck,
    Building2,
    Calculator,
    ClipboardCheck,
    Coins,
    Fingerprint,
    Globe,
    GraduationCap,
    Handshake,
    HeartHandshake,
    KeyRound,
    Landmark,
    Lock,
    PackageCheck,
    Pill,
    ScrollText,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Store,
    Truck,
    UserCheck,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Site-wide marketing copy: industries, the home page sections, the
 * platform and about pages. Each product's own wording is in
 * resources/content/marketing-catalog.json, and what is sold, in what
 * status, comes from the server.
 */

export type Feature = { icon: LucideIcon; title: string; body: string };

export type Solution = {
    id: string;
    icon: LucideIcon;
    title: string;
    pain: string;
    change: string;
    proof: string[];
    /** Catalog page the industry leads to (erp/pharmacy); null: the platform page. */
    product: string | null;
};

/** Industry pages: the reader's problem first, then the product. */
export const solutions: Solution[] = [
    {
        id: 'pharmacies',
        icon: Pill,
        title: 'Pharmacies',
        pain: 'Expired stock found on the shelf, prescriptions on paper, and cash that never matches the book.',
        change: 'The counter sells the batch that expires first and refuses anything expired or recalled. Prescriptions are verified by a pharmacist, and tills are counted and posted every night.',
        proof: [
            'Expiry-first selling',
            'Quarantine and recall',
            'Second-person disposals',
            'Near-expiry report',
        ],
        product: 'erp/pharmacy',
    },
    {
        id: 'wholesalers',
        icon: PackageCheck,
        title: 'Pharmaceutical wholesalers',
        pain: 'Batches you cannot trace once they leave, and supplier bills keyed in twice.',
        change: 'Every batch is traced from receipt to sale. Receiving a delivery creates the supplier bill in Finance.',
        proof: [
            'Batch traceability',
            'Goods received to bill',
            'Supplier returns',
            'Branch transfers',
        ],
        product: 'erp/pharmacy',
    },
    {
        id: 'shops',
        icon: ShoppingCart,
        title: 'Shops and supermarkets',
        pain: 'A slow queue at the till, and no clear view of what is selling or what to reorder.',
        change: 'A barcode counter that keeps up. Sales by product, category and cashier, and a reorder list with shortfall and pace.',
        proof: [
            'Barcode checkout',
            'Cash, mobile money, card',
            'Sales by cashier',
            'Reorder list',
        ],
        product: 'erp/retail',
    },
    {
        id: 'traders',
        icon: Handshake,
        title: 'Wholesalers and traders',
        pain: "Credit customers over their limit, and invoices that don't match what was delivered.",
        change: 'Credit limits are enforced at the sale. Quotations become orders, delivery notes and invoices, and statements match the books.',
        proof: [
            'Credit limits',
            'Price lists',
            'Partial deliveries',
            'Customer statements',
        ],
        product: 'erp/retail',
    },
    {
        id: 'multi-branch',
        icon: Building2,
        title: 'Multi-branch businesses',
        pain: 'Each branch is a black box, and staff see more than they should.',
        change: 'Branches and departments, roles per branch, stock transfers between branches, and reports for each one.',
        proof: [
            'Roles per branch',
            'Branch transfers',
            'Per-branch reports',
            'One sign-in',
        ],
        product: null,
    },
    {
        id: 'accountants',
        icon: Calculator,
        title: 'Accountants and bookkeepers',
        pain: 'Clients hand over a box of receipts, and month-end takes weeks.',
        change: 'A ledger fed live from the counter, bank reconciliation, locked periods and tax returns.',
        proof: [
            'Live ledger',
            'Bank reconciliation',
            'Locked periods',
            'Tax returns',
        ],
        product: 'apps/finance',
    },
    {
        id: 'schools',
        icon: GraduationCap,
        title: 'Schools and colleges',
        pain: 'Fees tracked in one place, the accounts in another, and student records on paper.',
        change: 'School ERP is being built to take admissions, classes, examinations and fees into one system, with fees posting to Miles Finance.',
        proof: ['Admissions', 'Attendance', 'Examinations', 'Student fees'],
        product: 'erp/school',
    },
    {
        id: 'ngos',
        icon: HeartHandshake,
        title: 'NGOs and development organizations',
        pain: 'Donor reports assembled by hand from spreadsheets that never quite agree.',
        change: 'NGO ERP is being built for programs, grants and budgets on fund accounting, so each donor report comes from the same books.',
        proof: ['Programs', 'Grants', 'Budgets', 'Donor reporting'],
        product: 'erp/ngo',
    },
    {
        id: 'logistics',
        icon: Truck,
        title: 'Transport and logistics',
        pain: 'Trips that are never costed, and deliveries tracked by phone call.',
        change: 'Logistics ERP is planned for shipments, fleet and trip costs, with billing in Miles Finance.',
        proof: ['Shipments', 'Fleet', 'Delivery tracking', 'Trip costs'],
        product: 'erp/logistics',
    },
];

/** Why Miles Softwares, on the home page. */
export const whyMiles: Feature[] = [
    {
        icon: Store,
        title: 'Made for your industry',
        body: 'Batches and expiry for medicines; barcodes, price lists and credit customers for shops. Not a generic system with your words pasted on.',
    },
    {
        icon: BookOpenCheck,
        title: 'Books that keep themselves',
        body: 'Sales, purchases, returns and cash counts post to Miles Finance as they happen. Month-end is a check, not a rebuild.',
    },
    {
        icon: UserCheck,
        title: 'Control without friction',
        body: 'Cashiers sell, managers approve, owners see everything. Roles are set per application and per branch.',
    },
    {
        icon: KeyRound,
        title: 'One account, every app',
        body: 'Your team signs in once and moves between applications. Sign in with Google or a passkey.',
    },
    {
        icon: Building2,
        title: 'Grows with you',
        body: 'Start with one application or one ERP. Add branches, people and applications without starting again.',
    },
    {
        icon: ShieldCheck,
        title: 'Your data, on record',
        body: "Each business's data is kept apart, and an audit trail records who did what.",
    },
];

/** Getting started, on the home page. */
export const howItWorks: { title: string; body: string }[] = [
    {
        title: 'Choose your solution',
        body: 'Pick an ERP for your industry or a single business application.',
    },
    {
        title: 'Create your workspace',
        body: 'Your business gets its own address, like yourbusiness.milesoftwares.com.',
    },
    {
        title: 'Invite your team',
        body: 'Add branches and departments, invite staff and give each person a role.',
    },
    {
        title: 'Run your business',
        body: 'Sell, buy and manage, and watch the accounts fill in on their own.',
    },
];

/** One connected platform, on the home page. */
export const connected: Feature[] = [
    {
        icon: KeyRound,
        title: 'Single sign-on',
        body: 'One account across every Miles application.',
    },
    {
        icon: Building2,
        title: 'One organization',
        body: 'Branches, departments and people set up once, shared by every app.',
    },
    {
        icon: Landmark,
        title: 'One set of books',
        body: 'Every ERP posts to Miles Finance automatically.',
    },
    {
        icon: ShieldCheck,
        title: 'One set of rules',
        body: 'Roles, approvals and an audit trail across the platform.',
    },
];

/** The platform page: what every Miles product stands on. */
export const platformGroups: {
    id: string;
    title: string;
    lead: string;
    points: Feature[];
}[] = [
    {
        id: 'workspace',
        title: 'Your workspace',
        lead: 'Set up your organization once. Every application follows it.',
        points: [
            {
                icon: Globe,
                title: 'Your own address',
                body: 'yourbusiness.milesoftwares.com, with each application at its own path.',
            },
            {
                icon: Building2,
                title: 'Branches and departments',
                body: 'Branches, departments and sub-departments that mirror how you work. Archive, never lose history.',
            },
            {
                icon: Users,
                title: 'Your whole team',
                body: 'Invite staff by email or create accounts for those without one. Run more than one business from one sign-in.',
            },
        ],
    },
    {
        id: 'sign-in',
        title: 'Sign-in your team will actually use',
        lead: 'One account for every application, with the protection you would expect.',
        points: [
            {
                icon: KeyRound,
                title: 'Single sign-on',
                body: 'One account across every Miles application.',
            },
            {
                icon: Fingerprint,
                title: 'Google and passkeys',
                body: 'Sign in with Google, or a passkey on your phone or laptop. No password to forget.',
            },
            {
                icon: ShieldCheck,
                title: 'Two-step verification',
                body: 'Authenticator codes and recovery codes. See and end active sessions on any device, with security alerts by email.',
            },
        ],
    },
    {
        id: 'permissions',
        title: 'Permissions with teeth',
        lead: 'Give each person exactly what their job needs, where they do it.',
        points: [
            {
                icon: UserCheck,
                title: 'Roles per application and branch',
                body: 'A cashier in one branch sees nothing in another.',
            },
            {
                icon: Lock,
                title: 'No self-promotion',
                body: 'Nobody can grant themselves more than they have, or hand out rights they do not hold.',
            },
            {
                icon: ClipboardCheck,
                title: 'Separation of duties',
                body: 'The person who prepares something cannot approve it. Disposals and write-offs need a second person.',
            },
        ],
    },
    {
        id: 'data',
        title: 'Your data, kept apart and on record',
        lead: 'Every number has a history, and only your people can see it.',
        points: [
            {
                icon: ShieldCheck,
                title: 'Isolated by organization',
                body: "One business can never see another's records.",
            },
            {
                icon: ScrollText,
                title: 'Append-only audit log',
                body: 'Sign-ins, role changes and administrative actions are recorded with tamper checks. Ledger and stock entries are reversed, never edited.',
            },
            {
                icon: HeartHandshake,
                title: 'Support only with your knowledge',
                body: 'Our support staff need a time-limited grant approved by a second manager, and you are emailed when it happens.',
            },
        ],
    },
];

export const aboutValues: Feature[] = [
    {
        icon: Store,
        title: 'Start where the work happens',
        body: 'At the counter, in the store room and at month-end. Not in a meeting about software.',
    },
    {
        icon: BookOpenCheck,
        title: 'Never type it twice',
        body: 'Every sale, purchase and return lands in the accounts on its own.',
    },
    {
        icon: ShoppingBag,
        title: 'Grow a piece at a time',
        body: 'Start with one application and add the next when you are ready.',
    },
    {
        icon: Coins,
        title: 'Priced for growing businesses',
        body: 'Proper software without a big-vendor rollout.',
    },
];
