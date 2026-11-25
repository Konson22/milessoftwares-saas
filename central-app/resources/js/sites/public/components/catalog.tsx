import { Link } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    ArrowRight,
    ArrowUpRight,
    Banknote,
    BarChart3,
    BookOpenCheck,
    Calculator,
    CalendarCheck,
    Check,
    ChefHat,
    ClipboardCheck,
    ClipboardList,
    CreditCard,
    FileCheck2,
    FileSpreadsheet,
    FileText,
    FolderKanban,
    FolderLock,
    Fuel,
    Gauge,
    Globe,
    GraduationCap,
    HandCoins,
    Handshake,
    HeartHandshake,
    History,
    Landmark,
    LayoutGrid,
    Lock,
    MapPin,
    Package,
    PackageSearch,
    Pill,
    Receipt,
    ScanBarcode,
    ScrollText,
    ShieldAlert,
    ShoppingCart,
    Tags,
    Truck,
    UserCheck,
    UserPlus,
    Users,
    Utensils,
    Wallet,
    Warehouse,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { waitlistUrl } from '@/sites/public/components/blocks';
import {
    darkCard,
    darkIcon,
    display,
    trialUrl,
} from '@/sites/public/components/styles';

/** One product of the public catalog (MarketingCatalog::catalog()). */
export type CatalogEntry = {
    slug: string;
    category: 'erp' | 'application';
    name: string;
    icon: string;
    summary: string;
    /** A fuller description in paragraphs, for the product's own page (optional). */
    overview?: string[];
    /** How this product works with the other apps, shown as its own section. */
    connections?: {
        intro: string;
        links: {
            slug: string;
            name: string;
            icon: string;
            url: string;
            body: string;
        }[];
    };
    headline: string;
    subhead: string;
    audience: string;
    seoTitle: string;
    seoDescription: string;
    features: { icon: string; title: string; body: string }[];
    workflow: { title: string; body: string }[];
    faqs: { question: string; answer: string }[];
    /** Path on the apex host, without a leading slash: erp/pharmacy. */
    url: string;
    status: 'available' | 'early_access' | 'coming_soon';
    /** What an ERP is made of, from the registry. */
    includes: {
        code: string;
        name: string;
        requirement: 'required' | 'optional';
        listed: boolean;
        /** The application's public page, from the root: /apps/finance. */
        url: string;
    }[];
    /** Application code whose free trial this product starts; null: no trial today. */
    trial: string | null;
    /** ERP product code started as itself (NGO), when all it requires is open to trials. */
    trialProduct?: string | null;
    /** Short points for the home page's featured products. */
    highlights?: string[];
    /** How the product runs today; only what is built is listed. */
    offering: Offering;
};

export type Platform = 'web' | 'desktop' | 'android' | 'ios';
export type Connectivity = 'online' | 'offline_sync' | 'offline';
export type Licensing = 'subscription' | 'one_time' | 'quotation';

export type Offering = {
    platforms: Platform[];
    connectivity: Connectivity[];
    licensing: Licensing[];
    /** What the desktop and mobile apps do. */
    desktop: string[];
    mobile: string[];
};

export const platformLabels: Record<Platform, string> = {
    web: 'Web',
    desktop: 'Windows desktop',
    android: 'Android',
    ios: 'iOS',
};

export const connectivityLabels: Record<Connectivity, string> = {
    online: 'Online in the cloud',
    offline_sync: 'Offline with cloud sync',
    offline: 'Fully offline',
};

export const licensingLabels: Record<Licensing, string> = {
    subscription: 'Subscription',
    one_time: 'One-time purchase',
    quotation: 'Custom quotation',
};

export const categoryLabels: Record<CatalogEntry['category'], string> = {
    erp: 'ERP Solutions',
    application: 'Business Applications',
};

/** Each category's section of the products page. */
export const categoryPaths: Record<CatalogEntry['category'], string> = {
    erp: '/products#erp',
    application: '/products#apps',
};

const icons: Record<string, LucideIcon> = {
    activity: Activity,
    'arrow-left-right': ArrowLeftRight,
    banknote: Banknote,
    'bar-chart': BarChart3,
    'book-open-check': BookOpenCheck,
    calculator: Calculator,
    'calendar-check': CalendarCheck,
    'chef-hat': ChefHat,
    'clipboard-check': ClipboardCheck,
    'clipboard-list': ClipboardList,
    'credit-card': CreditCard,
    'file-check': FileCheck2,
    'file-spreadsheet': FileSpreadsheet,
    'file-text': FileText,
    'folder-kanban': FolderKanban,
    'folder-lock': FolderLock,
    fuel: Fuel,
    gauge: Gauge,
    globe: Globe,
    'graduation-cap': GraduationCap,
    'hand-coins': HandCoins,
    'layout-grid': LayoutGrid,
    handshake: Handshake,
    'heart-handshake': HeartHandshake,
    history: History,
    landmark: Landmark,
    lock: Lock,
    'map-pin': MapPin,
    package: Package,
    'package-search': PackageSearch,
    pill: Pill,
    receipt: Receipt,
    'scan-barcode': ScanBarcode,
    'scroll-text': ScrollText,
    'shield-alert': ShieldAlert,
    'shopping-cart': ShoppingCart,
    tags: Tags,
    truck: Truck,
    'user-check': UserCheck,
    'user-plus': UserPlus,
    users: Users,
    utensils: Utensils,
    wallet: Wallet,
    warehouse: Warehouse,
    wrench: Wrench,
};

/** The icon a catalog entry names; a generic grid for unknown names. */
export function catalogIcon(name: string): LucideIcon {
    return icons[name] ?? LayoutGrid;
}

export const statusLabels: Record<CatalogEntry['status'], string | null> = {
    available: 'Available',
    early_access: 'Early access',
    coming_soon: 'Coming soon',
};

export function CatalogStatus({ status }: { status: CatalogEntry['status'] }) {
    const tone = {
        available:
            'bg-site-tint text-site-navy in-[.site-dark]:bg-site-night in-[.site-dark]:text-white in-[.site-dark]:ring-1 in-[.site-dark]:ring-site-navy-line',
        early_access:
            'bg-site-tint text-site-accent-ink in-[.site-dark]:bg-site-night in-[.site-dark]:text-site-highlight in-[.site-dark]:ring-1 in-[.site-dark]:ring-site-navy-line',
        coming_soon:
            'bg-site-soft text-slate-700 ring-1 ring-site-line in-[.site-dark]:bg-site-night in-[.site-dark]:text-site-silver-light in-[.site-dark]:ring-site-navy-line',
    }[status];

    return (
        <span
            className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${tone}`}
        >
            {statusLabels[status]}
        </span>
    );
}

/** The product's icon on a soft tinted square (white-tinted on a dark band). */
export function CatalogTile({
    icon,
    size = 'md',
}: {
    icon: string;
    size?: 'sm' | 'md' | 'lg';
}) {
    const Icon = catalogIcon(icon);
    const box = {
        sm: 'size-9 rounded-lg',
        md: 'size-12 rounded-xl',
        lg: 'size-16 rounded-2xl',
    }[size];
    const glyph = { sm: 'size-4', md: 'size-6', lg: 'size-8' }[size];

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center bg-site-tint text-site-accent-ink in-[.site-dark]:bg-site-navy-light in-[.site-dark]:text-white ${box}`}
        >
            <Icon className={glyph} aria-hidden />
        </span>
    );
}

/** A starburst seal: 24 points round a circle, in a 100 × 100 box. */
const burst = Array.from({ length: 48 }, (_, i) => {
    const r = i % 2 ? 43 : 50;
    const a = (i / 48) * Math.PI * 2;

    return `${(50 + r * Math.sin(a)).toFixed(2)},${(50 - r * Math.cos(a)).toFixed(2)}`;
}).join(' ');

/**
 * The card's promotion badge, after a shop's sale stickers: a starburst
 * seal with ribbon tails for a product that is coming soon, a corner
 * ribbon for early access, a hanging tag with the free days for a product
 * open for trials, and a small flag for the others.
 */
export function StatusBadge({
    status,
    trialDays,
}: {
    status: CatalogEntry['status'];
    /** Set when the product is open for trials. */
    trialDays?: number;
}) {
    if (status === 'coming_soon') {
        return (
            <span className="absolute -top-3 right-4 z-10 block size-[5.75rem] rotate-[-12deg] group-hover:scale-105 group-hover:rotate-[4deg] motion-safe:transition-transform motion-safe:duration-500">
                {/* The ribbon tails, under the seal. */}
                <span
                    aria-hidden
                    className="absolute top-[55%] left-[22%] h-16 w-6 origin-top rotate-[20deg] bg-site-accent-ink [clip-path:polygon(0_0,100%_0,100%_100%,50%_80%,0_100%)]"
                />
                <span
                    aria-hidden
                    className="absolute top-[55%] right-[22%] h-16 w-6 origin-top -rotate-[20deg] bg-site-accent [clip-path:polygon(0_0,100%_0,100%_100%,50%_80%,0_100%)]"
                />
                <svg
                    aria-hidden
                    viewBox="0 0 100 100"
                    className="absolute inset-0 size-full drop-shadow-[0_6px_10px_rgb(8_15_31/0.35)]"
                >
                    <polygon points={burst} className="fill-site-highlight" />
                    <circle
                        cx="50"
                        cy="50"
                        r="36"
                        fill="none"
                        strokeWidth="1.5"
                        strokeDasharray="3 3"
                        className="stroke-slate-400"
                    />
                </svg>
                <span className="absolute inset-0 flex flex-col items-center justify-center text-center leading-none font-extrabold text-site-night uppercase">
                    <span className="text-[0.625rem] tracking-[0.18em]">
                        Coming
                    </span>
                    <span className="mt-0.5 text-lg tracking-tight">Soon</span>
                </span>
            </span>
        );
    }

    if (status === 'early_access') {
        return (
            <span className="absolute top-0 right-0 z-10 size-28 overflow-hidden rounded-tr-xl">
                <span className="absolute top-[1.6rem] -right-[2.6rem] w-44 rotate-45 bg-site-highlight py-1.5 text-center text-[0.6875rem] font-extrabold tracking-[0.14em] text-site-night uppercase shadow-[0_4px_10px_rgb(8_15_31/0.3)] ring-1 ring-site-night/10">
                    Early access
                </span>
            </span>
        );
    }

    if (trialDays) {
        return (
            <span className="absolute -top-1 right-6 z-10 flex flex-col items-center group-hover:rotate-[6deg] motion-safe:origin-top motion-safe:transition-transform motion-safe:duration-500">
                {/* The string. */}
                <span aria-hidden className="h-5 w-px bg-slate-300" />
                <span className="relative flex flex-col items-center rounded-b-lg bg-site-highlight px-3 pt-4 pb-2 leading-none font-extrabold text-site-night uppercase shadow-[0_8px_16px_rgb(8_15_31/0.35)] [clip-path:polygon(50%_0,100%_14%,100%_100%,0_100%,0_14%)]">
                    <span
                        aria-hidden
                        className="absolute top-1.5 left-1/2 size-2 -translate-x-1/2 rounded-full bg-site-night"
                    />
                    <span className="text-xl tracking-tight">{trialDays}</span>
                    <span className="mt-0.5 text-[0.625rem] tracking-[0.14em]">
                        Days free
                    </span>
                </span>
            </span>
        );
    }

    return (
        <span className="absolute top-4 right-0 z-10 bg-site-highlight py-1.5 pr-4 pl-5 text-[0.6875rem] font-extrabold tracking-[0.14em] text-site-night uppercase shadow-[0_4px_10px_rgb(8_15_31/0.3)] [clip-path:polygon(0_0,100%_0,100%_100%,0_100%,0.6rem_50%)]">
            {statusLabels[status]}
        </span>
    );
}

/**
 * The compact product card (home page suite and ERPs): a navy card with
 * the product's blue icon, its real status, name, one line and a link.
 */
export function CompactCard({
    href,
    icon: Icon,
    title,
    body,
    status,
}: {
    href: string;
    icon: LucideIcon;
    title: string;
    body: string;
    status?: CatalogEntry['status'];
}) {
    return (
        <li>
            <Link href={href} className={darkCard}>
                <span className="flex items-start justify-between gap-3">
                    <span className={darkIcon}>
                        <Icon
                            className="size-6"
                            strokeWidth={1.75}
                            aria-hidden
                        />
                    </span>
                    {status && status !== 'available' && (
                        <CatalogStatus status={status} />
                    )}
                </span>
                <span className="mt-5 block text-lg leading-snug font-semibold text-white">
                    {title}
                </span>
                <span className="mt-1.5 block flex-1 text-sm leading-relaxed text-site-silver">
                    {body}
                </span>
                <span className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-site-highlight">
                    Learn more
                    <span className="sr-only"> about {title}</span>
                    <ArrowRight
                        className="size-4 transition-transform group-hover:translate-x-1"
                        aria-hidden
                    />
                </span>
            </Link>
        </li>
    );
}

/**
 * The featured product card, in three bands cut on the diagonal: near
 * black at the top with the product's icon, name and summary (and its
 * promotion badge, StatusBadge); dark blue in the middle with its main
 * points; light silver at the foot with where it runs and the next step.
 * Only a product open for trials offers one; the others lead to their
 * page or the waitlist. `highlights` shows the product's highlights
 * instead of its first features; `title` is the name to show (Miles
 * Pharmacy) when it is not the catalog's.
 */
export function ProductCard({
    product,
    trialDays,
    headingLevel: Heading = 'h3',
    highlights = false,
    title,
}: {
    product: CatalogEntry;
    trialDays: number;
    headingLevel?: 'h2' | 'h3';
    highlights?: boolean;
    title?: string;
}) {
    const name = title ?? product.name;
    const Icon = catalogIcon(product.icon);
    // The product's main points: its highlights where asked for, otherwise
    // the first few features from its page.
    const points =
        highlights && product.highlights
            ? product.highlights
            : product.features.slice(0, 4).map((feature) => feature.title);
    const action =
        'inline-flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold whitespace-nowrap transition-colors';
    // The diagonal seams: each lower band rises 1.25rem over the one above.
    const seam = '-mt-5 pt-10';

    return (
        <article className="group relative flex h-full flex-col rounded-xl bg-site-silver-light shadow-[0_1px_2px_rgb(7_26_61/0.08),0_14px_32px_-14px_rgb(7_26_61/0.35)] ring-1 ring-site-line transition-[box-shadow,transform] duration-300 hover:-translate-y-1 hover:shadow-[0_2px_4px_rgb(7_26_61/0.1),0_24px_48px_-16px_rgb(7_26_61/0.45)] hover:ring-site-leaf">
            {/* Top: near black, the product's icon, name and summary. */}
            <div className="site-dark relative overflow-hidden rounded-t-xl bg-site-night px-6 pt-6 pb-9 sm:px-7">
                <span
                    aria-hidden
                    className="absolute -right-12 -bottom-16 size-44 rounded-full border-[14px] border-site-navy group-hover:-translate-x-4 motion-safe:transition-transform motion-safe:duration-500"
                />
                <span className={`relative ${darkIcon}`}>
                    <Icon className="size-6" strokeWidth={1.75} aria-hidden />
                </span>
                <Heading
                    className={`relative mt-4 pr-20 text-xl leading-snug sm:text-[1.375rem] ${display}`}
                >
                    <Link
                        href={`/${product.url}`}
                        className="transition-colors hover:text-site-highlight"
                    >
                        {name}
                    </Link>
                </Heading>
                <p className="relative mt-2 text-sm leading-relaxed text-site-silver">
                    {product.subhead || product.summary}
                </p>
            </div>

            <StatusBadge
                status={product.status}
                trialDays={product.trial ? trialDays : undefined}
            />

            {/* Middle: dark blue, the main points. */}
            {points.length > 0 && (
                <div
                    className={`relative flex-1 bg-site-accent px-6 pb-9 sm:px-7 ${seam} [clip-path:polygon(0_1.25rem,100%_0,100%_100%,0_100%)]`}
                >
                    <ul className="grid gap-2.5 text-sm font-medium text-white">
                        {points.map((point) => (
                            <li
                                key={point}
                                className="flex items-start gap-2.5"
                            >
                                <span className="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-white text-site-accent">
                                    <Check
                                        className="size-3"
                                        strokeWidth={3}
                                        aria-hidden
                                    />
                                </span>
                                {point}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Foot: light silver, where it runs and the next step. */}
            <div
                className={`relative rounded-b-xl bg-site-silver-light px-6 pb-6 sm:px-7 sm:pb-7 ${seam} ${points.length === 0 ? 'flex-1' : ''} [clip-path:polygon(0_0,100%_1.25rem,100%_100%,0_100%)]`}
            >
                <PlatformList
                    platforms={product.offering.platforms}
                    planned={product.status === 'coming_soon'}
                />
                <div className="mt-5 flex items-center gap-3">
                    {product.trial ? (
                        <a
                            href={trialUrl(product.trial, product.trialProduct)}
                            className={`${action} bg-site-accent text-white shadow-sm hover:bg-site-accent-hover`}
                        >
                            Start {trialDays}-day free trial
                            <span className="sr-only"> of {name}</span>
                        </a>
                    ) : product.status === 'coming_soon' ? (
                        <Link
                            href={waitlistUrl(product.name)}
                            className={`${action} bg-site-navy text-white hover:bg-site-night`}
                        >
                            Join the waitlist
                            <span className="sr-only"> for {name}</span>
                            <ArrowRight
                                className="size-4 text-site-highlight"
                                aria-hidden
                            />
                        </Link>
                    ) : (
                        <Link
                            href={`/contact?subject=${encodeURIComponent(`Demo: ${product.name}`)}`}
                            className={`${action} bg-site-accent text-white shadow-sm hover:bg-site-accent-hover`}
                        >
                            Request a demo
                            <span className="sr-only"> of {name}</span>
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    )}
                    <Link
                        href={`/${product.url}`}
                        className="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-2.5 text-sm font-semibold text-site-ink hover:text-site-accent"
                    >
                        Learn more
                        <span className="sr-only"> about {name}</span>
                        <ArrowUpRight className="size-4" aria-hidden />
                    </Link>
                </div>
            </div>
        </article>
    );
}

/**
 * The platforms a product runs on, as small labels. A product not yet
 * released says they are planned.
 */
export function PlatformList({
    platforms,
    planned = false,
    className = '',
}: {
    platforms: Platform[];
    planned?: boolean;
    className?: string;
}) {
    return (
        <p
            className={`flex flex-wrap items-center gap-1.5 text-xs ${className}`}
        >
            <span className="mr-0.5 font-semibold text-site-muted">
                {planned ? 'Planned for' : 'Runs on'}
            </span>
            {platforms.map((platform) => (
                <span
                    key={platform}
                    className="rounded-full bg-white px-2.5 py-1 font-semibold text-site-ink ring-1 ring-site-line"
                >
                    {platformLabels[platform]}
                </span>
            ))}
        </p>
    );
}
