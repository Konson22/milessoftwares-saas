import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Building2,
    Check,
    KeyRound,
    Receipt,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ErpStatusBadge } from '@/sites/public/components/erp-product';
import type { ErpProduct } from '@/sites/public/components/erp-product';
import FaqList from '@/sites/public/components/faq';
import HeroShowcase from '@/sites/public/components/hero-showcase';
import { Accent, HeroBackdrop } from '@/sites/public/components/page-hero';
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
    iconChip,
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

            {/* Hero: navy, text on the left, application photos on the right. */}
            <section className={darkBand}>
                <HeroBackdrop />
                <div className="mx-auto grid max-w-7xl items-center gap-14 px-4 pt-16 pb-32 sm:px-6 sm:pt-24 sm:pb-40 lg:grid-cols-[1.05fr_1fr] lg:gap-10">
                    <div className="text-center lg:text-left">
                        <h1
                            className={`text-[2.25rem] leading-[1.08] sm:text-6xl xl:text-[4.25rem] ${display}`}
                        >
                            Business software for{' '}
                            <Accent>growing organizations</Accent>
                        </h1>
                        <p className="mx-auto mt-6 max-w-xl text-base text-pretty text-slate-300 sm:text-lg lg:mx-0">
                            Retail, pharmacy, school, finance, HR and payroll
                            applications that fit how your organization works.
                            Start with what you need and add more as you grow.
                        </p>
                        <div className="mt-9 flex flex-wrap justify-center gap-3 lg:justify-start">
                            <Link href="/start" className={button.primary}>
                                Start your free trial
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <a href="#products" className={button.outlineDark}>
                                Explore applications
                            </a>
                        </div>
                        <ul className="mt-8 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-slate-300 lg:justify-start">
                            {[
                                `Free for ${trialDuration(trialDays)}`,
                                'No payment details',
                                'Invite your whole team',
                            ].map((point) => (
                                <li
                                    key={point}
                                    className="flex items-center gap-2"
                                >
                                    <span className="inline-flex size-5 items-center justify-center rounded-full bg-site-accent/20 text-site-accent-glow">
                                        <Check className="size-3" aria-hidden />
                                    </span>
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>

                    {products.length > 0 && (
                        <div className="reveal-up relative mx-auto w-full max-w-2xl lg:max-w-none">
                            <div
                                aria-hidden
                                className="absolute -inset-4 -z-10 rounded-[2rem] bg-linear-to-br from-brand-cyan/30 via-transparent to-brand-green/25 blur-2xl"
                            />
                            <FloatingApps products={products} />
                            <HeroShowcase products={products} />
                        </div>
                    )}
                </div>
            </section>

            {/* Platform features: a strip that overlaps the hero's lower edge. */}
            <section
                aria-labelledby="platform-heading"
                className="relative z-10 mx-auto -mt-20 max-w-7xl px-4 sm:-mt-24 sm:px-6"
            >
                <h2 id="platform-heading" className="sr-only">
                    What every application shares
                </h2>
                <ul className="grid overflow-hidden rounded-2xl border border-site-line bg-white shadow-2xl shadow-site-navy/10 sm:grid-cols-2 lg:grid-cols-4">
                    {features.map(({ icon: Icon, title, body }, i) => (
                        <li
                            key={title}
                            className="group relative border-site-line p-7 transition-colors not-last:border-b hover:bg-site-soft sm:p-8 lg:border-b-0 lg:not-last:border-r sm:[&:nth-child(odd)]:border-r"
                        >
                            <span
                                aria-hidden
                                className="absolute top-6 right-7 font-public-display text-4xl font-bold text-site-line transition-colors group-hover:text-site-accent/25"
                            >
                                {ordinal(i)}
                            </span>
                            <span
                                className={`${iconChip} size-14 rounded-2xl transition-colors group-hover:bg-site-accent group-hover:text-white`}
                            >
                                <Icon className="size-6" aria-hidden />
                            </span>
                            <h3 className="mt-6 text-lg font-semibold">
                                {title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                {body}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            {/* About the platform: a collage on the left, the story on the right. */}
            <section
                aria-labelledby="about-heading"
                className="mx-auto grid max-w-7xl items-center gap-14 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-2 lg:gap-20"
            >
                <AboutCollage products={products} trialDays={trialDays} />
                <div>
                    <SectionHeading
                        id="about-heading"
                        label="One platform"
                        title={
                            <>
                                Applications that <Accent>work together</Accent>
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
                                className="flex items-start gap-3 font-semibold"
                            >
                                <span className="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-site-accent text-white">
                                    <Check className="size-3.5" aria-hidden />
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
            </section>

            {erpProducts.length > 0 && (
                <section
                    id="erp"
                    aria-labelledby="erp-heading"
                    className="scroll-mt-24 bg-site-soft"
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
                        <ul className="mt-12 grid gap-5 sm:mt-14 sm:grid-cols-2 lg:grid-cols-3">
                            {erpProducts.map((product, i) => (
                                <li key={product.code}>
                                    <ErpCard product={product} index={i} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {/* Figures: a navy band of plain facts. */}
            <section aria-label="At a glance" className={darkBand}>
                <div
                    aria-hidden
                    className="site-dots pointer-events-none absolute top-6 right-6 -z-10 h-24 w-40 text-white/10"
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute -bottom-24 left-1/3 -z-10 size-80 rounded-full bg-brand-green/15 blur-3xl"
                />
                <dl className="mx-auto grid max-w-7xl grid-cols-2 gap-y-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-4">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="flex flex-col-reverse items-center px-3 text-center lg:border-r lg:border-white/10 lg:last:border-r-0"
                        >
                            <dt className="mt-2 max-w-[12rem] text-sm leading-snug text-slate-300">
                                {stat.label}
                            </dt>
                            <dd className="font-public-display text-4xl font-bold text-white tabular-nums sm:text-5xl">
                                {stat.value}
                                <span className="text-site-accent-glow">+</span>
                            </dd>
                        </div>
                    ))}
                </dl>
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
                    <ul className="mt-12 grid gap-5 sm:mt-14 sm:grid-cols-2 lg:grid-cols-3">
                        {products.map((product) => (
                            <ProductCard key={product.code} product={product} />
                        ))}
                    </ul>
                )}
            </section>

            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="scroll-mt-24 border-t border-site-line bg-site-soft"
            >
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
                    <div className="relative mt-14 sm:mt-16">
                        <div
                            aria-hidden
                            className="absolute top-10 right-[17%] left-[17%] hidden border-t-2 border-dashed border-site-accent/30 md:block"
                        />
                        <ol className="grid gap-10 md:grid-cols-3 md:gap-6">
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
                                    className="group relative flex flex-col items-center px-4 text-center"
                                >
                                    <span className="relative inline-flex size-20 items-center justify-center rounded-full bg-white font-public-display text-2xl font-bold text-site-accent-ink shadow-lg ring-8 shadow-site-navy/10 ring-site-soft transition-colors group-hover:bg-site-accent group-hover:text-white">
                                        {ordinal(i)}
                                    </span>
                                    <h3 className="mt-6 text-xl font-semibold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-2 max-w-xs text-sm leading-relaxed text-slate-600">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                    <div className="mt-12 flex justify-center sm:mt-14">
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
                            title="Common questions"
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
                <div className="relative isolate mx-auto grid max-w-7xl items-center gap-8 overflow-hidden rounded-3xl bg-linear-to-r from-brand-blue-dark via-brand-blue to-[#0b6e63] px-6 py-14 text-white sm:px-14 sm:py-16 lg:grid-cols-[1fr_auto]">
                    <div
                        aria-hidden
                        className="absolute -top-24 -right-16 -z-10 size-72 rounded-full border-[36px] border-white/10"
                    />
                    <div
                        aria-hidden
                        className="absolute -bottom-28 left-1/3 -z-10 size-64 rounded-full border-[28px] border-white/5"
                    />
                    <div
                        aria-hidden
                        className="site-dots absolute top-6 left-6 -z-10 h-20 w-32 text-white/20"
                    />
                    <div className="text-center lg:text-left">
                        <h2
                            id="cta-heading"
                            className="text-3xl font-semibold tracking-tight text-balance sm:text-[2.75rem] sm:leading-tight"
                        >
                            Ready to try it for yourself?
                        </h2>
                        <p className="mt-4 max-w-xl text-white/90 lg:max-w-2xl">
                            Start a {trial} free trial today, or talk to us and
                            we will walk you through it.
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-center gap-3">
                        <Link href="/start" className={button.light}>
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
 * Two application drawings stacked at an angle, with a gold badge for
 * the free trial, beside the platform story. Decorative: the same
 * applications are listed below.
 */
function AboutCollage({
    products,
    trialDays,
}: {
    products: Product[];
    trialDays: number;
}) {
    const [first, second] = products;

    return (
        <div
            aria-hidden
            className="relative mx-auto w-full max-w-lg pb-10 lg:pb-0"
        >
            <div className="site-dots absolute -top-6 -left-6 h-32 w-32 text-site-accent/40" />
            <div className="absolute -right-4 -bottom-4 size-40 rounded-3xl border-2 border-site-accent/30" />
            <div className="relative overflow-hidden rounded-3xl border border-site-line bg-white shadow-2xl shadow-site-navy/15">
                {first ? (
                    <ProductArt code={first.code} />
                ) : (
                    <div className="aspect-[16/9] bg-site-soft" />
                )}
                <div className="flex items-center gap-3 p-5">
                    {first && <ProductTile code={first.code} size="sm" />}
                    <span className="h-2.5 w-32 rounded-full bg-slate-200" />
                    <span className="ml-auto h-2.5 w-12 rounded-full bg-site-accent/40" />
                </div>
            </div>
            {second && (
                <div className="absolute right-0 -bottom-6 w-3/5 rotate-3 overflow-hidden rounded-2xl border border-site-line bg-white shadow-2xl shadow-site-navy/20 sm:-right-8">
                    <ProductArt code={second.code} />
                </div>
            )}
            <div className="absolute -top-6 right-6 flex flex-col items-center rounded-2xl bg-linear-to-br from-brand-gold-light to-brand-gold px-6 py-5 text-site-ink shadow-xl shadow-brand-gold/30 sm:-right-6">
                <span className="font-public-display text-4xl leading-none font-bold">
                    {trialDays}
                </span>
                <span className="mt-1 text-xs font-bold tracking-widest uppercase">
                    days free
                </span>
            </div>
        </div>
    );
}

function ErpCard({ product, index }: { product: ErpProduct; index: number }) {
    return (
        <Link
            href={`/erp/${product.code}`}
            className={`${card} group relative flex h-full flex-col overflow-hidden p-7`}
        >
            <span
                aria-hidden
                className="absolute -top-3 right-4 font-public-display text-7xl font-bold text-site-soft transition-colors group-hover:text-site-accent/10"
            >
                {ordinal(index)}
            </span>
            <span
                aria-hidden
                className="absolute inset-x-0 bottom-0 h-1 origin-left scale-x-0 bg-linear-to-r from-brand-cyan via-brand-blue to-brand-green transition-transform duration-300 group-hover:scale-x-100"
            />
            <div className="relative flex items-start gap-3">
                <h3
                    className={`text-xl transition-colors group-hover:text-site-accent-ink ${display}`}
                >
                    {product.name}
                </h3>
                <span className="ml-auto">
                    <ErpStatusBadge status={product.status} />
                </span>
            </div>
            {product.tagline && (
                <p className="relative mt-3 flex-1 text-sm leading-relaxed text-slate-600">
                    {product.tagline}
                </p>
            )}
            <span className="relative mt-6 inline-flex items-center gap-2 text-sm font-bold text-site-accent-ink">
                Learn more
                <span className="sr-only"> about {product.name}</span>
                <span className="inline-flex size-7 items-center justify-center rounded-full bg-site-accent/10 transition-colors group-hover:bg-site-accent group-hover:text-white">
                    <ArrowUpRight className="size-3.5" aria-hidden />
                </span>
            </span>
        </Link>
    );
}

/**
 * Application names in small white pills, floating at the edges of the
 * workspace drawing on wide screens. Decorative: the same list is in the
 * applications section.
 */
function FloatingApps({ products }: { products: Product[] }) {
    const spots = [
        '-top-6 -left-8 -rotate-6',
        'top-1/3 -right-6 rotate-3',
        '-bottom-6 left-1/4 rotate-2',
        'top-2/3 -left-12 -rotate-3',
    ];

    return (
        <div aria-hidden className="hidden lg:block">
            {products.slice(0, spots.length).map((product, i) => {
                const Icon = productIcon(product.code);

                return (
                    <span
                        key={product.code}
                        className={`absolute z-10 ${spots[i]}`}
                    >
                        <span className="float-pill inline-flex items-center gap-2 rounded-full bg-white py-1.5 pr-4 pl-1.5 text-sm font-bold text-site-ink shadow-xl shadow-site-night/30">
                            <span className={`${iconChip} size-7 rounded-full`}>
                                <Icon className="size-3.5" />
                            </span>
                            {product.name}
                        </span>
                    </span>
                );
            })}
        </div>
    );
}

function ProductCard({ product }: { product: Product }) {
    const trialable = product.status !== 'planned';
    const href = `/products/${product.code}`;

    return (
        <li className={`${card} group flex flex-col overflow-hidden`}>
            <div className="relative overflow-hidden">
                <div className="transition-transform duration-500 group-hover:scale-105">
                    <ProductArt code={product.code} />
                </div>
                {statusLabel[product.status] && (
                    <span
                        className={`absolute top-4 left-4 rounded-full px-3 py-1 text-xs font-bold shadow-sm ${product.status === 'beta' ? 'bg-site-navy text-white' : 'bg-amber-100 text-amber-900'}`}
                    >
                        {statusLabel[product.status]}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col p-7">
                <div className="flex items-center gap-3">
                    <ProductTile code={product.code} size="sm" />
                    <h3 className={`min-w-0 text-xl ${display}`}>
                        <Link
                            href={href}
                            className="transition-colors hover:text-site-accent-ink"
                        >
                            {product.name}
                        </Link>
                    </h3>
                </div>
                <div className="flex-1">
                    {product.tagline && (
                        <p className="mt-4 leading-snug font-semibold">
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
                            <ArrowRight
                                className="size-3.5 transition-transform group-hover:translate-x-0.5"
                                aria-hidden
                            />
                        </a>
                    ) : (
                        <Link
                            href="/contact"
                            className="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-4 py-2 text-amber-900 transition-colors hover:bg-amber-200"
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
