import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Code2,
    FileText,
    LayoutGrid,
    MessagesSquare,
    Monitor,
    PackageCheck,
    Rocket,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CtaBand, FeatureGrid } from '@/sites/public/components/blocks';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import { cloudPillars, howItWorks } from '@/sites/public/components/content';
import FaqList, { faq } from '@/sites/public/components/faq';
import { heroApplications } from '@/sites/public/components/hero-applications';
import HeroEcosystem from '@/sites/public/components/hero-ecosystem';
import WhyMiles from '@/sites/public/components/why-miles';
import {
    DeploymentOptions,
    ServiceCards,
    quoteUrl,
} from '@/sites/public/components/offerings';
import { Accent, IconBox } from '@/sites/public/components/page-hero';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization, siteName } from '@/sites/public/components/seo';
import {
    button,
    container,
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

/**
 * The eight application areas as tiles on a dark panel, beside the
 * Products heading: the hero's icons again, with a link where the site has
 * a page.
 */
function ApplicationTiles() {
    return (
        <div className="site-dark relative isolate overflow-hidden rounded-xl bg-[radial-gradient(circle_at_30%_20%,#11512b,#063519_60%,#032a16)] p-4 shadow-2xl shadow-site-night/20 sm:p-8">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(#a3e63526_1px,transparent_1px)] bg-size-[22px_22px]"
            />
            <ul
                aria-label="Areas of a business Tatook connects"
                className="grid grid-cols-4 gap-2 sm:gap-4"
            >
                {heroApplications.map((app) => {
                    const tile = (
                        <>
                            <img
                                src={app.icon}
                                alt=""
                                width={192}
                                height={192}
                                loading="lazy"
                                decoding="async"
                                className="mx-auto w-12 drop-shadow-[0_6px_10px_rgb(0_0_0/0.25)] transition-transform duration-300 group-hover:scale-110 sm:w-16 lg:w-20"
                            />
                            <span className="mt-2 block text-center text-[0.6875rem] leading-tight font-semibold text-slate-200 sm:text-sm">
                                {app.name}
                            </span>
                        </>
                    );
                    const box =
                        'group block h-full rounded-lg bg-white/5 px-1 py-3 ring-1 ring-white/10 transition-colors sm:px-2 sm:py-5';

                    return (
                        <li key={app.id}>
                            {app.href ? (
                                <Link
                                    href={app.href}
                                    className={`${box} hover:bg-white/10 hover:ring-site-highlight/60 focus-visible:outline-2 focus-visible:outline-site-highlight`}
                                >
                                    {tile}
                                </Link>
                            ) : (
                                <div className={box}>{tile}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
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
                title="Run your whole business in one place"
                description="Pharmacy, retail, finance and HR, connected under one Tatook account."
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
              Full-bleed dark hero. Copy is the left 48%; the application
              orbit (animated dashboard and eight areas) is the right 52% from
              the tablet up, and above the copy on phones. The page then
              alternates light and dark bands down to the closing call.
            */}
            <section className="site-dark relative overflow-hidden bg-[radial-gradient(circle_at_75%_45%,#11512b,#063519_56%,#032a16_100%)]">
                {/* Faint dot grid, strongest behind the illustration. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(#a3e63533_1px,transparent_1px)] mask-[radial-gradient(ellipse_at_75%_45%,black,transparent_65%)] bg-size-[24px_24px]"
                />
                <div
                    className={`${container} relative grid items-center gap-8 pt-24 pb-20 sm:pt-28 sm:pb-24 md:grid-cols-[46fr_54fr] md:gap-6 lg:grid-cols-[48fr_52fr] lg:gap-10 lg:pt-32 lg:pb-28`}
                >
                    <div>
                        <h1
                            className={`site-rise max-w-xl text-[2.125rem] leading-[1.08] sm:text-[2.625rem] md:text-[2rem] lg:text-[2.75rem] xl:text-[3.5rem] ${display}`}
                        >
                            <span className="block">
                                Run your whole business
                            </span>
                            <Accent>in one place</Accent>
                        </h1>
                        <p className="site-rise mt-5 max-w-[35rem] text-base leading-relaxed text-pretty text-slate-300 [animation-delay:120ms] sm:text-[1.0625rem] lg:text-lg">
                            Pharmacy, retail, finance and HR, connected under
                            one Tatook account.
                        </p>
                        <div className="site-rise mt-8 flex flex-wrap items-center gap-3 [animation-delay:220ms]">
                            <a href="#products" className={button.primary}>
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
                    <div className="order-first md:order-none">
                        <HeroEcosystem />
                    </div>
                </div>
            </section>

            {/* Three ways in: cards that overlap the foot of the hero. */}
            <div className="bg-white">
                <ul
                    className={`${container} relative z-10 -mt-12 grid gap-4 sm:-mt-14 md:grid-cols-3 lg:gap-6`}
                >
                    {highlights(trialDays).map((item, i) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                className="group flex h-full items-start gap-4 rounded-lg bg-white p-6 shadow-xl ring-1 shadow-site-night/10 ring-site-line transition-[transform,box-shadow] duration-200 hover:-translate-y-1 hover:shadow-2xl hover:ring-site-accent/40"
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

            {/* 2. Tatook Products: the whole family in one picture; each product is on the Products page. */}
            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-24"
            >
                <div
                    className={`${container} grid items-center gap-10 py-20 sm:py-28 md:gap-12 lg:grid-cols-[1.15fr_1fr] lg:gap-16`}
                >
                    <ApplicationTiles />
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
                            other essential business activities, Tatook provides
                            reliable, scalable, and easy-to-use solutions for
                            modern organizations.
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

            {/* 3. Tatook Workspace */}
            <section
                id="cloud"
                aria-labelledby="cloud-heading"
                className="site-dark relative isolate scroll-mt-24 overflow-hidden bg-[radial-gradient(circle_at_85%_0%,#11512b,#063519_55%,#032a16)] text-slate-300"
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
                                lead="Your organization gets one workspace for every Tatook application: one account, one team and one set of permissions, whichever applications you use."
                                align="left"
                            />
                        </div>
                        <Link href="/platform" className={button.outlineDark}>
                            Explore Tatook Workspace
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
                className="relative scroll-mt-24 overflow-hidden bg-gradient-to-b from-white via-site-wash/60 to-white"
            >
                <div className={`${container} py-20 sm:py-28`}>
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
                                        className="rounded-lg bg-white/70 p-4 ring-1 ring-site-line"
                                    >
                                        <step.icon
                                            className="size-5 text-site-accent-ink"
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
                                src="/images/home/custom-software.webp"
                                srcSet="/images/home/custom-software-800.webp 800w, /images/home/custom-software.webp 1536w"
                                sizes="(min-width: 1024px) 50vw, 100vw"
                                alt="A business owner at her laptop, with Finance, HR, Inventory, Sales and POS, Projects, Scheduling, Operations and Analytics around one dashboard"
                                width={1536}
                                height={1024}
                                loading="lazy"
                                decoding="async"
                                className="w-full mix-blend-multiply [mask-image:radial-gradient(closest-side,black_72%,transparent)]"
                            />
                        </figure>
                    </div>
                </div>
            </section>

            {/* 5. Flexible software deployment, and the apps to download */}
            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="scroll-mt-24 bg-site-wash"
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
                    <div className="mt-14 sm:mt-16">
                        <h3 className="font-public-display text-2xl font-semibold tracking-tight text-site-ink">
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
                        className="group mt-10 flex flex-wrap items-center gap-x-6 gap-y-4 rounded-lg border border-site-line bg-white p-6 shadow-sm transition-[border-color,box-shadow] hover:border-site-accent/40 hover:shadow-lg hover:shadow-site-night/5 sm:p-8"
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

            {/* 6. Why Tatook */}
            <WhyMiles />

            {/* 7. How we work */}
            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="site-dark relative isolate scroll-mt-24 overflow-hidden bg-[radial-gradient(circle_at_15%_100%,#11512b,#063519_55%,#032a16)] text-slate-300"
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
                    <ol className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {howItWorks.map((step, i) => (
                            <li
                                key={step.title}
                                className="rounded-lg bg-white/5 p-6 ring-1 ring-white/10"
                            >
                                <span className="inline-flex size-10 items-center justify-center rounded-full bg-site-highlight font-public-display text-sm font-semibold text-site-night">
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
