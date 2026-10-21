import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Building2,
    Check,
    CirclePlay,
    KeyRound,
    Receipt,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ErpStatusBadge } from '@/sites/public/components/erp-product';
import type { ErpProduct } from '@/sites/public/components/erp-product';
import FaqList from '@/sites/public/components/faq';
import HeroMockup from '@/sites/public/components/hero-mockup';
import {
    Accent,
    HeroBackdrop,
    LightBackdrop,
} from '@/sites/public/components/page-hero';
import {
    ProductTile,
    productIcon,
    statusLabel,
} from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import ProductArt from '@/sites/public/components/product-art';
import Seo from '@/sites/public/components/seo';
import {
    button,
    card,
    darkBand,
    display,
    eyebrow,
    iconChip,
    panel,
    textLink,
    trialDuration,
    trialLength,
    trialUrl,
} from '@/sites/public/components/styles';

type Props = {
    products: Product[];
    erpProducts: ErpProduct[];
    trialDays: number;
    canonical: string;
};

const features: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: KeyRound,
        title: 'Sign in once',
        body: 'Your team moves between applications without juggling extra passwords.',
    },
    {
        icon: ShieldCheck,
        title: 'Roles that travel',
        body: 'Invite people, give them roles and decide who sees what in each application.',
    },
    {
        icon: Building2,
        title: 'Branches and departments',
        body: 'Mirror how your organization is set up. Applications follow the same structure.',
    },
    {
        icon: Receipt,
        title: 'Books that add up',
        body: 'Sales, purchases and returns flow into Finance, so the accounts follow the business.',
    },
];

/** Two digits, as section cards number themselves: 01, 02, … */
function ordinal(i: number): string {
    return String(i + 1).padStart(2, '0');
}

export default function MarketingHome({
    products,
    erpProducts,
    trialDays,
    canonical,
}: Props) {
    const trial = trialLength(trialDays);

    // Facts from the registry and the trial setting, not marketing figures.
    const stats = [
        {
            value: String(products.length),
            label: 'Applications, live and coming soon',
        },
        { value: String(trialDays), label: 'Days free, no payment details' },
        {
            value: String(erpProducts.length || products.length),
            label:
                erpProducts.length > 0
                    ? 'Industry ERP products'
                    : 'Applications to choose from',
        },
        { value: '1', label: 'Sign-in for every application' },
    ];

    return (
        <>
            <Seo
                title="Business software for growing organizations"
                description="Retail, pharmacy, school, finance, HR and payroll applications for growing organizations. Start with what you need and add more as you grow."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'Organization',
                    name: 'Miles Global Link Co. Ltd',
                    url: canonical,
                }}
            />

            {/* Hero: lavender wash, copy beside a drawn dashboard. */}
            <section className="relative isolate overflow-hidden bg-linear-to-b from-site-wash via-site-soft to-white">
                <LightBackdrop />
                <div className="mx-auto grid max-w-7xl items-center gap-16 px-4 pt-14 pb-20 sm:px-6 sm:pt-20 sm:pb-28 lg:grid-cols-[1.05fr_1fr] lg:gap-10">
                    <div className="site-rise text-center lg:text-left">
                        <p className={eyebrow}>
                            One platform for your whole business
                        </p>
                        <h1
                            className={`mt-6 text-[2.4rem] leading-[1.06] sm:text-6xl xl:text-[4.1rem] ${display}`}
                        >
                            Business software for{' '}
                            <Accent>growing organizations</Accent>
                        </h1>
                        <p className="mx-auto mt-6 max-w-xl text-base text-pretty text-slate-600 sm:text-lg lg:mx-0">
                            Retail, pharmacy, school, finance, HR and payroll
                            applications that fit how your organization works.
                            Start with what you need and add more as you grow.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center justify-center gap-4 lg:justify-start">
                            <Link href="/start" className={button.primary}>
                                Start your free trial
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <a
                                href="#how-it-works"
                                className="group inline-flex items-center gap-3 text-sm font-bold text-site-ink"
                            >
                                <span className="inline-flex size-12 items-center justify-center rounded-full bg-white text-site-accent-ink shadow-lg ring-1 shadow-site-accent/20 ring-site-line transition-transform group-hover:scale-105">
                                    <CirclePlay
                                        className="size-6"
                                        aria-hidden
                                    />
                                </span>
                                See how it works
                            </a>
                        </div>
                        <ul className="mt-9 flex flex-wrap justify-center gap-2.5 text-sm font-semibold text-slate-700 lg:justify-start">
                            {[
                                `${trial} free trial`,
                                'No payment details',
                                'One sign-in for every app',
                            ].map((point) => (
                                <li
                                    key={point}
                                    className="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 ring-1 ring-site-line"
                                >
                                    <Check
                                        className="size-4 text-site-accent"
                                        aria-hidden
                                    />
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <HeroMockup
                        codes={
                            products.length > 0
                                ? products.map((product) => product.code)
                                : ['retail', 'pharmacy', 'finance']
                        }
                    />
                </div>
            </section>

            {/* Who it is for: a muted strip of the applications. */}
            {products.length > 0 && (
                <section
                    aria-labelledby="industries-heading"
                    className="border-y border-site-line bg-white"
                >
                    <div className="mx-auto flex max-w-7xl flex-col items-center gap-6 px-4 py-10 sm:px-6 lg:flex-row lg:gap-10">
                        <h2
                            id="industries-heading"
                            className="shrink-0 text-sm font-semibold text-slate-500"
                        >
                            Built for businesses like yours
                        </h2>
                        <ul className="flex flex-1 flex-wrap items-center justify-center gap-x-10 gap-y-4 lg:justify-between">
                            {products.slice(0, 6).map((product) => {
                                const Icon = productIcon(product.code);

                                return (
                                    <li
                                        key={product.code}
                                        className="inline-flex items-center gap-2 font-public-display text-lg font-semibold text-slate-400 transition-colors hover:text-site-ink"
                                    >
                                        <Icon className="size-5" aria-hidden />
                                        {product.name}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </section>
            )}

            {/* Platform features */}
            <section
                aria-labelledby="platform-heading"
                className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28"
            >
                <SectionHeading
                    id="platform-heading"
                    label="What every application shares"
                    title={
                        <>
                            Everything your team needs,{' '}
                            <Accent>set up once</Accent>
                        </>
                    }
                    lead="The platform does the groundwork, so each application can focus on the job it was built for."
                />
                <ul className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {features.map(({ icon: Icon, title, body }, i) => (
                        <li
                            key={title}
                            className={`${card} group relative overflow-hidden p-8`}
                        >
                            <span
                                aria-hidden
                                className="absolute top-5 right-6 font-public-display text-5xl font-bold text-site-soft transition-colors group-hover:text-site-accent/10"
                            >
                                {ordinal(i)}
                            </span>
                            <span className={`${iconChip} relative size-14`}>
                                <Icon className="size-6" aria-hidden />
                            </span>
                            <h3 className="relative mt-6 text-lg font-semibold">
                                {title}
                            </h3>
                            <p className="relative mt-2 text-sm leading-relaxed text-slate-600">
                                {body}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            {/* About the platform */}
            <section
                aria-labelledby="about-heading"
                className="relative isolate overflow-hidden bg-site-soft"
            >
                <div
                    aria-hidden
                    className="absolute -top-24 -left-24 -z-10 size-96 rounded-full bg-site-accent/10 blur-3xl"
                />
                <div className="mx-auto grid max-w-7xl items-center gap-14 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-2 lg:gap-20">
                    <AboutImage products={products} trialDays={trialDays} />
                    <div>
                        <SectionHeading
                            id="about-heading"
                            label="One platform"
                            title={
                                <>
                                    Applications that{' '}
                                    <Accent>work together</Accent>
                                </>
                            }
                            lead="Each application is built for a job of its own. Together they share your people, your structure and your subscription, so your organization is set up once."
                            align="left"
                        />
                        <ul className="mt-8 grid gap-4 sm:grid-cols-2">
                            {[
                                'One account for your whole team',
                                'Roles per application and branch',
                                'Every sale and purchase in the books',
                                'Add applications whenever you need',
                            ].map((point) => (
                                <li
                                    key={point}
                                    className="flex items-start gap-3 rounded-2xl bg-white p-4 font-semibold ring-1 ring-site-line"
                                >
                                    <span className="site-gradient mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full text-white">
                                        <Check
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    </span>
                                    {point}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-10 flex flex-wrap items-center gap-6">
                            <Link href="/start" className={button.primary}>
                                Get started
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <Link href="/contact" className={textLink}>
                                Talk to us first
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            {erpProducts.length > 0 && (
                <section
                    id="erp"
                    aria-labelledby="erp-heading"
                    className="scroll-mt-24"
                >
                    <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28">
                        <div className="flex flex-wrap items-end justify-between gap-6">
                            <SectionHeading
                                id="erp-heading"
                                label="ERP products"
                                title={
                                    <>
                                        An ERP for{' '}
                                        <Accent>your industry</Accent>
                                    </>
                                }
                                lead="Each product brings together the applications a business of that kind needs, on one set of books."
                                align="left"
                            />
                            <Link href="/contact" className={button.outline}>
                                Ask about your industry
                            </Link>
                        </div>
                        <ul className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {erpProducts.map((product) => (
                                <li key={product.code}>
                                    <ErpCard product={product} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {/* Figures on a gradient band. */}
            <section aria-label="At a glance" className="px-4 sm:px-6">
                <div className="site-dark site-gradient relative isolate mx-auto max-w-7xl overflow-hidden rounded-[2rem] text-white shadow-2xl shadow-site-accent/25">
                    <div
                        aria-hidden
                        className="absolute -top-20 -right-10 -z-10 size-72 rounded-full border-[28px] border-white/10"
                    />
                    <div
                        aria-hidden
                        className="site-dots absolute bottom-4 left-6 -z-10 h-20 w-36 text-white/25"
                    />
                    <dl className="grid grid-cols-2 gap-y-10 px-6 py-12 sm:py-14 lg:grid-cols-4">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className="flex flex-col-reverse items-center px-3 text-center lg:border-r lg:border-white/20 lg:last:border-r-0"
                            >
                                <dt className="mt-2 max-w-[12rem] text-sm leading-snug text-white/85">
                                    {stat.label}
                                </dt>
                                <dd className="font-public-display text-4xl font-bold tabular-nums sm:text-5xl">
                                    {stat.value}
                                    <span className="text-white/70">+</span>
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            <section
                id="products"
                aria-labelledby="products-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-20 sm:px-6 sm:py-28"
            >
                <SectionHeading
                    id="products-heading"
                    label="Applications"
                    title={
                        <>
                            Built for the work{' '}
                            <Accent>your business does</Accent>
                        </>
                    }
                    lead="Start with the application you need first. Add others as your organization grows."
                />
                {products.length === 0 ? (
                    <p className="mt-12 text-center">
                        Our applications are being prepared.{' '}
                        <Link href="/contact" className={textLink}>
                            Contact us
                        </Link>{' '}
                        to hear first.
                    </p>
                ) : (
                    <ul className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {products.map((product) => (
                            <ProductCard key={product.code} product={product} />
                        ))}
                    </ul>
                )}
            </section>

            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="relative isolate scroll-mt-24 overflow-hidden bg-site-soft"
            >
                <LightBackdrop />
                <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28">
                    <SectionHeading
                        id="how-heading"
                        label="How it works"
                        title={
                            <>
                                Try any application free for{' '}
                                <Accent>{trialDuration(trialDays)}</Accent>
                            </>
                        }
                        lead="Three steps from here to working in your own workspace."
                    />
                    <ol className="relative mt-16 grid gap-8 md:grid-cols-3">
                        {/* The dashed line joining the steps. Decorative. */}
                        <span
                            aria-hidden
                            className="absolute top-10 right-[16%] left-[16%] hidden border-t-2 border-dashed border-site-accent/30 md:block"
                        />
                        {[
                            {
                                title: 'Choose an application',
                                body: 'Pick the one your business needs first. You can add the others later.',
                            },
                            {
                                title: 'Create your workspace',
                                body: 'Sign in or create an account, then name your organization and choose its address.',
                            },
                            {
                                title: `Use it free for ${trialDuration(trialDays)}`,
                                body: 'Invite your team and start working. No payment details are needed to start.',
                            },
                        ].map((step, i) => (
                            <li
                                key={step.title}
                                className={`${panel} relative flex flex-col items-center px-6 pt-0 pb-8 text-center`}
                            >
                                <span className="site-gradient -mt-10 inline-flex size-20 items-center justify-center rounded-full font-public-display text-2xl font-bold text-white shadow-xl ring-8 shadow-site-accent/30 ring-site-soft">
                                    {ordinal(i)}
                                </span>
                                <h3 className="mt-5 text-xl font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 max-w-xs text-sm leading-relaxed text-slate-600">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-14 flex justify-center">
                        <Link href="/start" className={button.primary}>
                            Choose your application
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </div>
            </section>

            <section aria-labelledby="faq-heading">
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-[1fr_1.5fr] lg:gap-16">
                    <div className="lg:sticky lg:top-32 lg:self-start">
                        <SectionHeading
                            id="faq-heading"
                            label="FAQs"
                            title={
                                <>
                                    Common <Accent>questions</Accent>
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
                    <FaqList trialDays={trialDays} />
                </div>
            </section>

            <section
                aria-labelledby="cta-heading"
                className="px-4 pb-20 sm:px-6 sm:pb-28"
            >
                <div
                    className={`${darkBand} mx-auto grid max-w-7xl items-center gap-8 rounded-[2rem] px-6 py-14 sm:px-14 sm:py-16 lg:grid-cols-[1fr_auto]`}
                >
                    <HeroBackdrop />
                    <div className="text-center lg:text-left">
                        <h2
                            id="cta-heading"
                            className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-[2.6rem] sm:leading-tight"
                        >
                            Ready to try it <Accent>for yourself?</Accent>
                        </h2>
                        <p className="mt-4 max-w-xl text-slate-300 lg:max-w-2xl">
                            Start a {trial} free trial today, or talk to us and
                            we will walk you through it.
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-center gap-3">
                        <Link href="/start" className={button.primary}>
                            Start your free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                        <Link href="/contact" className={button.outlineDark}>
                            Talk to us
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

/**
 * A product photo beside the platform story, with a floating card for the
 * trial. Decorative extras are hidden from assistive technology.
 */
function AboutImage({
    products,
    trialDays,
}: {
    products: Product[];
    trialDays: number;
}) {
    const first = products[0];

    return (
        <div className="relative mx-auto w-full max-w-lg lg:mx-0">
            <div
                aria-hidden
                className="site-gradient absolute -right-4 -bottom-4 -z-10 h-full w-full rounded-3xl opacity-90"
            />
            <div className="overflow-hidden rounded-3xl border border-site-line bg-white shadow-xl shadow-site-ink/10">
                {first ? (
                    <div className="[&>div]:h-64 sm:[&>div]:h-72">
                        <ProductArt code={first.code} />
                    </div>
                ) : (
                    <div className="aspect-[16/9] bg-site-soft" />
                )}
                {first && (
                    <div className="flex items-center gap-3 p-5">
                        <ProductTile code={first.code} size="sm" />
                        <span className="font-semibold">{first.name}</span>
                    </div>
                )}
            </div>
            <div
                aria-hidden
                className="site-float absolute -top-6 -left-4 rounded-2xl bg-white px-5 py-4 shadow-xl ring-1 shadow-site-ink/15 ring-site-line sm:-left-8"
            >
                <span className="block font-public-display text-3xl font-bold text-site-ink">
                    {trialDays}
                    <span className="site-gradient-text"> days</span>
                </span>
                <span className="block text-xs text-slate-500">
                    free on every application
                </span>
            </div>
        </div>
    );
}

function ErpCard({ product }: { product: ErpProduct }) {
    return (
        <Link
            href={`/erp/${product.code}`}
            className={`${card} group relative flex h-full flex-col overflow-hidden p-8`}
        >
            <span
                aria-hidden
                className="site-gradient absolute inset-x-0 top-0 h-1 origin-left scale-x-0 transition-transform duration-300 group-hover:scale-x-100"
            />
            <div className="relative flex items-start gap-3">
                <ProductTile code={product.code} />
                <span className="ml-auto">
                    <ErpStatusBadge status={product.status} />
                </span>
            </div>
            <h3
                className={`relative mt-5 text-xl transition-colors group-hover:text-site-accent-ink ${display}`}
            >
                {product.name}
            </h3>
            {product.tagline && (
                <p className="relative mt-3 flex-1 text-sm leading-relaxed text-slate-600">
                    {product.tagline}
                </p>
            )}
            <span className="relative mt-6 inline-flex items-center gap-2 text-sm font-bold text-site-accent-ink">
                Learn more
                <span className="sr-only"> about {product.name}</span>
                <span className="inline-flex size-8 items-center justify-center rounded-full bg-site-accent/10 transition-colors group-hover:bg-site-accent group-hover:text-white">
                    <ArrowUpRight className="size-4" aria-hidden />
                </span>
            </span>
        </Link>
    );
}

function ProductCard({ product }: { product: Product }) {
    const trialable = product.status !== 'planned';
    const href = `/products/${product.code}`;

    return (
        <li className={`${card} group flex flex-col overflow-hidden`}>
            <div className="relative overflow-hidden [&_img]:transition-transform [&_img]:duration-500 group-hover:[&_img]:scale-105">
                <ProductArt code={product.code} />
                {statusLabel[product.status] && (
                    <span
                        className={`absolute top-4 left-4 rounded-full px-3 py-1 text-xs font-bold ${product.status === 'beta' ? 'site-gradient text-white' : 'bg-white/95 text-slate-700'}`}
                    >
                        {statusLabel[product.status]}
                    </span>
                )}
            </div>
            <div className="relative flex flex-1 flex-col p-7 pt-0">
                <span className="-mt-7 mb-4 inline-flex w-fit rounded-2xl bg-white p-1.5 shadow-lg shadow-site-ink/10">
                    <ProductTile code={product.code} />
                </span>
                <h3 className={`min-w-0 text-xl ${display}`}>
                    <Link
                        href={href}
                        className="transition-colors hover:text-site-accent-ink"
                    >
                        {product.name}
                    </Link>
                </h3>
                <div className="flex-1">
                    {product.tagline && (
                        <p className="mt-3 leading-snug font-semibold">
                            {product.tagline}
                        </p>
                    )}
                    {product.description && (
                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                            {product.description}
                        </p>
                    )}
                    {product.features.length > 0 && (
                        <ul className="mt-5 space-y-2 text-sm">
                            {product.features.slice(0, 3).map((feature) => (
                                <li key={feature} className="flex gap-2">
                                    <Check
                                        className="mt-0.5 size-4 shrink-0 text-site-accent"
                                        aria-hidden
                                    />
                                    {feature}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-site-line pt-5 text-sm font-bold">
                    {trialable ? (
                        <a
                            href={trialUrl(product.code)}
                            className="inline-flex items-center gap-1.5 rounded-full bg-site-accent/10 px-4 py-2 text-site-accent-ink transition-colors hover:bg-site-accent-ink hover:text-white"
                        >
                            Start free trial
                            <span className="sr-only"> of {product.name}</span>
                            <ArrowRight className="size-3.5" aria-hidden />
                        </a>
                    ) : (
                        <Link
                            href="/contact"
                            className="inline-flex items-center gap-1.5 rounded-full bg-site-soft px-4 py-2 text-slate-700 ring-1 ring-site-line transition-colors hover:bg-white"
                        >
                            Notify me
                            <span className="sr-only">
                                {' '}
                                when {product.name} launches
                            </span>
                        </Link>
                    )}
                    <Link
                        href={href}
                        className="text-slate-600 transition-colors hover:text-site-ink"
                    >
                        Learn more
                        <span className="sr-only"> about {product.name}</span>
                    </Link>
                </div>
            </div>
        </li>
    );
}
