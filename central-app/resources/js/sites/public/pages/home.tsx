import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Layers, LayoutGrid } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
} from '@/sites/public/components/blocks';
import { ProductCard } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import {
    connected,
    howItWorks,
    solutions,
    whyMiles,
} from '@/sites/public/components/content';
import FaqList, { faq } from '@/sites/public/components/faq';
import HeroMockup from '@/sites/public/components/hero-mockup';
import {
    Accent,
    HeroBackdrop,
    IconBox,
    LightBackdrop,
} from '@/sites/public/components/page-hero';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import {
    button,
    card,
    darkBand,
    display,
    panel,
    textLink,
} from '@/sites/public/components/styles';

type Props = {
    catalog: CatalogEntry[];
    /** Registry applications, for the dashboard drawing in the hero. */
    products: Product[];
    trialDays: number;
    canonical: string;
};

/** Two digits, as numbered steps read: 01, 02, … */
function ordinal(i: number): string {
    return String(i + 1).padStart(2, '0');
}

export default function MarketingHome({
    catalog,
    products,
    trialDays,
    canonical,
}: Props) {
    const erp = catalog.filter((entry) => entry.category === 'erp');
    const apps = catalog.filter((entry) => entry.category === 'application');
    const trialLabel = `Start Your ${trialDays}-Day Free Trial`;

    return (
        <>
            <Seo
                title="ERP solutions and business applications"
                description={`Industry ERP solutions and business applications on one connected platform: one sign-in, one organization, one set of books. Start your ${trialDays}-day free trial.`}
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'Organization',
                    name: 'Miles Global Link Co. Ltd',
                    url: canonical,
                }}
            />

            {/* 1. Hero */}
            <section className={darkBand}>
                <HeroBackdrop />
                <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 pt-28 pb-20 sm:gap-16 sm:px-6 sm:pt-40 sm:pb-28 lg:grid-cols-[1.05fr_1fr] lg:gap-10 xl:pt-44">
                    <div className="site-rise text-center lg:text-left">
                        <p className="mb-5 inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-sm font-semibold text-white ring-1 ring-white/20">
                            Run your business. All connected.
                        </p>
                        <h1
                            className={`text-[1.875rem] leading-[1.1] min-[400px]:text-[2.125rem] sm:text-6xl lg:text-[3.4rem] xl:text-[4rem] ${display}`}
                        >
                            Everything your business needs.{' '}
                            <Accent>Working together.</Accent>
                        </h1>
                        <p className="mx-auto mt-6 max-w-xl text-base text-pretty text-slate-300 sm:text-lg lg:mx-0">
                            Industry ERP solutions and standalone business
                            applications on one platform: one sign-in, one
                            organization, one set of books.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center justify-center gap-3 lg:justify-start">
                            <Link href="/start" className={button.light}>
                                {trialLabel}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <Link href="/erp" className={button.outlineDark}>
                                Explore ERP Solutions
                            </Link>
                        </div>
                        <ul className="mt-9 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm font-semibold text-white/85 lg:justify-start">
                            {[
                                'No payment details',
                                'Nothing charged automatically',
                                'Keep your data when you upgrade',
                            ].map((point) => (
                                <li
                                    key={point}
                                    className="inline-flex items-center gap-2"
                                >
                                    <span className="inline-flex size-5 items-center justify-center rounded-full bg-white/15">
                                        <Check className="size-3" aria-hidden />
                                    </span>
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="site-tilt">
                        <HeroMockup
                            codes={
                                products.length > 0
                                    ? products.map((product) => product.code)
                                    : ['retail', 'pharmacy', 'finance']
                            }
                        />
                    </div>
                </div>
            </section>

            {/* 2. Our solutions */}
            <section
                aria-labelledby="solutions-heading"
                className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
            >
                <SectionHeading
                    id="solutions-heading"
                    title={
                        <>
                            Two ways to <Accent>run your business</Accent>
                        </>
                    }
                    lead="Take a complete ERP built for your industry, or start with the one application you need. Both run on the same platform."
                />
                <div className="mt-10 grid gap-5 sm:mt-14 md:grid-cols-2">
                    {[
                        {
                            icon: Layers,
                            title: 'ERP Solutions',
                            body: 'Complete systems for an industry: stock, sales, purchasing and operations, with Miles Finance built in.',
                            href: '/erp',
                            cta: 'Explore ERP Solutions',
                            count: erp.length,
                        },
                        {
                            icon: LayoutGrid,
                            title: 'Business Applications',
                            body: 'Standalone applications for finance, people, projects, purchasing and more. Use one, or add them to an ERP.',
                            href: '/apps',
                            cta: 'Explore Business Applications',
                            count: apps.length,
                        },
                    ].map((option, i) => (
                        <Link
                            key={option.title}
                            href={option.href}
                            className={`${card} group flex flex-col gap-5 p-7 sm:flex-row sm:p-9`}
                        >
                            <IconBox icon={option.icon} index={i} />
                            <span className="flex-1">
                                <span className="flex flex-wrap items-baseline gap-x-3">
                                    <span
                                        className={`text-2xl transition-colors group-hover:text-site-accent-ink ${display}`}
                                    >
                                        {option.title}
                                    </span>
                                    <span className="text-sm font-semibold text-slate-500">
                                        {option.count} products
                                    </span>
                                </span>
                                <span className="mt-2 block leading-relaxed text-slate-600">
                                    {option.body}
                                </span>
                                <span className="mt-5 inline-flex items-center gap-2 text-sm font-bold text-site-accent-ink">
                                    {option.cta}
                                    <ArrowRight
                                        className="size-4 transition-transform group-hover:translate-x-1"
                                        aria-hidden
                                    />
                                </span>
                            </span>
                        </Link>
                    ))}
                </div>
            </section>

            {/* 3. Industry ERP solutions */}
            <CatalogSection
                id="erp"
                eyebrow="ERP Solutions"
                title={
                    <>
                        An ERP for <Accent>your industry</Accent>
                    </>
                }
                lead="Each solution brings together what a business of that kind needs, on one set of books."
                entries={erp}
                trialDays={trialDays}
                href="/erp"
                linkLabel="Explore ERP Solutions"
                shaded
            />

            {/* 4. Standalone business applications */}
            <CatalogSection
                id="apps"
                eyebrow="Business Applications"
                title={
                    <>
                        Applications that <Accent>stand on their own</Accent>
                    </>
                }
                lead="Start with the application you need today. Every one shares your sign-in, your organization and your books."
                entries={apps}
                trialDays={trialDays}
                href="/apps"
                linkLabel="Explore Business Applications"
            />

            {/* 5. Why Miles Softwares */}
            <section
                aria-labelledby="why-heading"
                className="relative isolate overflow-hidden border-y border-site-line bg-site-soft"
            >
                <LightBackdrop />
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="why-heading"
                        title={
                            <>
                                Why <Accent>Miles Softwares</Accent>
                            </>
                        }
                        lead="Built for the counter, the store room and month-end, by people who start where the work happens."
                    />
                    <div className="mt-10 sm:mt-14">
                        <FeatureGrid features={whyMiles} />
                    </div>
                </div>
            </section>

            {/* 6. How it works */}
            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <SectionHeading
                    id="how-heading"
                    title={
                        <>
                            How it <Accent>works</Accent>
                        </>
                    }
                    lead="Four steps from here to working in your own workspace."
                />
                <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {howItWorks.map((step, i) => (
                        <li key={step.title} className={`${panel} p-6 sm:p-7`}>
                            <span className="site-gradient inline-flex size-11 items-center justify-center rounded-full font-public-display text-sm font-bold text-white">
                                {ordinal(i)}
                            </span>
                            <h3 className="mt-5 text-lg font-semibold">
                                {step.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                {step.body}
                            </p>
                        </li>
                    ))}
                </ol>
                <div className="mt-12 flex flex-wrap justify-center gap-3">
                    <Link href="/start" className={button.primary}>
                        {trialLabel}
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link
                        href="/contact?subject=Request%20a%20demo"
                        className={button.outline}
                    >
                        Request a Demo
                    </Link>
                </div>
            </section>

            {/* 7. The free trial */}
            <section
                id="free-trial"
                aria-labelledby="trial-heading"
                className="scroll-mt-24 px-4 sm:px-6"
            >
                <div
                    className={`${darkBand} mx-auto grid max-w-7xl items-center gap-10 rounded-[2rem] px-6 py-14 sm:px-14 sm:py-20 lg:grid-cols-[1.2fr_1fr]`}
                >
                    <HeroBackdrop />
                    <div>
                        <Eyebrow>{trialDays}-day free trial</Eyebrow>
                        <h2
                            id="trial-heading"
                            className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-[2.6rem] sm:leading-tight"
                        >
                            Try it in your own workspace,{' '}
                            <Accent>with your own data</Accent>
                        </h2>
                        <p className="mt-4 max-w-xl text-slate-300">
                            Any product marked with a free trial can be tried
                            for {trialDays} days. When you are ready, choose a
                            plan and carry straight on with everything you
                            entered.
                        </p>
                        <Link href="/start" className={`mt-8 ${button.light}`}>
                            {trialLabel}
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <ul className="grid gap-3 text-sm text-white">
                        {[
                            `${trialDays} days, counted from the day you start`,
                            'No payment details to start',
                            'Nothing is charged unless you choose a plan',
                            'One trial per product for each business',
                            'Your data stays when you upgrade',
                        ].map((line) => (
                            <li
                                key={line}
                                className="flex items-center gap-3 rounded-2xl bg-white/10 px-4 py-3 ring-1 ring-white/15"
                            >
                                <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-white text-site-accent-ink">
                                    <Check className="size-3.5" aria-hidden />
                                </span>
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            {/* 8. One connected platform */}
            <section
                aria-labelledby="platform-heading"
                className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
            >
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <SectionHeading
                        id="platform-heading"
                        title={
                            <>
                                One <Accent>connected</Accent> platform
                            </>
                        }
                        lead="Every Miles product shares the same foundation, so adding the next one never means starting again."
                        align="left"
                    />
                    <Link href="/platform" className={button.outline}>
                        Learn More
                        <span className="sr-only"> about the platform</span>
                    </Link>
                </div>
                <div className="mt-10">
                    <FeatureGrid features={connected} columns={4} />
                </div>
            </section>

            {/* 9. Who we serve */}
            <section
                aria-labelledby="serve-heading"
                className="border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="serve-heading"
                        title={
                            <>
                                Who we <Accent>serve</Accent>
                            </>
                        }
                        lead="Growing organizations that have outgrown spreadsheets and cash books."
                    />
                    <ul className="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {solutions.map(({ id, icon: Icon, title, pain }) => (
                            <li key={id}>
                                <Link
                                    href={`/industries#${id}`}
                                    className={`${card} group flex h-full items-start gap-4 p-5`}
                                >
                                    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink">
                                        <Icon className="size-5" aria-hidden />
                                    </span>
                                    <span>
                                        <span className="block font-semibold transition-colors group-hover:text-site-accent-ink">
                                            {title}
                                        </span>
                                        <span className="mt-1 line-clamp-2 block text-sm text-slate-600">
                                            {pain}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            {/* 10. FAQ */}
            <section aria-labelledby="faq-heading">
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_1.5fr] lg:gap-16">
                    <div className="lg:sticky lg:top-32 lg:self-start">
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

            {/* 11. Final call to action */}
            <CtaBand
                id="cta-heading"
                title={
                    <>
                        Run your business. <Accent>All connected.</Accent>
                    </>
                }
                body={`Start a ${trialDays}-day free trial today, or talk to us about the right solution for your business.`}
            >
                <Link href="/start" className={button.light}>
                    {trialLabel}
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link
                    href="/contact?subject=Request%20a%20demo"
                    className={button.outlineDark}
                >
                    Request a Demo
                </Link>
            </CtaBand>
        </>
    );
}

function CatalogSection({
    id,
    eyebrow,
    title,
    lead,
    entries,
    trialDays,
    href,
    linkLabel,
    shaded = false,
}: {
    id: string;
    eyebrow: string;
    title: ReactNode;
    lead: string;
    entries: CatalogEntry[];
    trialDays: number;
    href: string;
    linkLabel: string;
    shaded?: boolean;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-heading`}
            className={`scroll-mt-24 ${shaded ? 'border-y border-site-line bg-site-soft' : ''}`}
        >
            <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <Eyebrow>{eyebrow}</Eyebrow>
                        <SectionHeading
                            id={`${id}-heading`}
                            title={title}
                            lead={lead}
                            align="left"
                        />
                    </div>
                    <Link href={href} className={button.outline}>
                        {linkLabel}
                    </Link>
                </div>
                <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {entries.map((entry) => (
                        <li key={entry.slug}>
                            <ProductCard
                                product={entry}
                                trialDays={trialDays}
                            />
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
