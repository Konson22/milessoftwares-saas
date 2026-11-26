import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { useState } from 'react';
import {
    connectivityLabels,
    licensingLabels,
    platformLabels,
} from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import { erpCallToAction } from '@/sites/public/components/erp-product';
import FaqList from '@/sites/public/components/faq';
import ImageHero, {
    onBlue,
    onBlueSoft,
    outlineOnBlue,
} from '@/sites/public/components/image-hero';
import type { Plan } from '@/sites/public/components/plan-card';
import {
    PrimaryAction,
    ProductSeo,
} from '@/sites/public/components/product-profile';
import type { ProductProfileProps } from '@/sites/public/components/product-profile';
import SectionHeading from '@/sites/public/components/section-heading';
import {
    button,
    card,
    container,
    panel,
    section,
} from '@/sites/public/components/styles';

/** The features in four tabs, by title; any new feature joins the last. */
const featureTabs = [
    {
        label: 'Everyday books',
        titles: [
            'The ledger',
            'Sales and customers',
            'Purchases and suppliers',
            'Bank reconciliation',
        ],
    },
    {
        label: 'Controls',
        titles: [
            'Journal approvals',
            'Payment requests',
            'Petty cash',
            'Roles and audit trail',
        ],
    },
    {
        label: 'Closing and tax',
        titles: [
            'Recurring journals',
            'Month-end and year-end',
            'Tax',
            'Multi-currency',
        ],
    },
    {
        label: 'Planning and reports',
        titles: [
            'Cost centres and projects',
            'Budgets',
            'Fixed assets',
            'Reports',
        ],
    },
];

/** The statements of the Reports feature, as lines of the ledger sheet. */
const reports = [
    'Trial balance',
    'Profit and loss',
    'Balance sheet',
    'Cash flow',
    'General ledger',
    'Aged receivables',
    'Aged payables',
    'Financial ratios',
];

/**
 * Finance & Accounting: the workspace picture across the hero, the
 * highlights in a strip, the overview beside a ledger sheet of reports,
 * the features in four tabs, the connected apps on a navy band, the three
 * steps, who it is for, plans and questions. Words come from the catalog
 * and plans from the registry.
 */
export default function FinancePage({
    product,
    plans,
    trialDays,
    canonical,
}: ProductProfileProps) {
    const comingSoon = product.status === 'coming_soon';
    const overview = product.overview?.length
        ? product.overview
        : [product.summary];

    const glance = [
        {
            term: 'Platforms',
            values: product.offering.platforms.map((p) => platformLabels[p]),
        },
        {
            term: 'Connectivity',
            values: product.offering.connectivity.map(
                (c) => connectivityLabels[c],
            ),
        },
        {
            term: 'Licensing',
            values: product.offering.licensing.map((l) => licensingLabels[l]),
        },
    ];

    return (
        <>
            <ProductSeo product={product} canonical={canonical} />

            <ImageHero
                picture={{
                    src: '/images/hero/finance-hero.webp',
                    small: '/images/hero/finance-hero-1280.webp',
                    base: 'bg-[#012979]',
                }}
                title={product.headline}
                intro={product.subhead}
            >
                <PrimaryAction
                    product={product}
                    trialDays={trialDays}
                    className={onBlue}
                />
                <a href="#plans" className={outlineOnBlue}>
                    See plans
                </a>
            </ImageHero>

            {/* Highlights */}
            {product.highlights && product.highlights.length > 0 && (
                <section
                    aria-label="Highlights"
                    className="border-b border-site-line bg-white"
                >
                    <ul
                        className={`${container} grid gap-x-6 gap-y-4 py-6 sm:grid-cols-2 lg:grid-cols-5 lg:py-8`}
                    >
                        {product.highlights.map((highlight) => (
                            <li
                                key={highlight}
                                className="border-l-2 border-site-accent pl-3 text-sm font-semibold text-site-ink"
                            >
                                {highlight}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/* Overview beside the reports sheet */}
            <section
                aria-labelledby="overview-heading"
                className={`bg-white ${section}`}
            >
                <div
                    className={`${container} grid items-start gap-12 lg:grid-cols-[1.15fr_1fr] lg:gap-16`}
                >
                    <div>
                        <SectionHeading
                            id="overview-heading"
                            align="left"
                            title="Books that always balance"
                        />
                        <div className="mt-6 space-y-4 text-base leading-relaxed text-site-body">
                            {overview.slice(0, 3).map((paragraph) => (
                                <p key={paragraph}>{paragraph}</p>
                            ))}
                        </div>
                    </div>
                    <ReportSheet />
                </div>
            </section>

            {/* Features */}
            {product.features.length > 0 && (
                <section
                    aria-labelledby="features-heading"
                    className={`bg-site-wash ${section}`}
                >
                    <div className={container}>
                        <SectionHeading
                            id="features-heading"
                            title="Everything your books need"
                            lead={product.audience}
                        />
                        <FeatureTabs features={product.features} />
                    </div>
                </section>
            )}

            {/* Connected apps */}
            {product.connections && (
                <section
                    aria-labelledby="connections-heading"
                    className={`site-dark bg-site-navy ${section}`}
                >
                    <div className={container}>
                        <SectionHeading
                            id="connections-heading"
                            title="Fed by the rest of your business"
                            lead={product.connections.intro}
                        />
                        <ul className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {product.connections.links.map((link) => (
                                <li key={link.slug}>
                                    <Link
                                        href={link.url}
                                        className="group flex h-full flex-col rounded-xl bg-site-navy-light p-6 ring-1 ring-site-navy-line transition-colors hover:ring-site-highlight"
                                    >
                                        <h3 className="text-lg font-semibold text-white">
                                            {link.name}
                                        </h3>
                                        <p
                                            className={`mt-2 flex-1 text-sm leading-relaxed ${onBlueSoft}`}
                                        >
                                            {link.body}
                                        </p>
                                        <span className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-white">
                                            View {link.name}
                                            <ArrowRight
                                                className="size-4 transition-transform group-hover:translate-x-0.5"
                                                aria-hidden
                                            />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                            <li className="flex flex-col justify-center rounded-xl border border-dashed border-site-navy-line p-6">
                                <p className="text-lg font-semibold text-white">
                                    Or on its own
                                </p>
                                <p
                                    className={`mt-2 text-sm leading-relaxed ${onBlueSoft}`}
                                >
                                    Use Finance without an ERP; it connects
                                    automatically if you add one later.
                                </p>
                            </li>
                        </ul>
                    </div>
                </section>
            )}

            {/* How it works */}
            {product.workflow.length > 0 && (
                <section
                    aria-labelledby="workflow-heading"
                    className={`bg-white ${section}`}
                >
                    <div className={container}>
                        <SectionHeading
                            id="workflow-heading"
                            title="How the month runs"
                        />
                        <ol className="relative mt-12 grid gap-8 md:grid-cols-3">
                            <span
                                aria-hidden
                                className="absolute inset-x-[16%] top-7 hidden h-0.5 bg-site-tint-strong md:block"
                            />
                            {product.workflow.map((step, i) => (
                                <li
                                    key={step.title}
                                    className="relative text-center"
                                >
                                    <span className="mx-auto flex size-14 items-center justify-center rounded-full bg-site-navy text-xl font-bold text-white ring-8 ring-white">
                                        {i + 1}
                                    </span>
                                    <h3 className="mt-5 text-lg font-semibold text-site-ink">
                                        {step.title}
                                    </h3>
                                    <p className="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-site-muted">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            )}

            {/* Who it is for */}
            <section
                aria-labelledby="fit-heading"
                className="border-t border-site-line bg-white pb-16 sm:pb-20 lg:pb-24"
            >
                <div className={`${container} pt-16 sm:pt-20`}>
                    <div
                        className={`${panel} grid gap-8 p-6 sm:p-10 lg:grid-cols-[1.2fr_1fr] lg:items-center`}
                    >
                        <div>
                            <h2
                                id="fit-heading"
                                className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                            >
                                Made for businesses and their accountants
                            </h2>
                            <p className="mt-3 leading-relaxed text-site-body">
                                {product.audience}
                            </p>
                            {overview[4] && (
                                <p className="mt-3 text-sm leading-relaxed text-site-muted">
                                    {overview[4]}
                                </p>
                            )}
                        </div>
                        <dl className="grid gap-5 border-t border-site-line pt-6 sm:grid-cols-3 lg:grid-cols-1 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8">
                            {glance.map(({ term, values }) => (
                                <div key={term}>
                                    <dt className="text-sm font-semibold text-site-muted">
                                        {term}
                                    </dt>
                                    {values.map((value) => (
                                        <dd
                                            key={value}
                                            className="mt-1 font-medium text-site-ink"
                                        >
                                            {value}
                                        </dd>
                                    ))}
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </section>

            {/* Plans */}
            <section
                id="plans"
                aria-labelledby="plans-heading"
                className={`scroll-mt-20 border-y border-site-line bg-site-wash ${section}`}
            >
                <div className={container}>
                    <SectionHeading
                        id="plans-heading"
                        title="Plans"
                        lead={
                            comingSoon
                                ? `${product.name} is not released yet. Join the waitlist and we will tell you when it opens.`
                                : product.trial
                                  ? `${product.name} is open for a ${trialDays}-day free trial. Nothing is charged unless you choose a plan.`
                                  : `${product.name} is ${product.status === 'early_access' ? 'in early access' : 'available'}. Talk to us to set it up for your business.`
                        }
                    />
                    {plans.length > 0 && (
                        <ul className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <FinancePlan key={plan.code} plan={plan} />
                            ))}
                        </ul>
                    )}
                </div>
            </section>

            {/* Questions */}
            {product.faqs.length > 0 && (
                <section
                    aria-labelledby="product-faq-heading"
                    className={`${container} grid gap-6 ${section} lg:grid-cols-[0.8fr_1.2fr] lg:gap-14`}
                >
                    <h2
                        id="product-faq-heading"
                        className="text-[1.625rem] leading-tight font-semibold tracking-tight text-site-ink sm:text-3xl"
                    >
                        Questions
                    </h2>
                    <FaqList items={product.faqs} />
                </section>
            )}

            {/* Closing call to action */}
            <section
                aria-labelledby="finance-cta-heading"
                className="px-4 pb-16 sm:px-6 sm:pb-24 lg:px-8"
            >
                <div className="site-dark mx-auto grid max-w-7xl items-center gap-8 rounded-xl bg-[#012979] px-6 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1fr_auto]">
                    <div className="text-center lg:text-left">
                        <h2
                            id="finance-cta-heading"
                            className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl sm:leading-tight"
                        >
                            Start from where your books are today
                        </h2>
                        <p className={`mt-4 max-w-xl ${onBlueSoft}`}>
                            Bring your opening balances and carry on. Add an ERP
                            whenever you are ready, and its sales and purchases
                            post on their own.
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-center gap-3">
                        <PrimaryAction
                            product={product}
                            trialDays={trialDays}
                            className={onBlue}
                        />
                        <Link href="/contact" className={outlineOnBlue}>
                            Talk to us
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

/**
 * The statements Finance runs, drawn as a ledger sheet: a navy header,
 * numbered ruled lines and a closing double rule. Decorative framing for
 * a real list, so the lines are a list.
 */
function ReportSheet() {
    return (
        <div className="overflow-hidden rounded-xl border-2 border-site-ink bg-white">
            <div className="flex items-center justify-between bg-site-navy px-5 py-4">
                <p className="text-sm font-bold tracking-[0.14em] text-white uppercase">
                    Reports
                </p>
                <p className={`text-xs font-semibold ${onBlueSoft}`}>
                    Any dates, any period
                </p>
            </div>
            <ol className="divide-y divide-site-line">
                {reports.map((report, i) => (
                    <li
                        key={report}
                        className="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 px-5 py-3 text-sm"
                    >
                        <span className="font-mono text-xs text-site-muted tabular-nums">
                            {String(i + 1).padStart(2, '0')}
                        </span>
                        <span className="font-medium text-site-ink">
                            {report}
                        </span>
                        <Check
                            className="size-4 text-site-accent"
                            aria-label="Included"
                        />
                    </li>
                ))}
            </ol>
            <div className="border-t-4 border-double border-site-ink px-5 py-3 text-xs text-site-muted">
                Compared with another period. The main statements export to
                Excel.
            </div>
        </div>
    );
}

/** The features in four tabs; on phones the tabs scroll sideways. */
function FeatureTabs({ features }: { features: CatalogEntry['features'] }) {
    const [active, setActive] = useState(0);
    const placed = featureTabs.flatMap((tab) => tab.titles);
    const tabs = featureTabs.map((tab, i) => ({
        label: tab.label,
        features: [
            ...features.filter((f) => tab.titles.includes(f.title)),
            ...(i === featureTabs.length - 1
                ? features.filter((f) => !placed.includes(f.title))
                : []),
        ],
    }));
    const current = tabs[active];

    return (
        <div className="mt-10">
            <div
                role="tablist"
                aria-label="Feature groups"
                className="site-rail -mx-4 flex gap-2 overflow-x-auto px-4 pb-2 sm:mx-0 sm:justify-center sm:px-0"
            >
                {tabs.map((tab, i) => (
                    <button
                        key={tab.label}
                        type="button"
                        role="tab"
                        id={`feature-tab-${i}`}
                        aria-selected={active === i}
                        aria-controls="feature-panel"
                        onClick={() => setActive(i)}
                        className={`h-11 shrink-0 rounded-full px-5 text-sm font-semibold whitespace-nowrap transition-colors ${active === i ? 'bg-site-navy text-white' : 'border border-site-line bg-white text-site-ink hover:border-site-accent'}`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>
            <ul
                id="feature-panel"
                role="tabpanel"
                aria-labelledby={`feature-tab-${active}`}
                className="mt-8 grid gap-5 sm:grid-cols-2"
            >
                {current.features.map((feature) => (
                    <li
                        key={feature.title}
                        className={`${panel} border-t-4 border-t-site-accent p-6 sm:p-7`}
                    >
                        <h3 className="text-lg font-semibold text-site-ink">
                            {feature.title}
                        </h3>
                        <p className="mt-2 text-sm leading-relaxed text-site-muted">
                            {feature.body}
                        </p>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Same destinations as the shared plan card, in a box of even height. */
function FinancePlan({ plan }: { plan: Plan }) {
    const standalone = !plan.isBundle;
    const per = {
        monthly: '/ month',
        yearly: '/ year',
        custom: 'billed by agreement',
    }[plan.interval];

    return (
        <li
            className={`${card} flex h-full flex-col p-5 ${standalone ? 'border-site-accent ring-1 ring-site-accent' : ''}`}
        >
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-lg font-semibold tracking-tight text-slate-900">
                    {plan.name}
                </h3>
                <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${standalone ? 'bg-site-accent-ink text-white' : 'bg-slate-100 text-slate-700'}`}
                >
                    {standalone ? 'Finance plan' : 'Bundle'}
                </span>
            </div>
            {plan.product && (
                <p className="mt-1 text-sm font-semibold">
                    <Link
                        href={`/erp/${plan.product.code}`}
                        className="text-slate-900 underline-offset-4 hover:underline"
                    >
                        {plan.product.name}
                    </Link>
                    {plan.product.status === 'pilot' && ' · Early access'}
                </p>
            )}
            {plan.description && (
                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                    {plan.description}
                </p>
            )}
            <p className="mt-4 flex flex-wrap items-baseline gap-x-2">
                <span className="font-public-display text-3xl font-bold tracking-tight text-slate-900 tabular-nums">
                    {plan.price}
                </span>
                {plan.price !== 'Free' && (
                    <span className="text-sm text-slate-600">{per}</span>
                )}
            </p>
            <div className="mt-5 flex-1 border-t border-site-line pt-4">
                <p className="text-sm font-semibold text-slate-900">Includes</p>
                <ul className="mt-2 space-y-2 text-sm text-slate-700">
                    {plan.applications.map((application) => (
                        <li key={application} className="flex gap-2">
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                aria-hidden
                            />
                            {application}
                        </li>
                    ))}
                </ul>
            </div>
            {plan.product ? (
                <Link href="/contact" className={`mt-5 ${button.outline}`}>
                    {erpCallToAction[plan.product.status]}
                    <span className="sr-only"> about {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            ) : (
                <Link
                    href="/start"
                    className={`mt-5 ${standalone ? button.primary : button.outline}`}
                >
                    Start free trial
                    <span className="sr-only"> of {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            )}
        </li>
    );
}
