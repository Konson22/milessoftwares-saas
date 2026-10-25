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
    Layers,
    LifeBuoy,
    Lock,
    MonitorSmartphone,
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
    Utensils,
    WifiOff,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

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
        id: "pharmacies",
        icon: Pill,
        title: "Pharmacies",
        pain: "Expired stock found on the shelf, prescriptions on paper, and cash that never matches the book.",
        change: "The counter sells the batch that expires first and refuses anything expired or recalled. Prescriptions are verified by a pharmacist, and tills are counted and posted every night.",
        proof: [
            "Expiry-first selling",
            "Quarantine and recall",
            "Second-person disposals",
            "Near-expiry report",
        ],
        product: "erp/pharmacy",
    },
    {
        id: "wholesalers",
        icon: PackageCheck,
        title: "Pharmaceutical wholesalers",
        pain: "Batches you cannot trace once they leave, and supplier bills keyed in twice.",
        change: "Every batch is traced from receipt to sale. With Finance, receiving a delivery creates the supplier bill.",
        proof: [
            "Batch traceability",
            "Goods received to bill",
            "Supplier returns",
            "Branch transfers",
        ],
        product: "erp/pharmacy",
    },
    {
        id: "shops",
        icon: ShoppingCart,
        title: "Shops and supermarkets",
        pain: "A slow queue at the till, and no clear view of what is selling or what to reorder.",
        change: "A barcode counter that keeps up. Sales by product, category and cashier, and a reorder list with shortfall and pace.",
        proof: [
            "Barcode checkout",
            "Cash, mobile money, card",
            "Sales by cashier",
            "Reorder list",
        ],
        product: "erp/retail",
    },
    {
        id: "traders",
        icon: Handshake,
        title: "Wholesalers and traders",
        pain: "Credit customers over their limit, and invoices that don't match what was delivered.",
        change: "Credit limits are enforced at the sale. Quotations become orders, delivery notes and invoices, and customer statements stay up to date.",
        proof: [
            "Credit limits",
            "Price lists",
            "Partial deliveries",
            "Customer statements",
        ],
        product: "erp/retail",
    },
    {
        id: "restaurants",
        icon: Utensils,
        title: "Restaurants and cafés",
        pain: "Orders shouted to the kitchen, bills added up by hand, and drinks that go missing.",
        change: "Restaurant ERP is being built for table orders, kitchen tickets, billing and drinks stock, with optional Finance & Accounting for the books.",
        proof: ["Table orders", "Kitchen tickets", "Billing", "Drinks stock"],
        product: "erp/restaurant",
    },
    {
        id: "multi-branch",
        icon: Building2,
        title: "Multi-branch businesses",
        pain: "Each branch is a black box, and staff see more than they should.",
        change: "Branches and departments, roles per branch, stock transfers between branches, and reports for each one.",
        proof: [
            "Roles per branch",
            "Branch transfers",
            "Per-branch reports",
            "One sign-in",
        ],
        product: null,
    },
    {
        id: "accountants",
        icon: Calculator,
        title: "Accountants and bookkeepers",
        pain: "Clients hand over a box of receipts, and month-end takes weeks.",
        change: "A ledger fed live from the counter, bank reconciliation, locked periods and tax returns.",
        proof: [
            "Live ledger",
            "Bank reconciliation",
            "Locked periods",
            "Tax returns",
        ],
        product: "apps/finance",
    },
    {
        id: "schools",
        icon: GraduationCap,
        title: "Schools and colleges",
        pain: "Fees tracked in one place, the accounts in another, and student records on paper.",
        change: "School ERP is being built to take admissions, classes, examinations and fees into one system, with optional Finance & Accounting for the books.",
        proof: ["Admissions", "Attendance", "Examinations", "Student fees"],
        product: "erp/school",
    },
    {
        id: "ngos",
        icon: HeartHandshake,
        title: "NGOs and development organizations",
        pain: "Donor reports assembled by hand from spreadsheets that never quite agree.",
        change: "NGO ERP is being built for programs, grants and budgets on fund accounting, so each donor report comes from the same books.",
        proof: ["Programs", "Grants", "Budgets", "Donor reporting"],
        product: "erp/ngo",
    },
    {
        id: "logistics",
        icon: Truck,
        title: "Transport and logistics",
        pain: "Trips that are never costed, and deliveries tracked by phone call.",
        change: "Logistics ERP is planned for shipments, fleet, trip costs and customer billing.",
        proof: ["Shipments", "Fleet", "Delivery tracking", "Trip costs"],
        product: "erp/logistics",
    },
];

/** Why Miles Global Softwares, on the home page. */
export const whyMiles: Feature[] = [
    {
        icon: Store,
        title: "Industry-focused software",
        body: "Batches and expiry for medicines; barcodes, credit customers and price lists for shops. Built for the trade, not renamed for it.",
    },
    {
        icon: Layers,
        title: "Ready-made and custom",
        body: "Start with a product that is ready today, have it adapted, or have a new system built around how you work.",
    },
    {
        icon: MonitorSmartphone,
        title: "Web, desktop and mobile",
        body: "Every product runs in the browser. Selected products add desktop and mobile apps.",
    },
    {
        icon: WifiOff,
        title: "Offline where available",
        body: "Desktop and mobile apps that carry on without internet and sync later, for the products that have them.",
    },
    {
        icon: KeyRound,
        title: "Connected applications",
        body: "One sign-in, one organization and one set of books across every Miles application.",
    },
    {
        icon: Coins,
        title: "Flexible deployment and licensing",
        body: "Subscribe in the cloud, buy a standalone offline edition once where one is offered, or agree a quotation.",
    },
    {
        icon: LifeBuoy,
        title: "Implementation and support",
        body: "Set-up, data loading, training and the maintenance and support you agree with us.",
    },
];

/** How we work, on the home page. */
export const howItWorks: { title: string; body: string }[] = [
    {
        title: "Discover",
        body: "We learn your requirements and the operational problems you want to solve.",
    },
    {
        title: "Choose or build",
        body: "Select a ready-made product, customize one, or have a new system developed.",
    },
    {
        title: "Implement",
        body: "We configure, deploy and load your data so your team can start working.",
    },
    {
        title: "Support",
        body: "Maintenance and technical support, as agreed with you.",
    },
];

/**
 * Miles Products: the portfolio under its brand names, each pointing to its
 * catalog entry (wording, status and trial come from there). The icon is
 * the entry's, for the menus, which do not receive the catalog.
 */
export const milesProducts: {
    title: string;
    category: "erp" | "application";
    slug: string;
    icon: string;
}[] = [
    {
        title: "Miles Pharmacy",
        category: "erp",
        slug: "pharmacy",
        icon: "pill",
    },
    {
        title: "Miles Retail",
        category: "erp",
        slug: "retail",
        icon: "shopping-cart",
    },
    {
        title: "Miles Finance",
        category: "application",
        slug: "finance",
        icon: "landmark",
    },
    { title: "Miles HR", category: "application", slug: "hr", icon: "users" },
    {
        title: "Miles Logistics",
        category: "erp",
        slug: "logistics",
        icon: "truck",
    },
    {
        title: "Miles Restaurant",
        category: "erp",
        slug: "restaurant",
        icon: "utensils",
    },
];

/** Miles Cloud: what the platform does for an organization. */
export const cloudPillars: Feature[] = [
    {
        icon: KeyRound,
        title: "One account",
        body: "One sign-in for every Miles application you use, with Google, passkeys and two-step verification.",
    },
    {
        icon: Building2,
        title: "Your organization",
        body: "Your workspace at its own address, with branches and departments. Run more than one business from the same account.",
    },
    {
        icon: Layers,
        title: "Application access",
        body: "Open each application your organization uses from one dashboard. Data flows between them: add Finance and sales and purchases reach it on their own.",
    },
    {
        icon: Coins,
        title: "Subscriptions",
        body: "Subscribe only to the applications you need, start with a free trial, and add or cancel plans from your dashboard.",
    },
    {
        icon: UserCheck,
        title: "User permissions",
        body: "Invite your team and give each person a role per application and branch, so they see only what their job needs.",
    },
];

/** Miles Cloud: from a new workspace to a growing set of applications. */
export const cloudSteps: { title: string; body: string }[] = [
    {
        title: "Create your workspace",
        body: "Sign up, name your organization and start a free trial of the application you need first.",
    },
    {
        title: "Choose your applications",
        body: "Pick a plan for each application you use. Our team confirms plan changes with you.",
    },
    {
        title: "Bring in your team",
        body: "Invite staff by email and give each one the roles their job needs.",
    },
    {
        title: "Grow when you are ready",
        body: "Add the next application to the same workspace, with the same people and the same books.",
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
        id: "workspace",
        title: "Your workspace",
        lead: "Set up your organization once. Every application follows it.",
        points: [
            {
                icon: Globe,
                title: "Your own address",
                body: "yourbusiness.milesoftwares.com, with each application at its own path.",
            },
            {
                icon: Building2,
                title: "Branches and departments",
                body: "Branches, departments and sub-departments that mirror how you work. Archive, never lose history.",
            },
            {
                icon: Users,
                title: "Your whole team",
                body: "Invite staff by email or create accounts for those without one. Run more than one business from one sign-in.",
            },
        ],
    },
    {
        id: "sign-in",
        title: "Sign-in your team will actually use",
        lead: "One account for every application, with the protection you would expect.",
        points: [
            {
                icon: KeyRound,
                title: "Single sign-on",
                body: "One account across every Miles application.",
            },
            {
                icon: Fingerprint,
                title: "Google and passkeys",
                body: "Sign in with Google, or a passkey on your phone or laptop. No password to forget.",
            },
            {
                icon: ShieldCheck,
                title: "Two-step verification",
                body: "Authenticator codes and recovery codes. See and end active sessions on any device, with security alerts by email.",
            },
        ],
    },
    {
        id: "permissions",
        title: "Permissions with teeth",
        lead: "Give each person exactly what their job needs, where they do it.",
        points: [
            {
                icon: UserCheck,
                title: "Roles per application and branch",
                body: "A cashier in one branch sees nothing in another.",
            },
            {
                icon: Lock,
                title: "No self-promotion",
                body: "Nobody can grant themselves more than they have, or hand out rights they do not hold.",
            },
            {
                icon: ClipboardCheck,
                title: "Separation of duties",
                body: "The person who prepares something cannot approve it. Disposals and write-offs need a second person.",
            },
        ],
    },
    {
        id: "data",
        title: "Your data, kept apart and on record",
        lead: "Every number has a history, and only your people can see it.",
        points: [
            {
                icon: ShieldCheck,
                title: "Isolated by organization",
                body: "One business can never see another's records.",
            },
            {
                icon: ScrollText,
                title: "Append-only audit log",
                body: "Sign-ins, role changes and administrative actions are recorded with tamper checks. Ledger and stock entries are reversed, never edited.",
            },
            {
                icon: HeartHandshake,
                title: "Support only with your knowledge",
                body: "Our support staff need a time-limited grant approved by a second manager, and you are emailed when it happens.",
            },
        ],
    },
];

export const aboutValues: Feature[] = [
    {
        icon: Store,
        title: "Start where the work happens",
        body: "At the counter, in the store room and at month-end. Not in a meeting about software.",
    },
    {
        icon: BookOpenCheck,
        title: "Never type it twice",
        body: "Every sale, purchase and return lands in the accounts on its own.",
    },
    {
        icon: ShoppingBag,
        title: "Grow a piece at a time",
        body: "Start with one application and add the next when you are ready.",
    },
    {
        icon: Coins,
        title: "Priced for growing businesses",
        body: "Proper software without a big-vendor rollout.",
    },
];
