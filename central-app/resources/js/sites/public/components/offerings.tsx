import { Link } from '@inertiajs/react';
import {
    ArrowRight,
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
import {
    card,
    display,
    panel,
    textLink,
} from '@/sites/public/components/styles';

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
        body: 'Connect the systems you already use with each other or with Miles, so information is entered once and flows where it is needed.',
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

/** The ways a Miles product can run. Not every product offers each one. */
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
            title: 'Desktop applications with cloud sync',
            tag: 'Works offline',
            points: [
                'Installed on the computer at the counter',
                'Supported work carries on without internet',
                'Data syncs when the connection is back',
                'What syncs differs from product to product',
            ],
            licence: 'Included with the cloud plan of products that have it',
            offers: (entry) =>
                entry.offering.platforms.includes('desktop') &&
                entry.offering.connectivity.includes('offline_sync'),
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
        {
            id: 'mobile',
            icon: Smartphone,
            title: 'Mobile applications',
            tag: 'Android and iOS',
            points: [
                'Business work on a phone or tablet',
                'Online or offline, depending on the product',
                'Can do less than the desktop or web version',
            ],
            licence: 'Included with the cloud plan of products that have it',
            offers: hasMobile,
        },
        {
            id: 'connected',
            icon: MonitorSmartphone,
            title: 'Connected desktop and mobile',
            tag: 'Shared data',
            points: [
                'Desktop and mobile apps for the same product',
                'One set of business data where sync is supported',
            ],
            licence: 'Included with the cloud plan of products that have it',
            offers: (entry) =>
                entry.offering.platforms.includes('desktop') &&
                hasMobile(entry),
        },
    ];
}

/**
 * The ways to run Miles software, each naming the released products that
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
        <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            {deployments(trialDays).map((option, i) => {
                const products = released.filter(option.offers);

                return (
                    <li
                        key={option.id}
                        id={option.id}
                        className={`${panel} flex scroll-mt-28 flex-col p-7`}
                    >
                        <div className="flex items-start justify-between gap-4">
                            <IconBox icon={option.icon} index={i} />
                            <span className="rounded-full bg-site-wash px-3 py-1 text-xs font-bold text-site-accent-ink">
                                {option.tag}
                            </span>
                        </div>
                        <h3 className={`mt-6 text-xl ${display}`}>
                            {option.title}
                        </h3>
                        <ul className="mt-4 space-y-2.5 text-sm text-slate-700">
                            {option.points.map((point) => (
                                <li
                                    key={point}
                                    className="flex items-start gap-2.5"
                                >
                                    <Check
                                        className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                        aria-hidden
                                    />
                                    {point}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-auto space-y-2 pt-6 text-sm">
                            <p className="border-t border-site-line pt-5 font-semibold text-site-ink">
                                {option.licence}
                            </p>
                            <p className="text-slate-600">
                                {products.length > 0 ? (
                                    <>
                                        Available for{' '}
                                        {products.map((entry, j) => (
                                            <span key={entry.slug}>
                                                {j > 0 &&
                                                    (j === products.length - 1
                                                        ? ' and '
                                                        : ', ')}
                                                <Link
                                                    href={`/${entry.url}`}
                                                    className={textLink}
                                                >
                                                    {entry.name}
                                                </Link>
                                            </span>
                                        ))}
                                    </>
                                ) : (
                                    'Not offered by a released product yet.'
                                )}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/** Custom development, as link cards. */
export function ServiceCards() {
    return (
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {services.map((service, i) => (
                <li key={service.id}>
                    <Link
                        href={`/services#${service.id}`}
                        className={`${card} group flex h-full flex-col p-7`}
                    >
                        <IconBox icon={service.icon} index={i} />
                        <span
                            className={`mt-6 text-xl transition-colors group-hover:text-site-accent-ink ${display}`}
                        >
                            {service.title}
                        </span>
                        <span className="mt-2 block flex-1 leading-relaxed text-slate-600">
                            {service.body}
                        </span>
                        <span className="mt-5 inline-flex items-center gap-2 text-sm font-bold text-site-accent-ink">
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
        </ul>
    );
}
