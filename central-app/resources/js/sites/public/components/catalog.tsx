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
    Monitor,
    Package,
    PackageSearch,
    Pill,
    Receipt,
    ScanBarcode,
    ScrollText,
    ShieldAlert,
    ShoppingCart,
    Smartphone,
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
 * The featured product card: a navy head with the product's icon, name and
 * summary (and its promotion badge, StatusBadge); a white body with its
 * main points; a light foot with where it runs and the next step. Only a
 * product open for trials offers one; the others lead to their page or the
 * waitlist. `highlights` shows the product's highlights instead of its
 * first features; `title` is the name to show (Miles Pharmacy) when it is
 * not the catalog's.
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

    return (
        <article className="group relative flex h-full flex-col rounded-2xl bg-white shadow-[0_1px_2px_rgb(7_26_61/0.06),0_12px_28px_-16px_rgb(7_26_61/0.3)] ring-1 ring-site-line transition-[box-shadow,transform,--tw-ring-color] duration-300 hover:-translate-y-1 hover:shadow-[0_2px_4px_rgb(7_26_61/0.08),0_24px_44px_-18px_rgb(7_26_61/0.4)] hover:ring-site-leaf">
            {/* Head: navy, the product's icon, name and summary. */}
            <div className="site-dark relative overflow-hidden rounded-t-2xl bg-site-navy px-6 pt-6 pb-7 sm:px-7">
                {/* Two solid rings in the corner, drifting on hover. */}
                <span
                    aria-hidden
                    className="absolute -right-14 -bottom-20 size-48 rounded-full border-[18px] border-site-navy-light group-hover:-translate-x-3 motion-safe:transition-transform motion-safe:duration-500"
                />
                <span
                    aria-hidden
                    className="absolute -right-4 -bottom-10 size-24 rounded-full border-[10px] border-site-navy-line group-hover:-translate-y-2 motion-safe:transition-transform motion-safe:duration-500"
                />
                <span className="relative inline-flex size-12 items-center justify-center rounded-xl bg-site-accent text-white ring-4 ring-site-navy-light transition-colors group-hover:bg-site-leaf">
                    <Icon className="size-6" strokeWidth={1.75} aria-hidden />
                </span>
                <Heading
                    className={`relative mt-5 pr-20 text-xl leading-snug sm:text-[1.375rem] ${display}`}
                >
                    <Link
                        href={`/${product.url}`}
                        className="transition-colors hover:text-site-highlight"
                    >
                        {name}
                    </Link>
                </Heading>
                <p className="relative mt-2 text-sm leading-relaxed text-site-silver-light">
                    {product.subhead || product.summary}
                </p>
                <span
                    aria-hidden
                    className="absolute bottom-0 left-6 h-1 w-10 rounded-t-full bg-site-leaf transition-[width] duration-500 group-hover:w-20 sm:left-7"
                />
            </div>

            <StatusBadge
                status={product.status}
                trialDays={product.trial ? trialDays : undefined}
            />

            {/* Body: white, the main points. */}
            <div className="flex-1 px-6 py-6 sm:px-7">
                {points.length > 0 && (
                    <ul className="grid gap-3 text-sm font-medium text-site-body">
                        {points.map((point) => (
                            <li key={point} className="flex items-start gap-3">
                                <span className="mt-px inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-site-tint text-site-accent">
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
                )}
            </div>

            {/* Foot: where it runs and the next step. */}
            <div className="rounded-b-2xl border-t border-site-line bg-site-wash px-6 pt-5 pb-6 sm:px-7">
                <PlatformList
                    platforms={product.offering.platforms}
                    planned={product.status === 'coming_soon'}
                />
                <div className="mt-4 flex flex-wrap items-center gap-3">
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
                        className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-white px-3 py-2.5 text-sm font-semibold text-site-ink ring-1 ring-site-line transition-colors hover:text-site-accent hover:ring-site-leaf"
                    >
                        Details
                        <span className="sr-only"> about {name}</span>
                        <ArrowUpRight className="size-4" aria-hidden />
                    </Link>
                </div>
            </div>
        </article>
    );
}

const platformIcons: Record<Platform, LucideIcon> = {
    web: Globe,
    desktop: Monitor,
    android: Smartphone,
    ios: Smartphone,
};

/**
 * The platforms a product runs on, as small labels with icons. A product
 * not yet released says they are planned.
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
            {platforms.map((platform) => {
                const PlatformIcon = platformIcons[platform];

                return (
                    <span
                        key={platform}
                        className="inline-flex items-center gap-1 rounded-md bg-white px-2 py-1 font-semibold text-site-ink ring-1 ring-site-line"
                    >
                        <PlatformIcon
                            className="size-3.5 text-site-accent"
                            aria-hidden
                        />
                        {platformLabels[platform]}
                    </span>
                );
            })}
        </p>
    );
}
