import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { waitlistUrl } from '@/sites/public/components/blocks';
import {
    CatalogStatus,
    catalogIcon,
    connectivityLabels,
    licensingLabels,
    platformLabels,
} from '@/sites/public/components/catalog';
import type { CatalogEntry, Platform } from '@/sites/public/components/catalog';
import FaqList from '@/sites/public/components/faq';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import Seo, { organization } from '@/sites/public/components/seo';
import {
    button,
    card,
    container,
    panel,
    trialUrl,
} from '@/sites/public/components/styles';

export type ProductProfileProps = {
    product: CatalogEntry;
    plans: Plan[];
    trialDays: number;
    canonical: string;
};

type Props = ProductProfileProps & {
    /** Who it is set up for, in "set it up for your …": pharmacy, business. */
    customer?: string;
    /** A plan's card; the shared PlanCard unless the page has its own. */
    renderPlan?: (plan: Plan) => ReactNode;
};

const operatingSystems: Record<Platform, string> = {
    web: 'Web',
    desktop: 'Windows',
    android: 'Android',
    ios: 'iOS',
};

/**
 * A product's own page as a profile: what it is on a dark band at the
 * top, the details in one column beside a short "at a glance" card, then
 * plans and questions. Words, features, platforms and plans come from the
 * catalog and the registry. No green type: headings and links are
 * near-black, green is kept for icons and buttons.
 */
export default function ProductProfile({
    product,
    plans,
    trialDays,
    canonical,
    customer = 'business',
    renderPlan = (plan) => <PlanCard key={plan.code} plan={plan} />,
}: Props) {
    const origin = new URL(canonical).origin;
    const comingSoon = product.status === 'coming_soon';
    const demoUrl = `/contact?subject=${encodeURIComponent(`Demo: ${product.name}`)}`;
    const ProductIcon = catalogIcon(product.icon);

    const primary = (style: string) =>
        product.trial ? (
            <a href={trialUrl(product.trial, product.trialProduct)} className={style}>
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

    const glance = [
        { term: "Who it's for", values: [product.audience] },
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
                    ? `${licensingLabels[licence]} (standalone offline edition)`
                    : licensingLabels[licence],
            ),
        },
    ];

    const apps = [
        { title: 'Desktop application', points: product.offering.desktop },
        { title: 'Mobile application', points: product.offering.mobile },
    ].filter((app) => app.points.length > 0);

    const heading = 'text-2xl font-semibold tracking-tight text-slate-900';
    // The hero's main action: white on the dark green, not green on green.
    const onDark =
        'inline-flex items-center justify-center gap-2 rounded-lg bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';

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

            {/* Profile header */}
            {/* White and light grey type on the brand's dark green. */}
            <section className="site-dark bg-[#063519]">
                <div className={`${container} pt-24 pb-10 sm:pt-28 sm:pb-14`}>
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
                        <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-2xl bg-white text-site-accent-ink sm:size-20">
                            <ProductIcon
                                className="size-8 sm:size-10"
                                aria-hidden
                            />
                        </span>
                        <div className="flex-1">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl">
                                    {product.name}
                                </h1>
                                <CatalogStatus status={product.status} />
                            </div>
                            <p className="mt-2 text-lg font-semibold text-white">
                                {product.headline}
                            </p>
                            <p className="mt-3 max-w-3xl leading-relaxed text-pretty text-slate-300">
                                {product.subhead}
                            </p>
                            <div className="mt-6 flex flex-wrap gap-3">
                                {primary(onDark)}
                                <a href="#plans" className={button.outlineDark}>
                                    See plans
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Details beside "at a glance" */}
            <div
                className={`${container} grid gap-10 py-12 sm:py-16 lg:grid-cols-[1fr_20rem] lg:gap-14`}
            >
                <div className="space-y-12">
                    <section aria-labelledby="overview-heading">
                        <h2 id="overview-heading" className={heading}>
                            Overview
                        </h2>
                        <div className="mt-3 space-y-4 leading-relaxed text-slate-700">
                            {(product.overview?.length
                                ? product.overview
                                : [product.summary]
                            ).map((paragraph) => (
                                <p key={paragraph}>{paragraph}</p>
                            ))}
                        </div>
                    </section>

                    {product.features.length > 0 && (
                        <section aria-labelledby="features-heading">
                            <h2 id="features-heading" className={heading}>
                                Features
                            </h2>
                            <ul className="mt-5 divide-y divide-site-line border-y border-site-line">
                                {product.features.map((feature) => (
                                    <li key={feature.title} className="py-4">
                                        <h3 className="font-semibold text-slate-900">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                            {feature.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {product.connections && (
                        <section aria-labelledby="connections-heading">
                            <h2 id="connections-heading" className={heading}>
                                Linked to your other apps
                            </h2>
                            <p className="mt-3 leading-relaxed text-slate-700">
                                {product.connections.intro}
                            </p>
                            <ul className="mt-5 grid gap-4 sm:grid-cols-2">
                                {product.connections.links.map((link) => {
                                    const Icon = catalogIcon(link.icon);

                                    return (
                                        <li key={link.slug}>
                                            <Link
                                                href={link.url}
                                                className={`${card} group flex h-full flex-col p-5`}
                                            >
                                                <span className="inline-flex size-10 items-center justify-center rounded-lg bg-site-accent/10 text-site-accent-ink">
                                                    <Icon
                                                        className="size-5"
                                                        aria-hidden
                                                    />
                                                </span>
                                                <h3 className="mt-4 font-semibold text-slate-900">
                                                    {link.name}
                                                </h3>
                                                <p className="mt-1 flex-1 text-sm leading-relaxed text-slate-600">
                                                    {link.body}
                                                </p>
                                                <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-900">
                                                    View {link.name}
                                                    <ArrowRight
                                                        className="size-4 transition-transform group-hover:translate-x-0.5"
                                                        aria-hidden
                                                    />
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    )}

                    {product.workflow.length > 0 && (
                        <section aria-labelledby="workflow-heading">
                            <h2 id="workflow-heading" className={heading}>
                                How it works
                            </h2>
                            <ol className="mt-5 space-y-4">
                                {product.workflow.map((step, i) => (
                                    <li key={step.title} className="flex gap-4">
                                        <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-site-accent-ink text-sm font-bold text-white">
                                            {i + 1}
                                        </span>
                                        <div>
                                            <h3 className="font-semibold text-slate-900">
                                                {step.title}
                                            </h3>
                                            <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                                {step.body}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}

                    {apps.length > 0 && (
                        <section aria-labelledby="apps-heading">
                            <h2 id="apps-heading" className={heading}>
                                Desktop and mobile
                            </h2>
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                {apps.map(({ title, points }) => (
                                    <div key={title} className={`${panel} p-5`}>
                                        <h3 className="font-semibold text-slate-900">
                                            {title}
                                        </h3>
                                        <ul className="mt-3 space-y-2 text-sm text-slate-700">
                                            {points.map((point) => (
                                                <li
                                                    key={point}
                                                    className="flex items-start gap-2"
                                                >
                                                    <Check
                                                        className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                                        aria-hidden
                                                    />
                                                    {point}
                                                </li>
                                            ))}
                                        </ul>
                                        {title === 'Desktop application' && (
                                            <Link
                                                href="/downloads"
                                                className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-slate-900 underline-offset-4 hover:underline"
                                            >
                                                Download for Windows
                                                <ArrowRight
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </Link>
                                        )}
                                    </div>
                                ))}
                            </div>
                            <p className="mt-4 text-sm text-slate-600">
                                The desktop and mobile apps do less than the web
                                version, and what works offline differs between
                                them.
                            </p>
                        </section>
                    )}
                </div>

                {/* At a glance */}
                <aside aria-labelledby="glance-heading" className="lg:pt-1">
                    <div className={`${panel} p-6 lg:sticky lg:top-24`}>
                        <h2
                            id="glance-heading"
                            className="text-lg font-semibold text-slate-900"
                        >
                            At a glance
                        </h2>
                        <dl className="mt-4 space-y-4 text-sm">
                            {glance.map(({ term, values }) => (
                                <div key={term}>
                                    <dt className="font-semibold text-slate-500">
                                        {term}
                                    </dt>
                                    {values.map((value) => (
                                        <dd
                                            key={value}
                                            className="mt-1 text-slate-900"
                                        >
                                            {value}
                                        </dd>
                                    ))}
                                </div>
                            ))}
                            {product.includes.length > 0 && (
                                <div>
                                    <dt className="font-semibold text-slate-500">
                                        Applications
                                    </dt>
                                    {product.includes.map((application) => (
                                        <dd
                                            key={application.code}
                                            className="mt-1 flex items-center gap-1.5 text-slate-900"
                                        >
                                            {application.requirement ===
                                            'required' ? (
                                                <Check
                                                    className="size-4 shrink-0 text-site-accent-ink"
                                                    aria-label="Included"
                                                />
                                            ) : (
                                                <Plus
                                                    className="size-4 shrink-0 text-slate-400"
                                                    aria-label="Optional add-on"
                                                />
                                            )}
                                            {application.listed &&
                                            application.url !==
                                                `/${product.url}` ? (
                                                <Link
                                                    href={application.url}
                                                    className="hover:underline"
                                                >
                                                    {application.name}
                                                </Link>
                                            ) : (
                                                application.name
                                            )}
                                        </dd>
                                    ))}
                                </div>
                            )}
                        </dl>
                        <div className="mt-6 border-t border-site-line pt-5">
                            {primary(`${button.primary} w-full`)}
                        </div>
                    </div>
                </aside>
            </div>

            {/* Plans */}
            <section
                id="plans"
                aria-labelledby="plans-heading"
                className="scroll-mt-20 border-y border-site-line bg-site-soft"
            >
                <div className={`${container} py-12 sm:py-16`}>
                    <h2 id="plans-heading" className={heading}>
                        Plans
                    </h2>
                    <p className="mt-2 max-w-2xl text-slate-600">
                        {comingSoon
                            ? `${product.name} is not released yet. Join the waitlist and we will tell you when it opens.`
                            : product.trial
                              ? `${product.name} is open for a ${trialDays}-day free trial. Nothing is charged unless you choose a plan.`
                              : `${product.name} is ${product.status === 'early_access' ? 'in early access' : 'available'}. Talk to us to set it up for your ${customer}.`}
                    </p>
                    {plans.length > 0 && (
                        <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map(renderPlan)}
                        </ul>
                    )}
                </div>
            </section>

            {/* Questions */}
            {product.faqs.length > 0 && (
                <section
                    aria-labelledby="product-faq-heading"
                    className={`${container} grid gap-6 py-12 sm:py-16 lg:grid-cols-[0.8fr_1.2fr] lg:gap-14`}
                >
                    <h2 id="product-faq-heading" className={heading}>
                        Questions
                    </h2>
                    <FaqList items={product.faqs} />
                </section>
            )}
        </>
    );
}
