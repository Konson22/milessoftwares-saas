import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Boxes,
    Check,
    Cloud,
    Globe,
    HardDrive,
    Layers,
    Monitor,
    RefreshCw,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { IconBox } from '@/sites/public/components/page-hero';
import { button, card, display, panel } from '@/sites/public/components/styles';

/** A request through the contact form, subject filled in. */
export function quoteUrl(subject: string): string {
    return `/contact?subject=${encodeURIComponent(subject)}`;
}

export type Service = {
    id: string;
    icon: LucideIcon;
    title: string;
    body: string;
    href: string;
    cta: string;
    points: string[];
};

/** What we make: our ready-made ERPs, custom systems, websites and apps. */
export const services: Service[] = [
    {
        id: 'ready-made-erp',
        icon: Layers,
        title: 'Ready-made ERP',
        body: 'Complete systems for pharmacies, shops, schools, logistics and NGOs, ready to use today, with Finance & Accounting built in.',
        href: '/erp',
        cta: 'Explore ERP Solutions',
        points: [
            'Stock, sales, purchasing and reports',
            'Finance & Accounting included',
            'Free trial on released products',
        ],
    },
    {
        id: 'custom-erp',
        icon: Boxes,
        title: 'Custom ERP',
        body: 'An ERP shaped around how your organization works: your departments, approvals, documents and reports, built on our proven platform.',
        href: quoteUrl('Quote: Custom ERP'),
        cta: 'Request a quote',
        points: [
            'Your workflows, forms and reports',
            'Built on modules already in use',
            'Training, data migration and support',
        ],
    },
    {
        id: 'websites',
        icon: Globe,
        title: 'Websites',
        body: 'Fast, mobile-friendly websites for businesses, schools and organizations, built to be found on Google and easy to keep up to date.',
        href: quoteUrl('Quote: Website'),
        cta: 'Request a quote',
        points: [
            'Company sites, online shops and portals',
            'Search-engine ready from day one',
            'Domain, hosting and email set up for you',
        ],
    },
    {
        id: 'desktop-apps',
        icon: Monitor,
        title: 'Desktop apps',
        body: 'Windows, macOS and Linux applications that keep working when the internet goes down, for the counter, the office or the warehouse.',
        href: quoteUrl('Quote: Desktop app'),
        cta: 'Request a quote',
        points: [
            'Works offline, syncs when back online',
            'Barcode scanners and receipt printers',
            'Installers and updates handled for you',
        ],
    },
    {
        id: 'mobile-apps',
        icon: Smartphone,
        title: 'Mobile apps',
        body: 'Android and iOS apps for your customers or your staff, from sales in the field to stock counts in the store room.',
        href: quoteUrl('Quote: Mobile app'),
        cta: 'Request a quote',
        points: [
            'Android and iOS from one build',
            'Offline first, synced with your system',
            'Published to Google Play and the App Store',
        ],
    },
];

export type Deployment = {
    id: string;
    icon: LucideIcon;
    title: string;
    tag: string;
    body: string;
    points: string[];
    price: string;
    href: string;
    cta: string;
};

/** The three ways to run any of our ERPs. */
export function deployments(trialDays: number): Deployment[] {
    return [
        {
            id: 'cloud',
            icon: Cloud,
            title: 'Cloud',
            tag: 'In the browser',
            body: 'Use your ERP from any computer, tablet or phone, with every branch on one set of books.',
            points: [
                'Nothing to install',
                'All branches and staff together',
                'Backups and updates included',
            ],
            price: `Monthly or yearly plan, ${trialDays}-day free trial`,
            href: '/start',
            cta: 'Start free trial',
        },
        {
            id: 'synced',
            icon: RefreshCw,
            title: 'Desktop & mobile, synced',
            tag: 'Works offline',
            body: 'Desktop and mobile apps that keep selling without internet and send everything to the cloud when the connection is back.',
            points: [
                'Keeps working when the internet stops',
                'Syncs automatically with every branch',
                'Windows, macOS, Linux, Android and iOS',
            ],
            price: 'Included with your cloud plan',
            href: '/pricing',
            cta: 'See plans',
        },
        {
            id: 'offline',
            icon: HardDrive,
            title: 'Offline, single branch',
            tag: 'One-time purchase',
            body: 'The full ERP for one branch on one computer or device, with its own database. No internet, server or subscription needed.',
            points: [
                'Pay once, use it for good',
                'No internet or monthly fees',
                'Desktop or mobile',
            ],
            price: 'One-time purchase, contact us for the price',
            href: quoteUrl('Price: Offline single-branch ERP'),
            cta: 'Ask for the price',
        },
    ];
}

/** The three ways to run an ERP, as cards side by side. */
export function DeploymentOptions({ trialDays }: { trialDays: number }) {
    return (
        <ul className="grid gap-5 lg:grid-cols-3">
            {deployments(trialDays).map((option, i) => (
                <li
                    key={option.id}
                    id={option.id}
                    className={`${panel} flex scroll-mt-28 flex-col p-7 sm:p-8`}
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
                    <p className="mt-2 leading-relaxed text-slate-600">
                        {option.body}
                    </p>
                    <ul className="mt-5 space-y-2.5 text-sm text-slate-700">
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
                    <div className="mt-auto pt-7">
                        <p className="border-t border-site-line pt-5 text-sm font-semibold text-site-ink">
                            {option.price}
                        </p>
                        <Link
                            href={option.href}
                            className={`mt-4 w-full ${i === 0 ? button.primary : button.outline}`}
                        >
                            {option.cta}
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** What we make, as link cards. */
export function ServiceCards() {
    return (
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {services.map((service, i) => (
                <li key={service.id}>
                    <Link
                        href={service.href}
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
                            {service.cta}
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
