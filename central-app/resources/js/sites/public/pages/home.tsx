import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Calculator,
    ClipboardList,
    CalendarDays,
    Cloud,
    Code2,
    FileText,
    FolderOpen,
    Layers,
    LayoutGrid,
    MessagesSquare,
    Monitor,
    MonitorSmartphone,
    Package,
    PackageCheck,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CtaBand, FeatureGrid } from '@/sites/public/components/blocks';
import { CompactCard, catalogIcon } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import { cloudPillars, howItWorks } from '@/sites/public/components/content';
import FaqList, { faq } from '@/sites/public/components/faq';
import WhyMiles from '@/sites/public/components/why-miles';
import {
    DeploymentOptions,
    ServiceCards,
    demoUrl,
    quoteUrl,
} from '@/sites/public/components/offerings';
import { Accent } from '@/sites/public/components/page-hero';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, {
    organization,
    siteName,
    softwareApplication,
} from '@/sites/public/components/seo';
import {
    button,
    card,
    container,
    display,
    panel,
    section,
    textLink,
    type,
} from '@/sites/public/components/styles';

type Props = {
    catalog: CatalogEntry[];
    /** Registry applications (sent by the server; the hero no longer draws them). */
    products: Product[];
    trialDays: number;
    canonical: string;
};

/** How a custom project runs, in three plain steps (no links). */
const customSteps: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: MessagesSquare,
        title: 'Scoped with you',
        body: 'We map your workflows, approvals and reports first.',
    },
    {
        icon: FileText,
        title: 'Quoted up front',
        body: 'A fixed scope and price before any work starts.',
    },
    {
        icon: PackageCheck,
        title: 'Delivered and supported',
        body: 'Set up, your team trained, and support after launch.',
    },
];

/**
 * The business applications in the suite, each linking to its page. `slug`
 * names the catalog entry whose real release status is shown, so an
 * application that is not released yet says so.
 */
const suite: {
    icon: LucideIcon;
    title: string;
    body: string;
    href: string;
    slug: string;
}[] = [
    {
        icon: Calculator,
        title: 'Finance & Accounting',
        body: 'Ledger, invoices, bills, budgets and financial reports.',
        href: '/apps/finance',
        slug: 'finance',
    },
    {
        icon: Package,
        title: 'Inventory Management',
        body: 'Track stock, warehouses and supply across branches.',
        href: '/apps/inventory',
        slug: 'inventory',
    },
    {
        icon: ShoppingCart,
        title: 'Sales & POS',
        body: 'Fast, reliable sales and point-of-sale operations.',
        href: '/erp/retail',
        slug: 'retail',
    },
    {
        icon: Users,
        title: 'HR & Payroll',
        body: 'Staff records, contracts, payroll and payslips.',
        href: '/apps/hr',
        slug: 'hr',
    },
    {
        icon: FolderOpen,
        title: 'Projects & M&E',
        body: 'Plan, track, monitor and evaluate projects and results.',
        href: '/apps/projects',
        slug: 'projects',
    },
    {
        icon: ClipboardList,
        title: 'Procurement',
        body: 'Requests, quotations, purchase orders and approvals.',
        href: '/apps/procurement',
        slug: 'procurement',
    },
];

/** The platform's points, in the strip under the hero. */
const heroPoints: { icon: LucideIcon; label: string }[] = [
    { icon: Cloud, label: 'All-in-One Platform' },
    { icon: Layers, label: 'Multiple Applications' },
    { icon: Users, label: 'For Businesses, NGOs & Government' },
    { icon: ShieldCheck, label: 'Secure & Reliable' },
    { icon: MonitorSmartphone, label: 'Web, Desktop & Mobile' },
];

/** Two digits, as numbered steps read: 01, 02, … */
function ordinal(i: number): string {
    return String(i + 1).padStart(2, '0');
}

export default function MarketingHome({
    catalog,
    trialDays,
    canonical,
}: Props) {
    const origin = new URL(canonical).origin;
    const statusOf = new Map(
        catalog.map((entry) => [entry.slug, entry.status]),
    );
    // The ERP band: released ERPs first, then early access, then coming soon.
    const rank = { available: 0, early_access: 1, coming_soon: 2 } as const;
    const erps = catalog
        .filter((entry) => entry.category === 'erp')
        .sort((x, y) => rank[x.status] - rank[y.status]);

    return (
        <>
            <Seo
                // Brand first, then what it is, so "Miles Global Softwares" is read as ERP
                // and business software.
                title="Miles Global Softwares | ERP for Pharmacy, Retail, Finance & HR"
                description="Miles Global Softwares: ERP and business software by Miles Global Link: pharmacy, retail, restaurant and school ERPs with accounting, HR and payroll in one account."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@graph': [
                        organization(origin),
                        softwareApplication(origin),
                        {
                            '@type': 'WebSite',
                            '@id': `${origin}/#website`,
                            name: siteName,
                            alternateName: 'Miles ERP',
                            url: `${origin}/`,
                            publisher: { '@id': `${origin}/#organization` },
                        },
                    ],
                }}
            />

            {/*
              The dark green hero: the copy and actions on the left, the
              laptop and phone on the right from `lg` (under the copy on
              smaller screens).
            */}
            <section className="site-dark relative overflow-hidden bg-site-navy text-slate-200">
                <div
                    className={`${container} grid items-center gap-10 pt-28 pb-14 sm:pt-32 lg:grid-cols-[1fr_1.2fr] lg:gap-6 lg:pt-32 lg:pb-16`}
                >
                    <div>
                        <h1
                            className={`max-w-2xl text-[2rem] leading-[1.1] sm:text-[2.75rem] xl:text-[3.25rem] ${display}`}
                        >
                            Business Software and ERP Solutions for{' '}
                            <span className="text-site-highlight">
                                Modern Organizations
                            </span>
                        </h1>
                        <p className="mt-5 max-w-xl text-base leading-relaxed text-pretty text-slate-100 sm:text-lg">
                            Manage finance, inventory, projects, people and
                            daily operations with connected applications for
                            businesses, NGOs, government institutions and
                            service organizations.
                        </p>
                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            <a href="#products" className={button.light}>
                                Explore Products
                                <ArrowRight className="size-4" aria-hidden />
                            </a>
                            <Link href={demoUrl} className={button.outlineDark}>
                                <CalendarDays className="size-4" aria-hidden />
                                Request a Demo
                            </Link>
                        </div>
                    </div>
                    <figure className="mx-auto w-full max-w-xl lg:-mr-4 lg:max-w-none xl:-mr-10">
                        <img
                            src="/images/home/hero-devices.webp?v=20261126c"
                            srcSet="/images/home/hero-devices-800.webp?v=20261126c 800w, /images/home/hero-devices.webp?v=20261126c 1462w"
                            sizes="(min-width: 1024px) 50vw, 100vw"
                            alt="Miles Global Softwares on a laptop and a phone: a dashboard with sales, receivables, inventory and expenses, and the mobile app's Sales, Inventory, Finance, HR, Projects and Reports"
                            width={1462}
                            height={872}
                            fetchPriority="high"
                            decoding="async"
                            className="w-full"
                        />
                        <figcaption className="sr-only">
                            Illustration; the figures are examples.
                        </figcaption>
                    </figure>
                </div>
            </section>

            {/* The platform's points, in a white strip under the hero. */}
            <section
                aria-label="Platform highlights"
                className="border-b border-site-line bg-white"
            >
                <ul
                    className={`${container} grid grid-cols-2 gap-x-4 gap-y-8 py-10 sm:grid-cols-3 lg:grid-cols-5 lg:gap-0 lg:divide-x lg:divide-site-line`}
                >
                    {heroPoints.map(({ icon: Icon, label }) => (
                        <li
                            key={label}
                            className="flex flex-col items-center gap-3 px-4 text-center text-sm leading-snug font-semibold text-site-ink last:col-span-2 sm:text-base sm:last:col-span-1"
                        >
                            <span className="inline-flex size-12 items-center justify-center rounded-lg bg-site-tint text-site-accent">
                                <Icon
                                    className="size-6"
                                    strokeWidth={1.75}
                                    aria-hidden
                                />
                            </span>
                            {label}
                        </li>
                    ))}
                </ul>
            </section>

            {/* 2. The suite: six business applications, each with its real release status. */}
            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-24 bg-site-wash"
            >
                <div className={`${container} ${section}`}>
                    <div className="flex flex-wrap items-end justify-between gap-6">
                        <SectionHeading
                            id="products-heading"
                            title={
                                <>
                                    A complete suite of{' '}
                                    <Accent>business applications</Accent>
                                </>
                            }
                            lead="Integrated applications to run, manage and grow your organization: start with one, add the next as you grow."
                            align="left"
                        />
                        <div className="flex flex-wrap gap-3">
                            <Link href="/products" className={button.primary}>
                                <LayoutGrid className="size-4" aria-hidden />
                                View All Products
                            </Link>
                            <Link href="/services" className={button.outline}>
                                <Code2 className="size-4" aria-hidden />
                                Custom Software
                            </Link>
                        </div>
                    </div>
                    <ul className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                        {suite.map((item) => (
                            <CompactCard
                                key={item.title}
                                href={item.href}
                                icon={item.icon}
                                title={item.title}
                                body={item.body}
                                status={statusOf.get(item.slug)}
                            />
                        ))}
                    </ul>
                </div>
            </section>

            {/* 3. Industry ERPs: every one in the catalog, released ones first. */}
            <section
                id="erp"
                aria-labelledby="erp-heading"
                className="site-dark scroll-mt-24 bg-site-night text-slate-200"
            >
                <div className={`${container} ${section}`}>
                    <div className="flex flex-wrap items-end justify-between gap-6">
                        <SectionHeading
                            id="erp-heading"
                            title={
                                <>
                                    ERP solutions{' '}
                                    <Accent>for your industry</Accent>
                                </>
                            }
                            lead="A complete system for each kind of organization, with Finance & Accounting ready to add for the books."
                            align="left"
                        />
                        <Link href="/products#erp" className={button.light}>
                            Explore ERP solutions
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <ul className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                        {erps.map((erp) => (
                            <CompactCard
                                key={erp.slug}
                                href={`/${erp.url}`}
                                icon={catalogIcon(erp.icon)}
                                title={erp.name}
                                body={erp.summary}
                                status={erp.status}
                            />
                        ))}
                    </ul>
                </div>
            </section>

            {/* 4. Miles Workspace */}
            <section
                id="cloud"
                aria-labelledby="cloud-heading"
                className="scroll-mt-24 bg-white"
            >
                <div className={`${container} ${section}`}>
                    <div className="flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <SectionHeading
                                id="cloud-heading"
                                title={
                                    <>
                                        Your business applications.{' '}
                                        <Accent>
                                            One connected workspace.
                                        </Accent>
                                    </>
                                }
                                lead="Your organization gets one workspace for every Miles Global Softwares application: one account, one team and one set of permissions, whichever applications you use."
                                align="left"
                            />
                        </div>
                        <Link href="/platform" className={button.outline}>
                            Explore Miles Workspace
                        </Link>
                    </div>
                    <div className="mt-14">
                        <FeatureGrid features={cloudPillars} columns={3} />
                    </div>
                </div>
            </section>

            {/* 4. Custom software development: built for you, not a subscription */}
            <section
                id="custom"
                aria-labelledby="custom-heading"
                className="relative scroll-mt-24 overflow-hidden bg-site-wash"
            >
                <div className={`${container} ${section}`}>
                    <div className="grid items-center gap-12 lg:grid-cols-[1.15fr_1fr] lg:gap-16">
                        <div>
                            <SectionHeading
                                id="custom-heading"
                                title={
                                    <>
                                        Your business is unique.{' '}
                                        <Accent>
                                            Your software can be too.
                                        </Accent>
                                    </>
                                }
                                lead="Apart from our subscription products, we build and adapt software around your workflows: scoped with you, quoted, and delivered as a project."
                                align="left"
                            />
                            <ul className="mt-8 grid gap-3 sm:grid-cols-3">
                                {customSteps.map((step) => (
                                    <li
                                        key={step.title}
                                        className={`p-4 ${panel}`}
                                    >
                                        <step.icon
                                            className="size-5 text-site-accent"
                                            aria-hidden
                                        />
                                        <p className="mt-3 font-semibold text-site-ink">
                                            {step.title}
                                        </p>
                                        <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                            {step.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-8 flex flex-wrap gap-3">
                                <Link
                                    href={quoteUrl('Project: Custom software')}
                                    className={button.primary}
                                >
                                    Discuss Your Project
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                                <Link
                                    href={quoteUrl('Quote: Custom software')}
                                    className={button.outline}
                                >
                                    Request a Quotation
                                </Link>
                            </div>
                        </div>
                        <figure className="lg:order-first">
                            <img
                                src="/images/home/custom-software.webp?v=20261126c"
                                srcSet="/images/home/custom-software-800.webp?v=20261126c 800w, /images/home/custom-software.webp?v=20261126c 1536w"
                                sizes="(min-width: 1024px) 50vw, 100vw"
                                alt="A business owner at her laptop, with Finance, HR, Inventory, Sales and POS, Projects, Scheduling, Operations and Analytics around one dashboard"
                                width={1536}
                                height={1024}
                                loading="lazy"
                                decoding="async"
                                className="w-full [mask-image:radial-gradient(closest-side,black_72%,transparent)] mix-blend-multiply"
                            />
                        </figure>
                    </div>
                </div>
            </section>

            {/* 5. Flexible software deployment, and the apps to download */}
            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="scroll-mt-24 bg-white"
            >
                <div className={`${container} ${section}`}>
                    <SectionHeading
                        id="ways-heading"
                        title={
                            <>
                                Online, offline or connected.{' '}
                                <Accent>You choose how to work.</Accent>
                            </>
                        }
                        lead="Not every product offers every option. Each one below names the products that have it today."
                        align="left"
                    />
                    <div className="mt-12">
                        <DeploymentOptions
                            catalog={catalog}
                            trialDays={trialDays}
                        />
                    </div>
                    <div className="mt-14 sm:mt-16">
                        <h3 className={`${type.subheading} ${display}`}>
                            Or have it built for you
                        </h3>
                        <p className="mt-2 max-w-2xl text-slate-600">
                            When a ready product is not the right fit, we build
                            or adapt the software as a project, quoted up front.
                        </p>
                        <div className="mt-8">
                            <ServiceCards />
                        </div>
                    </div>
                    <Link
                        href="/downloads"
                        className={`group mt-10 flex flex-wrap items-center gap-x-6 gap-y-4 p-6 sm:p-8 ${card}`}
                    >
                        <span className="flex -space-x-2" aria-hidden>
                            <span className="inline-flex size-11 items-center justify-center rounded-lg bg-white text-site-accent ring-1 ring-site-line">
                                <Monitor className="size-5" />
                            </span>
                            <span className="inline-flex size-11 items-center justify-center rounded-lg bg-white text-site-accent ring-1 ring-site-line">
                                <Smartphone className="size-5" />
                            </span>
                        </span>
                        <span className="min-w-60 flex-1">
                            <span className="block font-semibold text-site-ink">
                                Apps for Windows and Android
                            </span>
                            <span className="mt-1 block text-sm text-slate-600">
                                The pharmacy and shop counter on a Windows PC,
                                online or fully offline, and the counter apps
                                for phones and tablets.
                            </span>
                        </span>
                        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-site-accent">
                            Go to downloads
                            <ArrowRight
                                className="size-4 transition-transform group-hover:translate-x-1"
                                aria-hidden
                            />
                        </span>
                    </Link>
                </div>
            </section>

            {/* 6. Why Miles Global Softwares */}
            <WhyMiles />

            {/* 7. How we work */}
            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="site-dark relative isolate scroll-mt-24 overflow-hidden bg-site-navy text-slate-300"
            >
                <div className={`${container} ${section}`}>
                    <SectionHeading
                        id="how-heading"
                        title={
                            <>
                                How we <Accent>work</Accent>
                            </>
                        }
                        align="left"
                    />
                    <ol className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {howItWorks.map((step, i) => (
                            <li
                                key={step.title}
                                className="rounded-xl bg-site-navy-light p-6 ring-1 ring-site-navy-line"
                            >
                                <span className="inline-flex size-10 items-center justify-center rounded-full bg-site-accent text-sm font-semibold text-white">
                                    {ordinal(i)}
                                </span>
                                <h3 className="mt-5 text-lg font-semibold text-white">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-slate-300">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* FAQ */}
            <section aria-labelledby="faq-heading" className="bg-white">
                <div
                    className={`${container} grid gap-10 lg:grid-cols-[1fr_1.5fr] lg:gap-16 ${section}`}
                >
                    <div className="lg:sticky lg:top-28 lg:self-start">
                        <SectionHeading
                            id="faq-heading"
                            title={
                                <>
                                    Frequently asked <Accent>questions</Accent>
                                </>
                            }
                            align="left"
                        />
                        <p className="mt-6 max-w-sm text-site-muted">
                            Something else on your mind?{' '}
                            <Link href="/contact" className={textLink}>
                                Ask us
                            </Link>{' '}
                            and we reply within one working day.
                        </p>
                    </div>
                    <FaqList items={faq(trialDays)} />
                </div>
            </section>

            {/* 8. Final call to action */}
            <CtaBand
                id="cta-heading"
                title={
                    <>
                        Let's build the right solution{' '}
                        <Accent>for your business.</Accent>
                    </>
                }
                body="Whether you need ready-made business software or a completely customized application, we can help you find the right solution."
            >
                <Link href="/start" className={button.light}>
                    Get Started
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link href={demoUrl} className={button.outlineDark}>
                    Request a Demo
                </Link>
                <Link
                    href={quoteUrl('Project: Custom software')}
                    className={button.outlineDark}
                >
                    Discuss a Custom Project
                </Link>
            </CtaBand>
        </>
    );
}
