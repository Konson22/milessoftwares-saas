import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Boxes,
    Cable,
    Check,
    Cloud,
    Globe,
    HardDrive,
    Monitor,
    MonitorSmartphone,
    RefreshCw,
    Settings2,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import { IconBox } from '@/sites/public/components/page-hero';
import { display } from '@/sites/public/components/styles';

/** A request through the contact form, subject filled in. */
export function quoteUrl(subject: string): string {
    return `/contact?subject=${encodeURIComponent(subject)}`;
}

/** The demo request every "Request a Demo" button leads to. */
export const demoUrl = quoteUrl('Request a demo');

export type Service = {
    id: string;
    icon: LucideIcon;
    title: string;
    body: string;
    href: string;
    cta: string;
    points: string[];
};

/**
 * Custom development: software we build or adapt for one customer, apart
 * from the ready-made products in the catalog.
 */
export const services: Service[] = [
    {
        id: 'custom-erp',
        icon: Boxes,
        title: 'Custom ERP Development',
        body: 'A new ERP designed around your departments, approvals, documents and reports, built on the platform our own ERPs run on.',
        href: quoteUrl('Project: Custom ERP'),
        cta: 'Discuss your project',
        points: [
            'Your workflows, forms and reports',
            'Built on modules already in use',
            'Data migration, training and support',
        ],
    },
    {
        id: 'web-applications',
        icon: Globe,
        title: 'Web Application Development',
        body: 'Business portals, internal systems, company websites and online shops that work on any screen and are easy to keep up to date.',
        href: quoteUrl('Project: Web application'),
        cta: 'Discuss your project',
        points: [
            'Portals and internal business systems',
            'Company websites and online shops',
            'Domain, hosting and email set up for you',
        ],
    },
    {
        id: 'desktop-applications',
        icon: Monitor,
        title: 'Desktop Application Development',
        body: 'Installed applications for the counter, the office or the warehouse, built to keep working when the internet goes down.',
        href: quoteUrl('Project: Desktop application'),
        cta: 'Discuss your project',
        points: [
            'Works offline, syncs when back online',
            'Barcode scanners and receipt printers',
            'Installers and updates handled for you',
        ],
    },
    {
        id: 'mobile-applications',
        icon: Smartphone,
        title: 'Mobile Application Development',
        body: 'Android and iOS apps for your customers or your staff, from sales in the field to stock counts in the store room.',
        href: quoteUrl('Project: Mobile application'),
        cta: 'Discuss your project',
        points: [
            'Android and iOS from one code base',
            'Offline first, synced with your system',
            'Prepared for Google Play and the App Store',
        ],
    },
    {
        id: 'system-integration',
        icon: Cable,
        title: 'System Integration',
        body: 'Connect the systems you already use with each other or with Tatook, so information is entered once and flows where it is needed.',
        href: quoteUrl('Project: System integration'),
        cta: 'Discuss your project',
        points: [
            'APIs between your systems',
            'Data imports from spreadsheets or older systems',
            'Scheduled exports and shared reports',
        ],
    },
    {
        id: 'erp-customization',
        icon: Settings2,
        title: 'ERP Customization',
        body: 'Adapt a Tatook ERP you already use or are about to buy: extra fields, documents, reports, print layouts and approval steps.',
        href: quoteUrl('Quote: ERP customization'),
        cta: 'Request a quotation',
        points: [
            'Your product licence, plus a setup or customization charge',
            'Reports, documents and print layouts',
            'Approval steps and roles',
        ],
    },
];

export type Deployment = {
    id: string;
    icon: LucideIcon;
    title: string;
    tag: string;
    points: string[];
    licence: string;
    /** Which released products offer it. */
    offers: (entry: CatalogEntry) => boolean;
};

const hasMobile = (entry: CatalogEntry) =>
    entry.offering.platforms.some(
        (platform) => platform === 'android' || platform === 'ios',
    );

/** The ways a Tatook product can run. Not every product offers each one. */
export function deployments(trialDays: number): Deployment[] {
    return [
        {
            id: 'cloud',
            icon: Cloud,
            title: 'Cloud-based web applications',
            tag: 'In the browser',
            points: [
                'Use it in the browser on a computer, tablet or phone',
                'Hosted for you, with backups and updates',
                'Needs an internet connection',
            ],
            licence: `Subscription; ${trialDays}-day free trial where offered`,
            offers: (entry) => entry.offering.platforms.includes('web'),
        },
        {
            id: 'synced',
            icon: RefreshCw,
            title: 'Desktop and mobile apps with cloud sync',
            tag: 'Works offline',
            points: [
                'On the counter computer, a phone or a tablet',
                'Supported work carries on without internet',
                'Syncs to the same business data when back online',
                'What syncs, and what the mobile app can do, differs by product',
            ],
            licence: 'Included with the cloud plan of products that have it',
            offers: (entry) =>
                hasMobile(entry) ||
                (entry.offering.platforms.includes('desktop') &&
                    entry.offering.connectivity.includes('offline_sync')),
        },
        {
            id: 'offline',
            icon: HardDrive,
            title: 'Standalone offline desktop applications',
            tag: 'One-time licence',
            points: [
                'Installed on one computer, with its own database',
                'Core work runs with no internet at all',
                'Cloud sync is not included',
            ],
            licence: 'One-time purchase; ask us for the price',
            offers: (entry) => entry.offering.connectivity.includes('offline'),
        },
    ];
}

/**
 * The ways to run Tatook software, each naming the released products that
 * offer it, so no option reads as available for everything.
 */
export function DeploymentOptions({
    catalog,
    trialDays,
}: {
    catalog: CatalogEntry[];
    trialDays: number;
}) {
    const released = catalog.filter((entry) => entry.status !== 'coming_soon');

    return (
        <ul className="grid gap-5 lg:grid-cols-3">
            {deployments(trialDays).map((option) => {
                const products = released.filter(option.offers);
                const Icon = option.icon;

                return (
                    <li
                        key={option.id}
                        id={option.id}
                        className="site-dark group relative isolate flex scroll-mt-28 flex-col overflow-hidden rounded-3xl bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16] shadow-lg ring-1 shadow-site-night/15 ring-white/10 transition-[box-shadow,transform] duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-site-accent/25"
                    >
                        {/* Faint dots and a large faded icon behind the top of the card. */}
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(#a3e63522_1px,transparent_1px)] mask-[linear-gradient(black,transparent_70%)] bg-size-[18px_18px]"
                        />
                        <Icon
                            aria-hidden
                            strokeWidth={1.25}
                            className="pointer-events-none absolute -top-6 -right-6 -z-10 size-40 text-site-highlight/10 transition-transform duration-500 group-hover:scale-110 group-hover:-rotate-6"
                        />
                        <div className="flex flex-1 flex-col p-6 sm:p-7">
                            <div className="flex items-start justify-between gap-4">
                                <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-site-highlight/15 text-site-highlight ring-1 ring-site-highlight/30">
                                    <Icon className="size-5" aria-hidden />
                                </span>
                                <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold text-white ring-1 ring-white/20">
                                    {option.tag}
                                </span>
                            </div>
                            <h3 className="mt-6 text-xl font-semibold tracking-tight text-balance text-white">
                                {option.title}
                            </h3>
                            <ul className="mt-4 space-y-3 text-sm text-slate-200">
                                {option.points.map((point) => (
                                    <li
                                        key={point}
                                        className="flex items-start gap-3"
                                    >
                                        <span className="mt-px inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-site-highlight text-site-night">
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

                        {/* The foot: a lime and a white wave into a white panel. */}
                        <div className="relative mt-4">
                            <svg
                                aria-hidden
                                viewBox="0 0 400 48"
                                preserveAspectRatio="none"
                                className="pointer-events-none absolute inset-x-0 bottom-[calc(100%-1px)] block h-10 w-full overflow-visible"
                            >
                                <path
                                    d="M0 22 C 80 0, 160 40, 250 16 S 360 4, 400 14 V40 H0 Z"
                                    className="fill-site-highlight/70 transition-transform duration-700 group-hover:translate-x-[-6px]"
                                />
                                <path
                                    d="M0 34 C 90 14, 180 50, 270 28 S 370 18, 400 26 V56 H0 Z"
                                    fill="#ffffff"
                                />
                            </svg>
                            <div className="space-y-4 bg-white px-6 pt-3 pb-6 text-sm sm:px-7 sm:pb-7 lg:min-h-[9.5rem]">
                                <div className="flex items-start gap-2.5 text-site-ink">
                                    <BadgeCheck
                                        className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                        aria-hidden
                                    />
                                    <p className="font-semibold">
                                        {option.licence}
                                    </p>
                                </div>
                                {products.length > 0 ? (
                                    <div className="border-t border-site-line pt-4">
                                        <p className="text-slate-600">
                                            Available for
                                        </p>
                                        <ul className="mt-2 flex flex-wrap gap-2">
                                            {products.map((entry) => (
                                                <li key={entry.slug}>
                                                    <Link
                                                        href={`/${entry.url}`}
                                                        className="inline-flex items-center gap-1 rounded-full bg-site-accent-ink px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-site-accent-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-site-accent-ink"
                                                    >
                                                        {entry.name}
                                                        <ArrowRight
                                                            className="size-3"
                                                            aria-hidden
                                                        />
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : (
                                    <p className="border-t border-site-line pt-4 text-slate-600">
                                        Not offered by a released product yet.
                                    </p>
                                )}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/** Custom development, as link cards. */
/**
 * The services in three cards for the homepage: the six on the Services
 * page, grouped by what the customer is after.
 */
const serviceGroups: Omit<Service, 'href' | 'cta'>[] = [
    {
        id: 'custom-erp',
        icon: Boxes,
        title: 'Custom ERP and integrations',
        body: 'A system designed around your departments, approvals and reports, connected to the software you already use.',
        points: [
            'Built on modules already in use',
            'APIs and data imports from older systems',
            'Migration, training and support',
        ],
    },
    {
        id: 'web-applications',
        icon: MonitorSmartphone,
        title: 'Web, desktop and mobile apps',
        body: 'Portals, websites and online shops; counter apps that keep working offline; Android and iOS apps for staff or customers.',
        points: [
            'Any screen, one code base where it fits',
            'Offline first, synced with your system',
            'Hosting, installers and app stores handled',
        ],
    },
    {
        id: 'erp-customization',
        icon: Settings2,
        title: 'Customize a Tatook product',
        body: 'Start from one of our ERPs and have it adapted: extra fields, documents, print layouts, reports and approval steps.',
        points: [
            'Your product licence plus a setup charge',
            'Reports, documents and print layouts',
            'Approval steps and roles',
        ],
    },
];

/**
 * The custom-work services as a numbered, divided list (no cards, no
 * surface): each row links to its section on the Services page.
 */
export function ServiceList() {
    return (
        <ol className="divide-y divide-site-line border-y border-site-line">
            {serviceGroups.map((service, i) => (
                <li key={service.id}>
                    <Link
                        href={`/services#${service.id}`}
                        className="group grid gap-x-6 gap-y-4 py-8 sm:grid-cols-[auto_1fr_auto] sm:items-start"
                    >
                        <span className="flex items-center gap-4">
                            <span className="font-public-display text-sm font-semibold text-site-accent-ink tabular-nums">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <IconBox icon={service.icon} index={i} />
                        </span>
                        <span className="block">
                            <span
                                className={`block text-xl transition-colors group-hover:text-site-accent-ink ${display}`}
                            >
                                {service.title}
                            </span>
                            <span className="mt-2 block max-w-xl leading-relaxed text-slate-600">
                                {service.body}
                            </span>
                            <span className="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                                {service.points.map((point) => (
                                    <span
                                        key={point}
                                        className="inline-flex items-center gap-2 text-sm text-slate-700"
                                    >
                                        <Check
                                            className="size-4 shrink-0 text-site-accent-ink"
                                            aria-hidden
                                        />
                                        {point}
                                    </span>
                                ))}
                            </span>
                        </span>
                        <span className="inline-flex items-center gap-2 text-sm font-bold text-site-accent-ink sm:mt-1">
                            Learn more
                            <span className="sr-only">
                                {' '}
                                about {service.title}
                            </span>
                            <ArrowRight
                                className="size-4 transition-transform group-hover:translate-x-1"
                                aria-hidden
                            />
                        </span>
                    </Link>
                </li>
            ))}
        </ol>
    );
}
