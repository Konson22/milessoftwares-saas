import { Link } from '@inertiajs/react';
import {
    ArrowRight,
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
import PageHero, {
    Accent,
    HeroBadge,
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
    display,
    iconChip,
    textLink,
    trialDuration,
    trialLength,
    trialUrl,
} from '@/sites/public/components/styles';
import WorkspacePreview from '@/sites/public/components/workspace-preview';

type Props = {
    products: Product[];
    erpProducts: ErpProduct[];
    trialDays: number;
    canonical: string;
};

const features: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: KeyRound,
        title: 'One sign-in',
        body: 'Your team signs in once and moves between applications without new passwords.',
    },
    {
        icon: ShieldCheck,
        title: 'One place for access',
        body: 'Invite people, give them roles and decide who sees what, across every application.',
    },
    {
        icon: Building2,
        title: 'Branches and departments',
        body: 'Set up your organization once. Every application works with the same structure.',
    },
    {
        icon: Receipt,
        title: 'Books that add up',
        body: 'Sales, purchases and returns flow into Finance, so the accounts follow the business.',
    },
];

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
        { value: '1', label: 'Sign-in for your whole team' },
    ];

    return (
        <>
            <Seo
                title="Business software for growing organizations"
                description="Retail, pharmacy, school, finance, HR and payroll applications on one account, one organization and one subscription."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'Organization',
                    name: 'Miles Global Link Co. Ltd',
                    url: canonical,
                }}
            />

            <PageHero
                padding="pt-16 pb-16 sm:pt-24 sm:pb-20"
                eyebrow={
                    <HeroBadge>
                        {trial} free trial on every application
                    </HeroBadge>
                }
                title={
                    <>
                        One account for{' '}
                        <Accent>all your business software</Accent>
                    </>
                }
                intro="Run every part of your organization from one workspace: one sign-in for your team, one place to manage who can do what, one subscription."
            >
                <div className="mt-9 flex flex-wrap justify-center gap-3">
                    <Link href="/start" className={button.primary}>
                        Start your free trial
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <a href="#products" className={button.outline}>
                        Explore applications
                    </a>
                </div>
                <ul className="mt-7 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-slate-600">
                    {[
                        `Free for ${trialDuration(trialDays)}`,
                        'No payment details',
                        'Your whole team on one sign-in',
                    ].map((point) => (
                        <li key={point} className="flex items-center gap-1.5">
                            <span className="inline-flex size-4 items-center justify-center rounded-full bg-emerald-500 text-white">
                                <Check className="size-3" aria-hidden />
                            </span>
                            {point}
                        </li>
                    ))}
                </ul>

                {products.length > 0 && (
                    <div className="relative mt-14 w-full max-w-5xl sm:mt-16">
                        <FloatingApps products={products} />
                        <WorkspacePreview products={products} />
                    </div>
                )}

                <dl className="mt-14 grid w-full max-w-4xl gap-4 sm:grid-cols-3">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className={`${card} flex flex-col-reverse items-center px-6 py-6`}
                        >
                            <dt className="mt-1 text-sm text-slate-600">
                                {stat.label}
                            </dt>
                            <dd className="text-4xl font-bold tracking-tight text-site-ink">
                                {stat.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </PageHero>

            <section
                aria-labelledby="platform-heading"
                className="mx-auto max-w-7xl px-4 py-24 sm:px-6"
            >
                <SectionHeading
                    id="platform-heading"
                    label="Platform"
                    title={
                        <>
                            Built as <Accent>one platform</Accent>, not a pile
                            of tools
                        </>
                    }
                    lead="Every application runs on the same foundation: the same people, the same structure, the same subscription."
                />
                <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {features.map(({ icon: Icon, title, body }) => (
                        <li key={title} className={`${card} p-7 text-center`}>
                            <span className={`${iconChip} size-14`}>
                                <Icon className="size-6" aria-hidden />
                            </span>
                            <h3 className="mt-6 text-lg font-semibold">
                                {title}
                            </h3>
                            <p className="mt-2 text-sm text-slate-600">
                                {body}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            {erpProducts.length > 0 && (
                <section
                    id="erp"
                    aria-labelledby="erp-heading"
                    className="mx-auto max-w-7xl scroll-mt-20 px-4 pb-24 sm:px-6"
                >
                    <SectionHeading
                        id="erp-heading"
                        label="ERP products"
                        title={
                            <>
                                An ERP for <Accent>your industry</Accent>
                            </>
                        }
                        lead="Each product brings together the applications a business of that kind needs, on one set of books."
                    />
                    <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {erpProducts.map((product) => (
                            <li
                                key={product.code}
                                className={`${card} flex flex-col p-6`}
                            >
                                <div className="flex items-start gap-3">
                                    <h3 className={`text-xl ${display}`}>
                                        <Link
                                            href={`/erp/${product.code}`}
                                            className="hover:text-brand-blue"
                                        >
                                            {product.name}
                                        </Link>
                                    </h3>
                                    <span className="ml-auto">
                                        <ErpStatusBadge
                                            status={product.status}
                                        />
                                    </span>
                                </div>
                                {product.tagline && (
                                    <p className="mt-3 flex-1 text-sm text-slate-600">
                                        {product.tagline}
                                    </p>
                                )}
                                <Link
                                    href={`/erp/${product.code}`}
                                    className="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-brand-blue hover:underline"
                                >
                                    Learn more
                                    <span className="sr-only">
                                        {' '}
                                        about {product.name}
                                    </span>
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-20 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-24 sm:px-6">
                    <SectionHeading
                        id="products-heading"
                        label="Applications"
                        title={
                            <>
                                Everything your business runs on,{' '}
                                <Accent>in one place</Accent>
                            </>
                        }
                        lead="Start with the one you need and add more as you grow. They all share your team, your structure and your subscription."
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
                        <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {products.map((product) => (
                                <ProductCard
                                    key={product.code}
                                    product={product}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </section>

            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="mx-auto max-w-7xl scroll-mt-20 px-4 py-24 sm:px-6"
            >
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
                <ol className="mt-14 grid gap-5 md:grid-cols-3">
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
                        <li key={step.title} className={`${card} p-7`}>
                            <span className="inline-flex size-11 items-center justify-center rounded-full bg-brand-blue text-lg font-bold text-white">
                                {i + 1}
                            </span>
                            <h3 className="mt-6 text-lg font-semibold">
                                {step.title}
                            </h3>
                            <p className="mt-2 text-sm text-slate-600">
                                {step.body}
                            </p>
                        </li>
                    ))}
                </ol>
                <div className="mt-12 flex justify-center">
                    <Link href="/start" className={button.primary}>
                        Choose your application
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                </div>
            </section>

            <section
                aria-labelledby="faq-heading"
                className="border-t border-site-line bg-site-soft"
            >
                <div className="mx-auto grid max-w-7xl gap-12 px-4 py-24 sm:px-6 lg:grid-cols-[1fr_1.5fr] lg:gap-16">
                    <div>
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
                className="px-4 py-24 sm:px-6"
            >
                <div className="relative isolate mx-auto max-w-7xl overflow-hidden rounded-3xl bg-linear-to-br from-brand-blue to-brand-blue-dark px-6 py-16 text-center text-white sm:px-12 sm:py-20">
                    <div
                        aria-hidden
                        className="absolute -top-24 -right-24 -z-10 size-72 rounded-full border-[40px] border-white/10"
                    />
                    <div
                        aria-hidden
                        className="absolute -bottom-32 -left-20 -z-10 size-80 rounded-full border-[40px] border-white/5"
                    />
                    <h2
                        id="cta-heading"
                        className="mx-auto max-w-3xl text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                    >
                        Ready to bring your business together?
                    </h2>
                    <p className="mx-auto mt-5 max-w-xl text-white/85">
                        Start a {trial} free trial today, or talk to us and we
                        will walk you through it.
                    </p>
                    <div className="mt-9 flex flex-wrap justify-center gap-3">
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
 * Application names in small white pills, floating at the edges of the
 * workspace drawing on wide screens. Decorative: the same list is in the
 * applications section.
 */
function FloatingApps({ products }: { products: Product[] }) {
    const spots = [
        '-top-6 -left-10 -rotate-6',
        'top-1/3 -right-14 rotate-3',
        '-bottom-6 left-1/4 rotate-2',
        'top-1/2 -left-16 -rotate-3',
    ];

    return (
        <div aria-hidden className="hidden lg:block">
            {products.slice(0, spots.length).map((product, i) => {
                const Icon = productIcon(product.code);

                return (
                    <span
                        key={product.code}
                        className={`absolute z-10 inline-flex items-center gap-2 rounded-full border border-site-line bg-white py-1.5 pr-4 pl-1.5 text-sm font-semibold shadow-lg shadow-site-ink/10 ${spots[i]}`}
                    >
                        <span className={`${iconChip} size-7 rounded-full`}>
                            <Icon className="size-3.5" />
                        </span>
                        {product.name}
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
            <ProductArt code={product.code} />
            <div className="flex flex-1 flex-col p-6">
                <div className="flex items-center gap-3">
                    <ProductTile code={product.code} size="sm" />
                    <h3 className={`text-xl ${display}`}>
                        <Link href={href} className="hover:text-brand-blue">
                            {product.name}
                        </Link>
                    </h3>
                    {statusLabel[product.status] && (
                        <span
                            className={`ml-auto shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${product.status === 'beta' ? 'bg-brand-blue/10 text-brand-blue' : 'bg-amber-100 text-amber-800'}`}
                        >
                            {statusLabel[product.status]}
                        </span>
                    )}
                </div>
                <div className="flex-1">
                    {product.tagline && (
                        <p className="mt-4 font-semibold">{product.tagline}</p>
                    )}
                    {product.description && (
                        <p className="mt-1.5 text-sm text-slate-600">
                            {product.description}
                        </p>
                    )}
                    {product.features.length > 0 && (
                        <ul className="mt-5 space-y-2 text-sm">
                            {product.features.slice(0, 3).map((feature) => (
                                <li key={feature} className="flex gap-2">
                                    <Check
                                        className="mt-0.5 size-4 shrink-0 text-emerald-500"
                                        aria-hidden
                                    />
                                    {feature}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-site-line pt-5 text-sm font-semibold">
                    {trialable ? (
                        <a
                            href={trialUrl(product.code)}
                            className="inline-flex items-center gap-1 text-brand-blue hover:underline"
                        >
                            Start free trial
                            <span className="sr-only"> of {product.name}</span>
                            <ArrowRight
                                className="size-4 transition-transform group-hover:translate-x-0.5"
                                aria-hidden
                            />
                        </a>
                    ) : (
                        <Link
                            href="/contact"
                            className="inline-flex items-center gap-1 text-brand-blue hover:underline"
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
                        className="text-slate-600 hover:text-site-ink"
                    >
                        Learn more
                        <span className="sr-only"> about {product.name}</span>
                    </Link>
                </div>
            </div>
        </li>
    );
}
