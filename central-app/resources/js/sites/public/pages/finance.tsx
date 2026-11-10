import { Link } from '@inertiajs/react';
import { ArrowRight, BarChart3, Check, Users } from 'lucide-react';
import { waitlistUrl } from '@/sites/public/components/blocks';
import {
    catalogIcon,
    connectivityLabels,
    licensingLabels,
    platformLabels,
    type CatalogEntry,
    type Platform,
} from '@/sites/public/components/catalog';
import { erpCallToAction } from '@/sites/public/components/erp-product';
import FaqList from '@/sites/public/components/faq';
import { Accent } from '@/sites/public/components/page-hero';
import type { Plan } from '@/sites/public/components/plan-card';
import Seo, { organization } from '@/sites/public/components/seo';
import {
    button,
    card,
    container,
    display,
    trialUrl,
} from '@/sites/public/components/styles';

type Props = {
    product: CatalogEntry;
    plans: Plan[];
    trialDays: number;
    canonical: string;
};

const operatingSystems: Record<Platform, string> = {
    web: 'Web',
    desktop: 'Windows',
    android: 'Android',
    ios: 'iOS',
};

/** Compact marks for the hero. Existing catalog icons, not a new set. */
const heroMarks = ['landmark', 'receipt', 'calculator', 'file-spreadsheet'];

/**
 * Finance & Accounting only. Other products stay on the shared landing
 * page. Words, platforms and plans still come from the catalog and registry.
 */
export default function FinancePage({
    product,
    plans,
    trialDays,
    canonical,
}: Props) {
    const origin = new URL(canonical).origin;
    const comingSoon = product.status === 'coming_soon';
    const features = product.features.map((feature) => ({
        ...feature,
        icon: catalogIcon(feature.icon),
    }));
    const demoUrl = `/contact?subject=${encodeURIComponent(`Demo: ${product.name}`)}`;

    const primary = (style: string) =>
        product.trial ? (
            <a href={trialUrl(product.trial)} className={style}>
                Start Your {trialDays}-Day Free Trial
                <ArrowRight className="size-4" aria-hidden />
            </a>
        ) : comingSoon ? (
            <Link href={waitlistUrl(product.name)} className={style}>
                Join the waitlist
                <ArrowRight className="size-4" aria-hidden />
            </Link>
        ) : (
            <Link href={demoUrl} className={style}>
                Request a Demo
                <ArrowRight className="size-4" aria-hidden />
            </Link>
        );

    const facts = [
        {
            term: 'Platforms',
            values: product.offering.platforms.map(
                (platform) => platformLabels[platform],
            ),
        },
        {
            term: 'Connectivity',
            values: product.offering.connectivity.map(
                (mode) => connectivityLabels[mode],
            ),
        },
        {
            term: 'Licensing',
            values: product.offering.licensing.map((licence) =>
                licence === 'one_time'
                    ? `${licensingLabels[licence]} (standalone offline edition, price on request)`
                    : licensingLabels[licence],
            ),
        },
    ];

    return (
        <>
            <Seo
                title={product.seoTitle}
                description={product.seoDescription}
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@graph': [
                        {
                            '@type': 'SoftwareApplication',
                            name: product.name,
                            applicationCategory: 'BusinessApplication',
                            operatingSystem: product.offering.platforms
                                .map((platform) => operatingSystems[platform])
                                .join(', '),
                            description: product.seoDescription,
                            url: canonical,
                            publisher: organization(origin),
                        },
                        {
                            '@type': 'BreadcrumbList',
                            itemListElement: [
                                {
                                    '@type': 'ListItem',
                                    position: 1,
                                    name: 'Home',
                                    item: `${origin}/`,
                                },
                                {
                                    '@type': 'ListItem',
                                    position: 2,
                                    name: 'Products',
                                    item: `${origin}/products`,
                                },
                                {
                                    '@type': 'ListItem',
                                    position: 3,
                                    name: product.name,
                                    item: canonical,
                                },
                            ],
                        },
                        ...(product.faqs.length > 0
                            ? [
                                  {
                                      '@type': 'FAQPage',
                                      mainEntity: product.faqs.map((faq) => ({
                                          '@type': 'Question',
                                          name: faq.question,
                                          acceptedAnswer: {
                                              '@type': 'Answer',
                                              text: faq.answer,
                                          },
                                      })),
                                  },
                              ]
                            : []),
                    ],
                }}
            />

            <section className="site-dark relative overflow-hidden bg-[#17313f] bg-[linear-gradient(115deg,#17313f_0%,#203b49_100%)]">
                <div
                    className={`${container} flex items-center pt-[4.5rem] pb-5 sm:pb-6 lg:min-h-[35vh]`}
                >
                    <div className="grid w-full items-center gap-6 lg:grid-cols-[minmax(0,1.7fr)_minmax(15rem,0.7fr)] lg:gap-8">
                        <div>
                            <h1
                                className={`text-[1.875rem] leading-[1.08] sm:text-[2.125rem] lg:text-[2.625rem] xl:text-[3rem] ${display}`}
                            >
                                {product.name}, <Accent>simplified.</Accent>
                            </h1>
                            <p className="mt-3 max-w-[34rem] text-base leading-relaxed text-pretty text-[#b8c8d0] sm:text-lg">
                                Manage your books, reconcile banks, track
                                budgets, and generate financial reports—all in
                                one place.
                            </p>
                            <div className="mt-5">{primary(button.primary)}</div>
                        </div>
                        <div
                            aria-hidden
                            className="hidden w-full max-w-sm grid-cols-2 gap-2 lg:grid lg:justify-self-end"
                        >
                            {heroMarks.map((name) => {
                                const Icon = catalogIcon(name);

                                return (
                                    <span
                                        key={name}
                                        className="flex h-16 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-site-highlight"
                                    >
                                        <Icon className="size-6" />
                                    </span>
                                );
                            })}
                            <span className="col-span-2 flex h-12 items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 text-sm font-semibold text-white">
                                <BarChart3 className="size-5 text-site-highlight" />
                                Reports from the same ledger
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section
                aria-labelledby="audience-heading"
                className={`${container} py-9 sm:py-12`}
            >
                <div className="flex items-start gap-4 rounded-2xl border border-site-line bg-white p-5 sm:p-6">
                    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink">
                        <Users className="size-5" aria-hidden />
                    </span>
                    <div>
                        <h2
                            id="audience-heading"
                            className="text-sm font-semibold tracking-wide text-slate-500 uppercase"
                        >
                            Who it's for
                        </h2>
                        <p className="mt-1 max-w-3xl font-semibold text-site-ink">
                            {product.audience}
                        </p>
                    </div>
                </div>
            </section>

            {features.length > 0 && (
                <section
                    aria-labelledby="features-heading"
                    className="border-t border-site-line"
                >
                    <div className={`${container} py-10 sm:py-14 lg:py-16`}>
                        <h2
                            id="features-heading"
                            className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                        >
                            What <Accent>{product.name}</Accent> does
                        </h2>
                        {comingSoon && (
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">
                                What it is being built to do. Details may change
                                before release.
                            </p>
                        )}
                        <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {features.map((feature) => {
                                const Icon = feature.icon;

                                return (
                                <li
                                    key={feature.title}
                                    className="rounded-2xl border border-site-line bg-white p-4"
                                >
                                    <span className="inline-flex size-9 items-center justify-center rounded-lg bg-site-accent/10 text-site-accent-ink">
                                        <Icon className="size-4" aria-hidden />
                                    </span>
                                    <h3 className="mt-3 text-base font-semibold text-site-ink">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                        {feature.body}
                                    </p>
                                </li>
                                );
                            })}
                        </ul>
                    </div>
                </section>
            )}

            {product.workflow.length > 0 && (
                <section
                    aria-labelledby="workflow-heading"
                    className="border-t border-site-line bg-site-soft"
                >
                    <div className={`${container} py-10 sm:py-14 lg:py-16`}>
                        <h2
                            id="workflow-heading"
                            className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                        >
                            How it works
                        </h2>
                        <ol className="mt-6 grid gap-3 md:grid-cols-3">
                            {product.workflow.map((step, i) => (
                                <li
                                    key={step.title}
                                    className="rounded-2xl border border-site-line bg-white p-5"
                                >
                                    <span className="font-public-display text-sm font-bold text-site-accent-ink">
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    <h3 className="mt-2 text-lg font-semibold text-site-ink">
                                        {step.title}
                                    </h3>
                                    <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            )}

            <section
                id="platforms"
                aria-labelledby="platforms-heading"
                className="scroll-mt-24 border-t border-site-line"
            >
                <div className={`${container} py-10 sm:py-14 lg:py-16`}>
                    <h2
                        id="platforms-heading"
                        className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                    >
                        Platforms and licensing
                    </h2>
                    {comingSoon && (
                        <p className="mt-2 text-sm text-slate-600">
                            What is planned for release. Details may change.
                        </p>
                    )}
                    <dl className="mt-6 grid gap-3 md:grid-cols-3">
                        {facts.map(({ term, values }) => (
                            <div
                                key={term}
                                className="rounded-2xl border border-site-line bg-white p-5"
                            >
                                <dt className="text-sm font-semibold text-slate-500">
                                    {term}
                                </dt>
                                {values.map((value) => (
                                    <dd
                                        key={value}
                                        className="mt-2 flex items-start gap-2 text-sm font-semibold text-site-ink"
                                    >
                                        <Check
                                            className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                            aria-hidden
                                        />
                                        {value}
                                    </dd>
                                ))}
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            <section
                id="plans"
                aria-labelledby="plans-heading"
                className="scroll-mt-20 border-t border-site-line bg-site-soft"
            >
                <div className={`${container} py-10 sm:py-14 lg:py-16`}>
                    <h2
                        id="plans-heading"
                        className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                    >
                        Pricing and availability
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600 sm:text-base">
                        {comingSoon
                            ? `${product.name} is not released yet. Join the waitlist and we will tell you when it opens.`
                            : product.trial
                              ? `${product.name} is open for a ${trialDays}-day free trial. Nothing is charged unless you choose a plan.`
                              : `${product.name} is ${product.status === 'early_access' ? 'in early access' : 'available'}. Talk to us to set it up for your business.`}
                    </p>
                    {plans.length > 0 && (
                        <ul className="mt-6 grid items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <FinancePlan key={plan.code} plan={plan} />
                            ))}
                        </ul>
                    )}
                </div>
            </section>

            {product.faqs.length > 0 && (
                <section aria-labelledby="product-faq-heading">
                    <div
                        className={`${container} grid gap-6 py-10 sm:py-14 lg:grid-cols-[0.8fr_1.2fr] lg:gap-12 lg:py-16`}
                    >
                        <h2
                            id="product-faq-heading"
                            className="text-2xl font-semibold tracking-tight text-site-ink sm:text-3xl"
                        >
                            Questions about <Accent>{product.name}</Accent>
                        </h2>
                        <FaqList items={product.faqs} />
                    </div>
                </section>
            )}

            <section className="px-4 pb-12 sm:px-6 sm:pb-16 lg:px-8">
                <div className="site-dark mx-auto grid max-w-7xl items-center gap-6 rounded-2xl bg-[#17313f] px-6 py-8 sm:px-10 lg:grid-cols-[1fr_auto]">
                    <div>
                        <h2 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                            Run your business.{' '}
                            <Accent>All connected.</Accent>
                        </h2>
                        <p className="mt-2 max-w-xl text-sm leading-relaxed text-[#b8c8d0] sm:text-base">
                            Bring your financial operations and business
                            applications together with Miles Global Softwares.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {primary(button.light)}
                        <Link href="/pricing" className={button.outlineDark}>
                            See pricing
                        </Link>
                    </div>
                </div>
            </section>
        </>
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
            className={`${card} flex h-full flex-col p-5 ${standalone ? 'border-site-accent ring-1 ring-site-accent/40' : ''}`}
        >
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-lg font-semibold tracking-tight text-site-ink">
                    {plan.name}
                </h3>
                <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${standalone ? 'bg-site-accent-ink text-white' : 'bg-site-wash text-site-accent-ink'}`}
                >
                    {standalone ? 'Finance plan' : 'Bundle'}
                </span>
            </div>
            {plan.product && (
                <p className="mt-1 text-sm font-semibold">
                    <Link
                        href={`/erp/${plan.product.code}`}
                        className="text-site-accent-ink hover:underline"
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
                <span className="font-public-display text-3xl font-bold tracking-tight text-site-ink tabular-nums">
                    {plan.price}
                </span>
                {plan.price !== 'Free' && (
                    <span className="text-sm text-slate-600">{per}</span>
                )}
            </p>
            <div className="mt-5 flex-1 border-t border-site-line pt-4">
                <p className="text-sm font-semibold text-site-ink">Includes</p>
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
