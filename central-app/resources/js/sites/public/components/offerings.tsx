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
import type { CSSProperties } from 'react';
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
        body: 'Connect the systems you already use with each other or with Miles Softwares, so information is entered once and flows where it is needed.',
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
        body: 'Adapt a Miles ERP you already use or are about to buy: extra fields, documents, reports, print layouts and approval steps.',
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
    /** The short word on the card's seal. */
    badge: string;
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

/** The ways a Miles Softwares product can run. Not every product offers each one. */
export function deployments(trialDays: number): Deployment[] {
    return [
        {
            id: 'cloud',
            icon: Cloud,
            badge: 'Cloud',
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
            badge: 'Synced',
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
            badge: 'Offline',
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

/** A scalloped edge for a seal: `points` small bumps around a circle. */
function sealPath(points: number, outer: number, inner: number): string {
    const steps = points * 2;
    const coords = Array.from({ length: steps }, (_, i) => {
        const angle = (Math.PI * 2 * i) / steps - Math.PI / 2;
        const r = i % 2 === 0 ? outer : inner;
        return `${(100 + r * Math.cos(angle)).toFixed(2)} ${(100 + r * Math.sin(angle)).toFixed(2)}`;
    });
    return `M${coords.join('L')}Z`;
}

const seal = sealPath(28, 98, 91);

/**
 * The badge at the top of a deployment card: a scalloped navy seal with a
 * dotted inner ring, the icon and a short word, a green ribbon across it and
 * two tails below.
 */
function DeploymentSeal({ option }: { option: Deployment }) {
    const Icon = option.icon;

    return (
        <div className="relative mx-auto size-44 transition-transform duration-500 group-hover:-rotate-3 group-hover:scale-105">
            {/* Tails, behind the seal. */}
            <span
                aria-hidden
                className="absolute bottom-0 left-1/2 -z-10 h-16 w-10 -translate-x-[95%] translate-y-6 rotate-[18deg] bg-site-accent-deep [clip-path:polygon(0_0,100%_0,100%_100%,50%_78%,0_100%)]"
            />
            <span
                aria-hidden
                className="absolute bottom-0 left-1/2 -z-10 h-16 w-10 -translate-x-[5%] translate-y-6 -rotate-[18deg] bg-site-accent-deep [clip-path:polygon(0_0,100%_0,100%_100%,50%_78%,0_100%)]"
            />

            <svg
                aria-hidden
                viewBox="0 0 200 200"
                className="absolute inset-0 size-full"
            >
                <path
                    d={seal}
                    fill="#001b48"
                    stroke="#01c5fd"
                    strokeWidth="3"
                    strokeLinejoin="round"
                />
                <circle
                    cx="100"
                    cy="100"
                    r="78"
                    fill="none"
                    stroke="#0a64c4"
                    strokeWidth="2"
                    strokeDasharray="1 6"
                    strokeLinecap="round"
                />
                <circle
                    cx="100"
                    cy="100"
                    r="70"
                    fill="none"
                    stroke="#0a2d66"
                    strokeWidth="1"
                />
            </svg>

            <div className="absolute inset-x-0 top-[22%] flex flex-col items-center text-center">
                <Icon
                    className="size-7 text-site-highlight"
                    strokeWidth={1.75}
                    aria-hidden
                />
                <span className="mt-1.5 font-public-display text-[1.65rem] leading-none font-extrabold tracking-tight text-white uppercase">
                    {option.badge}
                </span>
            </div>

            {/* The ribbon across the lower half, wider than the seal. */}
            <div className="absolute inset-x-[-14%] top-[60%]">
                <span
                    aria-hidden
                    className="absolute top-2 -left-3 -z-10 h-full w-8 bg-site-leaf [clip-path:polygon(0_0,100%_0,100%_100%,0_100%,35%_50%)]"
                />
                <span
                    aria-hidden
                    className="absolute top-2 -right-3 -z-10 h-full w-8 bg-site-leaf [clip-path:polygon(0_0,100%_0,65%_50%,100%_100%,0_100%)]"
                />
                <span className="relative block bg-site-highlight px-3 py-1.5 text-center text-xs font-extrabold tracking-[0.12em] text-site-night uppercase shadow-md shadow-site-night/20">
                    {option.tag}
                </span>
            </div>
        </div>
    );
}

/**
 * The ways to run Miles Softwares software, each naming the released products that
 * offer it, so no option reads as available for everything. Each card leads
 * with a seal-and-ribbon badge.
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
        <ul className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {deployments(trialDays).map((option) => {
                const products = released.filter(option.offers);
                const Icon = option.icon;

                return (
                    <li
                        key={option.id}
                        id={option.id}
                        className="group relative isolate flex scroll-mt-28 flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-site-line transition-[box-shadow,transform] duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-site-night/10"
                    >
                        {/* A faint watermark of the option's icon in the lower corner. */}
                        <Icon
                            aria-hidden
                            strokeWidth={1}
                            className="pointer-events-none absolute -right-8 -bottom-8 -z-10 size-48 text-site-tint transition-transform duration-700 group-hover:-rotate-12"
                        />

                        {/* The badge on a deep blue stage. */}
                        <div className="relative isolate overflow-hidden bg-site-accent-deep px-6 pt-8 pb-14">
                            <DeploymentSeal option={option} />

                            {/* A soft wave into the card body. */}
                            <svg
                                aria-hidden
                                viewBox="0 0 400 40"
                                preserveAspectRatio="none"
                                className="pointer-events-none absolute inset-x-0 -bottom-px h-8 w-full"
                            >
                                <path
                                    d="M0 18 C 90 2, 170 34, 260 16 S 370 6, 400 12 V40 H0 Z"
                                    fill="#0164b2"
                                />
                                <path
                                    d="M0 28 C 100 12, 190 42, 280 24 S 370 16, 400 22 V40 H0 Z"
                                    fill="#ffffff"
                                />
                            </svg>
                        </div>

                        <div className="flex flex-1 flex-col p-6 sm:p-7">
                            <h3
                                className={`text-center text-xl text-balance ${display}`}
                            >
                                {option.title}
                            </h3>
                            <ul className="mt-5 space-y-3 text-sm text-slate-700">
                                {option.points.map((point) => (
                                    <li
                                        key={point}
                                        className="flex items-start gap-3"
                                    >
                                        <span className="mt-px inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-site-wash text-site-accent-ink ring-1 ring-site-line">
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

                            {/* Coupon-style foot: a dashed tear line with notches. */}
                            <div className="relative mt-auto pt-7">
                                <div className="relative -mx-6 border-t-2 border-dashed border-site-line sm:-mx-7">
                                    <span
                                        aria-hidden
                                        className="absolute top-1/2 -left-3 size-6 -translate-y-1/2 rounded-full bg-site-wash ring-1 ring-site-line"
                                    />
                                    <span
                                        aria-hidden
                                        className="absolute top-1/2 -right-3 size-6 -translate-y-1/2 rounded-full bg-site-wash ring-1 ring-site-line"
                                    />
                                </div>
                                <div className="mt-5 flex items-start gap-2.5 text-sm text-site-ink">
                                    <BadgeCheck
                                        className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                        aria-hidden
                                    />
                                    <p className="font-semibold">
                                        {option.licence}
                                    </p>
                                </div>
                                {products.length > 0 ? (
                                    <div className="mt-4 text-sm">
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
                                    <p className="mt-4 text-sm text-slate-600">
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
        title: 'Customize a Miles Softwares product',
        body: 'Start from one of our ERPs and have it adapted: extra fields, documents, print layouts, reports and approval steps.',
        points: [
            'Your product licence plus a setup charge',
            'Reports, documents and print layouts',
            'Approval steps and roles',
        ],
    },
];

/**
 * One colour per banner, all from the logo's blues: the fill, the
 * text on that fill, the colour for headings on white and a lighter
 * shade of the fill for the header's circle.
 */
const bannerThemes: {
    fill: string;
    onFill: string;
    ink: string;
    soft: string;
}[] = [
    { fill: '#0353ff', onFill: '#ffffff', ink: '#0353ff', soft: '#1f68ff' },
    { fill: '#001b48', onFill: '#ffffff', ink: '#001b48', soft: '#0a2d66' },
    { fill: '#01c5fd', onFill: '#00122f', ink: '#006a8e', soft: '#3dd3fd' },
];

/**
 * The custom-work services as three roll-up banner stands: a top rail, a
 * tall banner with a curved coloured header and a round icon frame, the
 * points, a coloured foot with the link, and the stand's base. Each banner
 * links to its section on the Services page.
 */
export function ServiceCards() {
    return (
        <ol className="grid gap-x-8 gap-y-12 md:grid-cols-2 lg:grid-cols-3">
            {serviceGroups.map((service, i) => {
                const theme = bannerThemes[i % bannerThemes.length];
                const Icon = service.icon;

                return (
                    <li
                        key={service.id}
                        className="group flex flex-col items-center"
                        style={
                            {
                                '--banner': theme.fill,
                                '--banner-on': theme.onFill,
                                '--banner-ink': theme.ink,
                                '--banner-soft': theme.soft,
                            } as CSSProperties
                        }
                    >
                        {/* The top rail the banner hangs from. */}
                        <span
                            aria-hidden
                            className="relative z-10 h-3 w-[calc(100%+0.75rem)] rounded-full bg-linear-to-b from-slate-300 via-slate-500 to-slate-700 shadow-md"
                        />

                        <Link
                            href={`/services#${service.id}`}
                            className="relative isolate -mt-1.5 flex w-full flex-1 flex-col overflow-hidden bg-white shadow-xl ring-1 shadow-site-night/15 ring-site-line transition-transform duration-300 group-hover:-translate-y-1 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--banner)"
                        >
                            {/* Header: the banner colour with stripes, a big number and a curved foot. */}
                            <span className="relative block h-44 bg-(--banner) text-(--banner-on)">
                                <span
                                    aria-hidden
                                    className="absolute -top-16 -right-16 size-48 rounded-full bg-(--banner-soft)"
                                />
                                <span
                                    aria-hidden
                                    className="absolute top-5 left-6 font-public-display text-6xl leading-none font-extrabold text-(--banner-soft) tabular-nums"
                                >
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                <svg
                                    aria-hidden
                                    viewBox="0 0 400 60"
                                    preserveAspectRatio="none"
                                    className="absolute inset-x-0 -bottom-px block h-14 w-full"
                                >
                                    <path
                                        d="M0 30 Q 200 -10 400 30 V60 H0 Z"
                                        fill="var(--banner-soft)"
                                    />
                                    <path
                                        d="M0 44 Q 200 4 400 44 V60 H0 Z"
                                        fill="#ffffff"
                                    />
                                </svg>
                                {/* The round frame where a photo would sit on a printed banner. */}
                                <span className="absolute -bottom-10 left-1/2 inline-flex size-28 -translate-x-1/2 items-center justify-center rounded-full bg-white shadow-lg ring-[6px] shadow-site-night/20 ring-(--banner)/25 transition-transform duration-500 group-hover:scale-105 group-hover:rotate-6">
                                    <span className="inline-flex size-20 items-center justify-center rounded-full bg-(--banner) text-(--banner-on)">
                                        <Icon
                                            className="size-9"
                                            strokeWidth={1.75}
                                            aria-hidden
                                        />
                                    </span>
                                </span>
                            </span>

                            <span className="flex flex-1 flex-col px-6 pt-16 pb-7 sm:px-7">
                                <span
                                    className={`block text-center font-public-display text-2xl text-balance text-(--banner-ink)! ${display}`}
                                >
                                    {service.title}
                                </span>
                                <span
                                    aria-hidden
                                    className="mx-auto mt-3 block h-1 w-12 rounded-full bg-(--banner)"
                                />
                                <span className="mt-4 block text-center text-sm leading-relaxed text-slate-600">
                                    {service.body}
                                </span>
                                <span className="mt-6 flex flex-col divide-y divide-site-line rounded-xl bg-site-wash px-4 ring-1 ring-site-line">
                                    {service.points.map((point) => (
                                        <span
                                            key={point}
                                            className="flex items-center gap-3 py-3 text-sm font-medium text-slate-700"
                                        >
                                            <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-(--banner) text-(--banner-on) shadow-sm">
                                                <Check
                                                    className="size-3.5"
                                                    strokeWidth={3}
                                                    aria-hidden
                                                />
                                            </span>
                                            {point}
                                        </span>
                                    ))}
                                </span>
                            </span>

                            {/* Foot band: the link, with a lighter slanted strip. */}
                            <span className="relative flex items-center justify-between gap-3 overflow-hidden bg-(--banner) px-6 py-4 text-(--banner-on) sm:px-7">
                                <span
                                    aria-hidden
                                    className="absolute inset-y-0 right-0 w-2/5 bg-site-navy-light [clip-path:polygon(22%_0,100%_0,100%_100%,0_100%)]"
                                />
                                <span className="relative text-sm font-bold">
                                    Learn more
                                    <span className="sr-only">
                                        {' '}
                                        about {service.title}
                                    </span>
                                </span>
                                <span className="relative inline-flex size-9 items-center justify-center rounded-full bg-white text-(--banner-ink) shadow-md">
                                    <ArrowRight
                                        className="size-4 transition-transform group-hover:translate-x-0.5"
                                        aria-hidden
                                    />
                                </span>
                            </span>
                        </Link>

                        {/* The stand's base and its shadow on the floor. */}
                        <span
                            aria-hidden
                            className="relative z-10 h-4 w-[calc(100%+1.5rem)] rounded-t-md rounded-b-2xl bg-linear-to-b from-slate-400 via-slate-600 to-slate-800 shadow-lg"
                        />
                        <span
                            aria-hidden
                            className="mt-1 h-3 w-[90%] rounded-[50%] bg-slate-200"
                        />
                    </li>
                );
            })}
        </ol>
    );
}
