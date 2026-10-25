import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Plus, Users } from 'lucide-react';
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
    waitlistUrl,
} from '@/sites/public/components/blocks';
import {
    CatalogStatus,
    CatalogTile,
    catalogIcon,
    categoryLabels,
    categoryPaths,
    connectivityLabels,
    licensingLabels,
    platformLabels,
} from '@/sites/public/components/catalog';
import type { CatalogEntry, Platform } from '@/sites/public/components/catalog';
import FaqList from '@/sites/public/components/faq';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { ProductTile } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization } from '@/sites/public/components/seo';
import {
    button,
    card,
    panel,
    trialUrl,
} from '@/sites/public/components/styles';

type Props = {
    product: CatalogEntry;
    plans: Plan[];
    trialDays: number;
    canonical: string;
};

/** schema.org operatingSystem for each platform. */
const operatingSystems: Record<Platform, string> = {
    web: 'Web',
    desktop: 'Windows',
    android: 'Android',
    ios: 'iOS',
};

/**
 * One product's page, ERP or application: the same sections for each,
 * words from the catalog file, status, contents and plans from the
 * registry. Only a product open for trials offers one.
 */
export default function LandingPage({
    product,
    plans,
    trialDays,
    canonical,
}: Props) {
    const origin = new URL(canonical).origin;
    const comingSoon = product.status === 'coming_soon';
    const demoUrl = `/contact?subject=${encodeURIComponent(`Demo: ${product.name}`)}`;
    const features = product.features.map((feature) => ({
        ...feature,
        icon: catalogIcon(feature.icon),
    }));

    const primary = product.trial ? (
        <a href={trialUrl(product.trial)} className={button.light}>
            Start Your {trialDays}-Day Free Trial
            <ArrowRight className="size-4" aria-hidden />
        </a>
    ) : comingSoon ? (
        <Link href={waitlistUrl(product.name)} className={button.light}>
            Join the waitlist
            <ArrowRight className="size-4" aria-hidden />
        </Link>
    ) : (
        <Link href={demoUrl} className={button.light}>
            Request a Demo
            <ArrowRight className="size-4" aria-hidden />
        </Link>
    );

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
                                    name: categoryLabels[product.category],
                                    item: `${origin}${categoryPaths[product.category]}`,
                                },
                                {
                                    '@type': 'ListItem',
                                    position: 3,
                                    name: product.name,
                                    item: canonical,
                                },
                            ],
                        },
                        // The questions answered on the page, for rich results.
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

            {/* Hero */}
            <PageHero
                above={
                    <>
                        <Link
                            href={categoryPaths[product.category]}
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-300 transition-colors hover:text-white"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            {categoryLabels[product.category]}
                        </Link>
                        <div className="mt-8 mb-6 flex items-center gap-3">
                            <CatalogTile icon={product.icon} size="lg" />
                            <span className="flex flex-col items-start gap-1.5 text-left">
                                <span className="font-semibold text-white">
                                    {product.name}
                                </span>
                                <CatalogStatus status={product.status} />
                            </span>
                        </div>
                    </>
                }
                title={product.headline}
                intro={product.subhead}
            >
                <div className="mt-9 flex flex-wrap justify-center gap-3">
                    {primary}
                    {product.trial && (
                        <Link href={demoUrl} className={button.outlineDark}>
                            Request a Demo
                        </Link>
                    )}
                    {plans.length > 0 && (
                        <a href="#plans" className={button.outlineDark}>
                            See plans
                        </a>
                    )}
                </div>
            </PageHero>

            {/* Overview and audience */}
            <section
                aria-labelledby="audience-heading"
                className="relative z-10 -mt-12 px-4 sm:-mt-16 sm:px-6"
            >
                <div className="mx-auto flex max-w-4xl flex-col items-center gap-4 rounded-3xl bg-white px-6 py-6 text-center shadow-xl ring-1 shadow-site-night/5 ring-site-line sm:flex-row sm:px-10 sm:text-left">
                    <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-site-accent/10 text-site-accent-ink">
                        <Users className="size-6" aria-hidden />
                    </span>
                    <div>
                        <h2
                            id="audience-heading"
                            className="text-sm font-semibold text-slate-500"
                        >
                            Who it's for
                        </h2>
                        <p className="mt-1 font-semibold">{product.audience}</p>
                    </div>
                </div>
                <p className="mx-auto mt-12 max-w-3xl text-center text-lg text-pretty text-slate-600">
                    {product.summary}
                </p>
            </section>

            {/* Features */}
            {features.length > 0 && (
                <section
                    aria-labelledby="features-heading"
                    className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
                >
                    <SectionHeading
                        id="features-heading"
                        title={
                            <>
                                What <Accent>{product.name}</Accent> does
                            </>
                        }
                        lead={
                            comingSoon
                                ? 'What it is being built to do. Details may change before release.'
                                : undefined
                        }
                    />
                    <div className="mt-10 sm:mt-14">
                        <FeatureGrid features={features} />
                    </div>
                </section>
            )}

            {/* Workflow */}
            {product.workflow.length > 0 && (
                <section
                    aria-labelledby="workflow-heading"
                    className="border-y border-site-line bg-site-soft"
                >
                    <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                        <SectionHeading
                            id="workflow-heading"
                            title={
                                <>
                                    How the work <Accent>flows</Accent>
                                </>
                            }
                        />
                        <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {product.workflow.map((step, i) => (
                                <li
                                    key={step.title}
                                    className={`${panel} p-6 sm:p-7`}
                                >
                                    <span className="site-gradient inline-flex size-10 items-center justify-center rounded-full font-public-display text-sm font-bold text-white">
                                        {String(i + 1).padStart(2, '0')}
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
                    </div>
                </section>
            )}

            {/* Where it runs, offline or not, and how it is licensed */}
            <section
                id="platforms"
                aria-labelledby="platforms-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <SectionHeading
                    id="platforms-heading"
                    title={
                        <>
                            Platforms and <Accent>licensing</Accent>
                        </>
                    }
                    lead={
                        comingSoon
                            ? 'What is planned for release. Details may change.'
                            : undefined
                    }
                    align="left"
                />
                <dl className="mt-10 grid gap-5 md:grid-cols-3">
                    {[
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
                    ].map(({ term, values }) => (
                        <div key={term} className={`${panel} p-6 sm:p-7`}>
                            <dt className="text-sm font-semibold text-slate-500">
                                {term}
                            </dt>
                            {values.map((value) => (
                                <dd
                                    key={value}
                                    className="mt-2.5 flex items-start gap-2.5 font-semibold"
                                >
                                    <Check
                                        className="mt-1 size-4 shrink-0 text-site-accent-ink"
                                        aria-hidden
                                    />
                                    {value}
                                </dd>
                            ))}
                        </div>
                    ))}
                </dl>
                {(product.offering.desktop.length > 0 ||
                    product.offering.mobile.length > 0) && (
                    <div className="mt-5 grid gap-5 md:grid-cols-2">
                        {[
                            {
                                title: 'Desktop application',
                                points: product.offering.desktop,
                            },
                            {
                                title: 'Mobile application',
                                points: product.offering.mobile,
                            },
                        ]
                            .filter(({ points }) => points.length > 0)
                            .map(({ title, points }) => (
                                <div
                                    key={title}
                                    className={`${panel} p-6 sm:p-7`}
                                >
                                    <h3 className="text-lg font-semibold">
                                        {title}
                                    </h3>
                                    <ul className="mt-4 space-y-2.5 text-sm text-slate-700">
                                        {points.map((point) => (
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
                                    {title === 'Desktop application' && (
                                        <Link
                                            href="/downloads"
                                            className="mt-5 inline-flex items-center gap-1.5 text-sm font-bold text-site-accent-ink hover:underline"
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
                )}
                {product.offering.platforms.length > 1 && (
                    <p className="mt-6 text-sm text-slate-600">
                        The desktop and mobile apps do less than the web
                        version, and what works offline differs between them.
                    </p>
                )}
            </section>

            {/* What it connects to: the registry's applications */}
            {product.includes.length > 0 && (
                <section
                    aria-labelledby="included-heading"
                    className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
                >
                    <Eyebrow>What's included</Eyebrow>
                    <SectionHeading
                        id="included-heading"
                        title={
                            <>
                                The applications in{' '}
                                <Accent>{product.name}</Accent>
                            </>
                        }
                        lead="Every application runs on the same platform, and posts to the same Finance books once you add Finance & Accounting."
                        align="left"
                    />
                    <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {product.includes.map((application) => (
                            <li
                                key={application.code}
                                className={`${card} flex items-start gap-4 p-6`}
                            >
                                <ProductTile
                                    code={application.code}
                                    size="sm"
                                />
                                <div>
                                    <p className="font-semibold">
                                        {application.listed ? (
                                            <Link
                                                href={`/products/${application.code}`}
                                                className="transition-colors hover:text-site-accent-ink"
                                            >
                                                {application.name}
                                            </Link>
                                        ) : (
                                            application.name
                                        )}
                                    </p>
                                    <p className="mt-1 inline-flex items-center gap-1.5 text-sm text-slate-600">
                                        {application.requirement ===
                                        'required' ? (
                                            <>
                                                <Check
                                                    className="size-4 text-site-accent"
                                                    aria-hidden
                                                />
                                                Included
                                            </>
                                        ) : (
                                            <>
                                                <Plus
                                                    className="size-4 text-slate-400"
                                                    aria-hidden
                                                />
                                                Optional add-on
                                            </>
                                        )}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/* Availability and plans */}
            <section
                id="plans"
                aria-labelledby="plans-heading"
                className="scroll-mt-20 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="plans-heading"
                        title={
                            <>
                                Availability and <Accent>plans</Accent>
                            </>
                        }
                        lead={
                            comingSoon
                                ? `${product.name} is not released yet. Join the waitlist and we will tell you when it opens.`
                                : product.trial
                                  ? `${product.name} is open for a ${trialDays}-day free trial. Nothing is charged unless you choose a plan.`
                                  : `${product.name} is ${product.status === 'early_access' ? 'in early access' : 'available'}. Talk to us to set it up for your business.`
                        }
                    />
                    {plans.length > 0 && (
                        <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard key={plan.code} plan={plan} />
                            ))}
                        </ul>
                    )}
                </div>
            </section>

            {/* FAQs */}
            {product.faqs.length > 0 && (
                <section aria-labelledby="product-faq-heading">
                    <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_1.5fr] lg:gap-16">
                        <SectionHeading
                            id="product-faq-heading"
                            title={
                                <>
                                    Questions about{' '}
                                    <Accent>{product.name}</Accent>
                                </>
                            }
                            align="left"
                        />
                        <FaqList items={product.faqs} />
                    </div>
                </section>
            )}

            {/* Call to action */}
            <div className={product.faqs.length > 0 ? '' : 'pt-16 sm:pt-24'}>
                <CtaBand
                    id="landing-cta-heading"
                    title={
                        comingSoon ? (
                            <>
                                Be first to <Accent>try it</Accent>
                            </>
                        ) : (
                            <>
                                Run your business.{' '}
                                <Accent>All connected.</Accent>
                            </>
                        )
                    }
                    body={
                        comingSoon
                            ? `Join the waitlist and we will tell you as soon as ${product.name} opens.`
                            : product.trial
                              ? `Try ${product.name} free for ${trialDays} days in your own workspace.`
                              : `Tell us about your business and we will walk you through ${product.name}.`
                    }
                >
                    {primary}
                    <Link href="/pricing" className={button.outlineDark}>
                        See pricing
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
