import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Code2,
    LayoutGrid,
    Monitor,
    Rocket,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CtaBand, FeatureGrid } from '@/sites/public/components/blocks';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import {
    cloudPillars,
    howItWorks,
    whyMiles,
} from '@/sites/public/components/content';
import FaqList, { faq } from '@/sites/public/components/faq';
import {
    DeploymentOptions,
    ServiceList,
    quoteUrl,
} from '@/sites/public/components/offerings';
import { Accent, IconBox } from '@/sites/public/components/page-hero';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization, siteName } from '@/sites/public/components/seo';
import {
    button,
    container,
    darkBand,
    display,
    textLink,
} from '@/sites/public/components/styles';

type Props = {
    catalog: CatalogEntry[];
    /** Registry applications (sent by the server; the hero no longer draws them). */
    products: Product[];
    trialDays: number;
    canonical: string;
};

/** The three ways in, in a row just under the hero: subscriptions, custom work, the trial. */
function highlights(trialDays: number): {
    icon: LucideIcon;
    title: string;
    body: string;
    cta: string;
    href: string;
}[] {
    return [
        {
            icon: LayoutGrid,
            title: 'Subscribe only to what you need',
            body: 'Pick the applications your business uses and add the next one as you grow.',
            cta: 'See plans',
            href: '/pricing',
        },
        {
            icon: Code2,
            title: 'Custom software',
            body: 'Web, desktop and mobile systems built around how you work, quoted as a project.',
            cta: 'Custom solutions',
            href: '/services',
        },
        {
            icon: Rocket,
            title: `${trialDays}-day free trial`,
            body: 'Start with the application you need first. No payment details.',
            cta: 'Get started',
            href: '/start',
        },
    ];
}

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

    return (
        <>
            <Seo
                title="Business software that works together"
                description="Miles Global Softwares builds business applications that share one workspace, and tailored software, helping organizations manage operations, connect teams and grow with confidence."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@graph': [
                        organization(origin),
                        {
                            '@type': 'WebSite',
                            '@id': `${origin}/#website`,
                            name: siteName,
                            url: `${origin}/`,
                            publisher: { '@id': `${origin}/#organization` },
                        },
                    ],
                }}
            />

            {/*
              Sections sit on solid bands, no patterns or gradients: navy,
              white and soft blue in turn.
            */}
            {/* 1. Hero: solid navy, the copy centred. */}
            <section className={darkBand}>
                <div
                    className={`site-rise ${container} flex flex-col items-center pt-28 pb-16 text-center sm:pt-36 sm:pb-24`}
                >
                    <div className="flex flex-col items-center">
                        <h1
                            className={`max-w-3xl text-[2.25rem] leading-[1.05] min-[400px]:text-[2.625rem] sm:text-6xl lg:text-[4rem] ${display}`}
                        >
                            Business software{' '}
                            <Accent>that works together.</Accent>
                        </h1>
                        <p className="mt-6 max-w-2xl text-base leading-relaxed text-pretty text-slate-300 sm:text-lg">
                            Miles Global Softwares builds powerful business
                            applications and tailored software solutions to help
                            organizations manage operations, connect teams and
                            grow with confidence.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
                            <a href="#products" className={button.light}>
                                Explore Products
                                <ArrowRight className="size-4" aria-hidden />
                            </a>
                            <Link
                                href="/platform"
                                className={button.outlineDark}
                            >
                                How it works
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            {/* Three ways in, a divided row under the hero. */}
            <div className="bg-white">
                <ul
                    className={`${container} grid divide-y divide-site-line md:grid-cols-3 md:divide-x md:divide-y-0`}
                >
                    {highlights(trialDays).map((item, i) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                className="group flex h-full items-start gap-4 py-7 md:px-7 md:first:pl-0 md:last:pr-0"
                            >
                                <IconBox icon={item.icon} index={i} />
                                <span className="flex-1">
                                    <span className="block font-semibold text-site-ink transition-colors group-hover:text-site-accent-ink">
                                        {item.title}
                                    </span>
                                    <span className="mt-1 block text-sm leading-relaxed text-slate-600">
                                        {item.body}
                                    </span>
                                    <span className="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-site-accent-ink">
                                        {item.cta}
                                        <ArrowRight
                                            className="size-4 transition-transform group-hover:translate-x-1"
                                            aria-hidden
                                        />
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>

            {/* 2. Miles Products: the whole family in one picture; each product is on the Products page. */}
            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-24"
            >
                <div
                    className={`${container} grid items-center gap-10 py-20 sm:py-28 md:gap-12 lg:grid-cols-[1.15fr_1fr] lg:gap-16`}
                >
                    <img
                        src="/images/home/miles-products.webp"
                        alt="Miles business applications on a desktop, laptop, tablet and phone, surrounded by the industries they serve: restaurants, pharmacies, retail, schools, accounting, finance, HR, warehousing, hotels, water utilities, fuel stations and offices."
                        width={1280}
                        height={960}
                        loading="lazy"
                        decoding="async"
                        className="mx-auto w-full max-w-2xl lg:max-w-none"
                    />
                    <div>
                        <SectionHeading
                            id="products-heading"
                            title={
                                <>
                                    One Platform.{' '}
                                    <Accent>
                                        Multiple Business Solutions.
                                    </Accent>
                                </>
                            }
                            lead="Discover a complete ecosystem of powerful business software designed to simplify daily operations, streamline workflows, improve productivity, and support business growth."
                            align="left"
                        />
                        <p className="mt-4 max-w-xl text-base leading-relaxed text-pretty text-slate-600">
                            From managing sales and inventory to handling
                            financial operations, customer relationships, and
                            other essential business activities, Miles Global
                            Softwares provides reliable, scalable, and
                            easy-to-use solutions for modern organizations.
                        </p>
                        <Link
                            href="/products"
                            className={`${button.primary} mt-8 w-full justify-center sm:w-auto`}
                        >
                            Explore All Products
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </div>
            </section>

            {/* 3. Miles Workspace */}
            <section
                id="cloud"
                aria-labelledby="cloud-heading"
                className="scroll-mt-24 bg-white"
            >
                <div className={`${container} py-20 sm:py-28`}>
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
                                lead="Your organization gets one workspace for every Miles application: one account, one team and one set of permissions, whichever applications you use."
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
                className="scroll-mt-24"
            >
                <div
                    className={`${container} grid gap-12 py-20 sm:py-28 lg:grid-cols-[1fr_1.4fr] lg:gap-16`}
                >
                    <div className="lg:sticky lg:top-28 lg:self-start">
                        <SectionHeading
                            id="custom-heading"
                            title={
                                <>
                                    Your business is unique.{' '}
                                    <Accent>Your software can be too.</Accent>
                                </>
                            }
                            lead="Apart from our subscription products, we build and adapt software around your workflows: scoped with you, quoted, and delivered as a project."
                            align="left"
                        />
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link
                                href={quoteUrl('Project: Custom software')}
                                className={button.primary}
                            >
                                Discuss Your Project
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <Link
                                href={quoteUrl('Quote: Custom software')}
                                className={button.outline}
                            >
                                Request a Quotation
                            </Link>
                        </div>
                    </div>
                    <ServiceList />
                </div>
            </section>

            {/* 5. Flexible software deployment, and the apps to download */}
            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="scroll-mt-24 bg-white"
            >
                <div className={`${container} py-20 sm:py-28`}>
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
                    <Link
                        href="/downloads"
                        className="group mt-10 flex flex-wrap items-center gap-x-6 gap-y-4 rounded-2xl border border-site-line bg-site-soft p-6 transition-colors hover:border-site-accent/40 sm:p-8"
                    >
                        <span className="flex -space-x-2" aria-hidden>
                            <span className="inline-flex size-11 items-center justify-center rounded-xl bg-white text-site-accent-ink ring-1 ring-site-line">
                                <Monitor className="size-5" />
                            </span>
                            <span className="inline-flex size-11 items-center justify-center rounded-xl bg-white text-site-accent-ink ring-1 ring-site-line">
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
                        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-site-accent-ink">
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
            <section aria-labelledby="why-heading" className={darkBand}>
                <div
                    className={`${container} grid gap-12 py-20 sm:py-28 lg:grid-cols-[1fr_1.7fr] lg:gap-16`}
                >
                    <div className="lg:sticky lg:top-28 lg:self-start">
                        <SectionHeading
                            id="why-heading"
                            title={
                                <>
                                    Why <Accent>Miles Global Softwares</Accent>
                                </>
                            }
                            lead="Software made for the trade you run, on the screens you use, in one workspace, with people who set it up and stay to support it."
                            align="left"
                        />
                        <Link
                            href="/about"
                            className={`${button.outlineDark} mt-8`}
                        >
                            About us
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <ul className="grid gap-4 sm:grid-cols-2 sm:gap-5">
                        {whyMiles.map((item, i) => (
                            <li
                                key={item.title}
                                className="flex flex-col rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 sm:p-7"
                            >
                                <div className="flex items-center justify-between">
                                    <IconBox icon={item.icon} index={i} />
                                    <span className="font-public-display text-sm font-semibold text-white/40 tabular-nums">
                                        {ordinal(i)}
                                    </span>
                                </div>
                                <h3 className="mt-5 text-lg font-semibold text-white">
                                    {item.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-slate-300">
                                    {item.body}
                                </p>
                                <ul className="mt-5 space-y-2 border-t border-white/10 pt-5">
                                    {item.points.map((point) => (
                                        <li
                                            key={point}
                                            className="flex items-start gap-2.5 text-sm text-slate-200"
                                        >
                                            <Check
                                                className="mt-0.5 size-4 shrink-0 text-site-highlight"
                                                aria-hidden
                                            />
                                            {point}
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            {/* 7. How we work */}
            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="scroll-mt-24 bg-site-wash"
            >
                <div className={`${container} py-20 sm:py-28`}>
                    <SectionHeading
                        id="how-heading"
                        title={
                            <>
                                How we <Accent>work</Accent>
                            </>
                        }
                        align="left"
                    />
                    <ol className="mt-14 grid gap-x-10 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
                        {howItWorks.map((step, i) => (
                            <li
                                key={step.title}
                                className="border-t-2 border-site-ink pt-6"
                            >
                                <span className="font-public-display text-sm font-semibold text-site-accent-ink">
                                    {ordinal(i)}
                                </span>
                                <h3 className="mt-3 text-lg font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-slate-600">
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
                    className={`${container} grid gap-10 py-20 sm:py-28 lg:grid-cols-[1fr_1.5fr] lg:gap-16`}
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
                        <p className="mt-6 max-w-sm text-slate-600">
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
                <a href="#products" className={button.light}>
                    Explore Products
                    <ArrowRight className="size-4" aria-hidden />
                </a>
                <Link href="/start" className={button.outlineDark}>
                    Get Started
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
