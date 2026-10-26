import { Link } from "@inertiajs/react";
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
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { waitlistUrl } from "@/sites/public/components/blocks";
import { card, display, trialUrl } from "@/sites/public/components/styles";

/** One product of the public catalog (MarketingCatalog::catalog()). */
export type CatalogEntry = {
    slug: string;
    category: "erp" | "application";
    name: string;
    icon: string;
    summary: string;
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
    status: "available" | "early_access" | "coming_soon";
    /** What an ERP is made of, from the registry. */
    includes: {
        code: string;
        name: string;
        requirement: "required" | "optional";
        listed: boolean;
    }[];
    /** Application code whose free trial this product starts; null: no trial today. */
    trial: string | null;
    /** Short points for the home page's featured products. */
    highlights?: string[];
    /** How the product runs today; only what is built is listed. */
    offering: Offering;
};

export type Platform = "web" | "desktop" | "android" | "ios";
export type Connectivity = "online" | "offline_sync" | "offline";
export type Licensing = "subscription" | "one_time" | "quotation";

export type Offering = {
    platforms: Platform[];
    connectivity: Connectivity[];
    licensing: Licensing[];
    /** What the desktop and mobile apps do. */
    desktop: string[];
    mobile: string[];
};

export const platformLabels: Record<Platform, string> = {
    web: "Web",
    desktop: "Windows desktop",
    android: "Android",
    ios: "iOS",
};

export const connectivityLabels: Record<Connectivity, string> = {
    online: "Online in the cloud",
    offline_sync: "Offline with cloud sync",
    offline: "Fully offline",
};

export const licensingLabels: Record<Licensing, string> = {
    subscription: "Subscription",
    one_time: "One-time purchase",
    quotation: "Custom quotation",
};

export const categoryLabels: Record<CatalogEntry["category"], string> = {
    erp: "ERP Solutions",
    application: "Business Applications",
};

/** Each category's section of the products page. */
export const categoryPaths: Record<CatalogEntry["category"], string> = {
    erp: "/products#erp",
    application: "/products#apps",
};

const icons: Record<string, LucideIcon> = {
    activity: Activity,
    "arrow-left-right": ArrowLeftRight,
    banknote: Banknote,
    "bar-chart": BarChart3,
    "book-open-check": BookOpenCheck,
    calculator: Calculator,
    "calendar-check": CalendarCheck,
    "chef-hat": ChefHat,
    "clipboard-check": ClipboardCheck,
    "clipboard-list": ClipboardList,
    "credit-card": CreditCard,
    "file-check": FileCheck2,
    "file-spreadsheet": FileSpreadsheet,
    "file-text": FileText,
    "folder-kanban": FolderKanban,
    "folder-lock": FolderLock,
    globe: Globe,
    "graduation-cap": GraduationCap,
    "hand-coins": HandCoins,
    "layout-grid": LayoutGrid,
    handshake: Handshake,
    "heart-handshake": HeartHandshake,
    history: History,
    landmark: Landmark,
    lock: Lock,
    "map-pin": MapPin,
    package: Package,
    "package-search": PackageSearch,
    pill: Pill,
    receipt: Receipt,
    "scan-barcode": ScanBarcode,
    "scroll-text": ScrollText,
    "shield-alert": ShieldAlert,
    "shopping-cart": ShoppingCart,
    tags: Tags,
    truck: Truck,
    "user-check": UserCheck,
    "user-plus": UserPlus,
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

export const statusLabels: Record<CatalogEntry["status"], string | null> = {
    available: "Available",
    early_access: "Early access",
    coming_soon: "Coming soon",
};

export function CatalogStatus({ status }: { status: CatalogEntry["status"] }) {
    const tone = {
        available:
            "bg-site-navy/10 text-site-navy in-[.site-dark]:bg-white/15 in-[.site-dark]:text-white",
        early_access:
            "bg-site-accent/10 text-site-accent-ink in-[.site-dark]:bg-white/15 in-[.site-dark]:text-white",
        coming_soon:
            "bg-site-soft text-slate-700 ring-1 ring-site-line in-[.site-dark]:bg-white/15 in-[.site-dark]:text-white in-[.site-dark]:ring-white/20",
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
    size = "md",
}: {
    icon: string;
    size?: "sm" | "md" | "lg";
}) {
    const Icon = catalogIcon(icon);
    const box = {
        sm: "size-9 rounded-lg",
        md: "size-12 rounded-xl",
        lg: "size-16 rounded-2xl",
    }[size];
    const glyph = { sm: "size-4", md: "size-6", lg: "size-8" }[size];

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center bg-site-accent/10 text-site-accent-ink in-[.site-dark]:bg-white/10 in-[.site-dark]:text-white ${box}`}
        >
            <Icon className={glyph} aria-hidden />
        </span>
    );
}

/**
 * A product card: what it is, where it stands, and the next step. Only a
 * product open for trials offers one; the others lead to their page or the
 * waitlist. `highlights` adds the product's main points under its summary
 * (the home page's Miles Products); `title` is the name to show (Miles
 * Pharmacy) when it is not the catalog's.
 */
export function ProductCard({
    product,
    trialDays,
    headingLevel: Heading = "h3",
    highlights = false,
    title,
}: {
    product: CatalogEntry;
    trialDays: number;
    headingLevel?: "h2" | "h3";
    highlights?: boolean;
    title?: string;
}) {
    const name = title ?? product.name;

    return (
        <article
            className={`${card} group relative flex h-full flex-col overflow-hidden p-6 sm:p-7`}
        >
            <span
                aria-hidden
                className="bg-site-accent absolute inset-x-0 top-0 h-1 origin-left scale-x-0 transition-transform duration-300 group-hover:scale-x-100"
            />
            <div className="flex items-start justify-between gap-3">
                <CatalogTile icon={product.icon} />
                <CatalogStatus status={product.status} />
            </div>
            <Heading className={`mt-5 text-xl ${display}`}>
                <Link
                    href={`/${product.url}`}
                    className="transition-colors hover:text-site-accent-ink"
                >
                    {name}
                </Link>
            </Heading>
            <p
                className={`mt-2 text-sm leading-relaxed text-slate-600 ${highlights && product.highlights ? "" : "flex-1"}`}
            >
                {product.summary}
            </p>
            {highlights && product.highlights && (
                <ul className="mt-4 flex-1 space-y-2 text-sm text-slate-700">
                    {product.highlights.map((point) => (
                        <li key={point} className="flex items-start gap-2.5">
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                aria-hidden
                            />
                            {point}
                        </li>
                    ))}
                </ul>
            )}
            <PlatformList
                platforms={product.offering.platforms}
                planned={product.status === "coming_soon"}
                className="mt-5"
            />
            <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                {product.trial ? (
                    <a
                        href={trialUrl(product.trial)}
                        className="inline-flex items-center gap-2 rounded-full bg-site-accent-ink px-4 py-2 text-sm font-bold text-white transition-[background-color,transform] hover:-translate-y-0.5 hover:bg-site-navy"
                    >
                        Start {trialDays}-day free trial
                        <span className="sr-only"> of {name}</span>
                    </a>
                ) : product.status === "coming_soon" ? (
                    <Link
                        href={waitlistUrl(product.name)}
                        className="inline-flex items-center gap-1.5 text-sm font-bold text-site-accent-ink hover:underline"
                    >
                        Join the waitlist
                        <span className="sr-only"> for {name}</span>
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                ) : (
                    <Link
                        href={`/contact?subject=${encodeURIComponent(`Demo: ${product.name}`)}`}
                        className="inline-flex items-center gap-1.5 text-sm font-bold text-site-accent-ink hover:underline"
                    >
                        Request a demo
                        <span className="sr-only"> of {name}</span>
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                )}
                <Link
                    href={`/${product.url}`}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-slate-600 hover:text-site-ink"
                >
                    Learn more
                    <span className="sr-only"> about {name}</span>
                    <ArrowUpRight className="size-4" aria-hidden />
                </Link>
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
    className = "",
}: {
    platforms: Platform[];
    planned?: boolean;
    className?: string;
}) {
    return (
        <p
            className={`flex flex-wrap items-center gap-1.5 text-xs ${className}`}
        >
            <span className="mr-0.5 font-semibold text-slate-500">
                {planned ? "Planned for" : "Runs on"}
            </span>
            {platforms.map((platform) => (
                <span
                    key={platform}
                    className="rounded-full bg-site-soft px-2.5 py-1 font-semibold text-slate-700 ring-1 ring-site-line"
                >
                    {platformLabels[platform]}
                </span>
            ))}
        </p>
    );
}
