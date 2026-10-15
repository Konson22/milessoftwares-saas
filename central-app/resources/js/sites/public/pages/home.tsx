import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    KeyRound,
    Receipt,
    ShieldCheck,
} from 'lucide-react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import PageHero, { HeroBackdrop } from '@/sites/public/components/page-hero';
import { productIcon, statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';
import { button, textLink } from '@/sites/public/components/styles';
import WorkspacePreview from '@/sites/public/components/workspace-preview';
import { register } from '@/routes';

type Props = {
    products: Product[];
    canonical: string;
};

// What every application shares by running on the platform.
const platform = [
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

export default function MarketingHome({ products, canonical }: Props) {
    // The hub drawing only reads as a network with a few applications in it.
    const showHub = products.length >= 3;

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
                padding="pt-20 pb-16 sm:pt-28 sm:pb-24"
                eyebrow={
                    <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-sm text-zinc-300">
                        <span className="size-1.5 rounded-full bg-primary shadow-[0_0_8px] shadow-primary" />
                        Business software by Miles Global Link Co. Ltd
                    </p>
                }
                title={
                    <>
                        One account for{' '}
                        <span className="bg-linear-to-r from-sky-300 via-primary to-indigo-400 bg-clip-text text-transparent">
                            all your business software.
                        </span>
                    </>
                }
                intro="Run every part of your organization from one workspace: one sign-in for your team, one place to manage who can do what, one subscription."
            >
                <div className="mt-10 flex flex-wrap gap-3">
                    <a href={register.url()} className={button.primary}>
                        Create your workspace
                        <ArrowRight className="size-4" aria-hidden />
                    </a>
                    <Link href="/demo" className={button.outlineDark}>
                        Book a demo
                    </Link>
                </div>
                {products.length > 0 && (
                    <div className="mt-16 sm:mt-20">
                        <WorkspacePreview products={products} />
                    </div>
                )}
            </PageHero>

            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-16"
            >
                <div className="mx-auto max-w-6xl px-4 py-24">
                    <SectionHeading
                        id="products-heading"
                        eyebrow="Products"
                        title="Applications"
                        intro="Start with the one you need and add more as you grow. They all share your team, your structure and your subscription."
                    />
                    {products.length === 0 ? (
                        <p className="mt-10 text-muted-foreground">
                            Our applications are being prepared.{' '}
                            <Link href="/contact" className={textLink}>
                                Contact us
                            </Link>{' '}
                            to hear first.
                        </p>
                    ) : (
                        <ul className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                aria-labelledby="platform-heading"
                className="border-y bg-muted/40"
            >
                <div
                    className={`mx-auto grid max-w-6xl items-center gap-16 px-4 py-24 ${showHub ? 'lg:grid-cols-[1fr_26rem]' : ''}`}
                >
                    <div>
                        <SectionHeading
                            id="platform-heading"
                            eyebrow="Platform"
                            title="Built as one platform, not a pile of tools"
                        />
                        <ul
                            className={`mt-12 grid gap-x-10 gap-y-10 sm:grid-cols-2 ${showHub ? '' : 'lg:grid-cols-4'}`}
                        >
                            {platform.map(({ icon: Icon, title, body }) => (
                                <li key={title} className="flex gap-4">
                                    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-md shadow-primary/30">
                                        <Icon className="size-5" aria-hidden />
                                    </span>
                                    <div>
                                        <h3 className="font-semibold">
                                            {title}
                                        </h3>
                                        <p className="mt-1.5 text-sm text-muted-foreground">
                                            {body}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                    {showHub && <PlatformHub products={products} />}
                </div>
            </section>

            <section aria-labelledby="cta-heading" className="px-4 py-24">
                <div className="relative isolate mx-auto max-w-6xl overflow-hidden rounded-3xl bg-zinc-950 px-6 py-16 text-center sm:px-12 sm:py-20">
                    <HeroBackdrop />
                    <h2
                        id="cta-heading"
                        className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl"
                    >
                        Ready to bring your business together?
                    </h2>
                    <p className="mx-auto mt-4 max-w-xl text-zinc-400">
                        Create a workspace for your organization, or talk to us
                        and we will walk you through it.
                    </p>
                    <div className="mt-10 flex flex-wrap justify-center gap-3">
                        <a href={register.url()} className={button.light}>
                            Create your workspace
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                        <Link href="/pricing" className={button.outlineDark}>
                            See pricing
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

function SectionHeading({
    id,
    eyebrow,
    title,
    intro,
}: {
    id: string;
    eyebrow: string;
    title: string;
    intro?: string;
}) {
    return (
        <>
            <p className="text-sm font-semibold tracking-wide text-primary uppercase">
                {eyebrow}
            </p>
            <h2
                id={id}
                className="mt-3 max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
            >
                {title}
            </h2>
            {intro && (
                <p className="mt-4 max-w-xl text-lg text-pretty text-muted-foreground">
                    {intro}
                </p>
            )}
        </>
    );
}

function ProductCard({ product }: { product: Product }) {
    const Icon = productIcon(product.code);

    return (
        <li className="group relative flex flex-col overflow-hidden rounded-2xl border bg-card p-6 transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-xl hover:shadow-primary/5 has-[a:focus-visible]:ring-2 has-[a:focus-visible]:ring-ring">
            <div
                aria-hidden
                className="pointer-events-none absolute -top-24 -right-24 size-48 rounded-full bg-primary/10 opacity-0 blur-2xl transition-opacity group-hover:opacity-100"
            />
            <div className="flex items-start justify-between gap-2">
                <span className="inline-flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-primary to-indigo-600 text-white shadow-md shadow-primary/25">
                    <Icon className="size-6" aria-hidden />
                </span>
                {statusLabel[product.status] && (
                    <span className="rounded-full border px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        {statusLabel[product.status]}
                    </span>
                )}
            </div>
            <h3 className="mt-6 text-lg font-semibold">
                {/* The whole card is clickable through this link. */}
                <Link
                    href={`/products/${product.code}`}
                    className="after:absolute after:inset-0 after:rounded-2xl focus-visible:outline-none"
                >
                    {product.name}
                </Link>
            </h3>
            <p className="mt-2 flex-1 text-sm text-muted-foreground">
                {product.tagline ?? product.description}
            </p>
            <span
                aria-hidden
                className="mt-6 inline-flex items-center gap-1 text-sm font-medium text-primary"
            >
                Learn more
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
            </span>
        </li>
    );
}

/**
 * The applications arranged around the workspace they share. Decorative:
 * the section's text says the same thing.
 */
function PlatformHub({ products }: { products: Product[] }) {
    const apps = products.slice(0, 6);

    return (
        <div
            aria-hidden
            className="relative mx-auto aspect-square w-full max-w-sm"
        >
            <svg
                viewBox="0 0 100 100"
                className="absolute inset-0 size-full text-primary/30"
            >
                <circle
                    cx="50"
                    cy="50"
                    r="38"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="0.3"
                    strokeDasharray="1 1.5"
                />
                <circle
                    cx="50"
                    cy="50"
                    r="22"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="0.3"
                />
                {apps.map((product, i) => {
                    const [x, y] = orbit(i, apps.length);

                    return (
                        <line
                            key={product.code}
                            x1="50"
                            y1="50"
                            x2={x}
                            y2={y}
                            stroke="currentColor"
                            strokeWidth="0.4"
                        />
                    );
                })}
            </svg>
            <div className="absolute top-1/2 left-1/2 flex size-24 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-3xl border bg-background shadow-2xl ring-8 shadow-primary/20 ring-primary/5">
                <AppLogoIcon className="size-12" alt="" />
            </div>
            {apps.map((product, i) => {
                const [x, y] = orbit(i, apps.length);
                const Icon = productIcon(product.code);

                return (
                    <div
                        key={product.code}
                        className="absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5"
                        style={{ left: `${x}%`, top: `${y}%` }}
                    >
                        <span className="inline-flex size-12 items-center justify-center rounded-xl border bg-background text-primary shadow-lg">
                            <Icon className="size-5" />
                        </span>
                        {/* Background so the spoke behind it doesn't strike through. */}
                        <span className="rounded bg-background px-1.5 text-xs font-medium whitespace-nowrap text-muted-foreground">
                            {product.name}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

/** Position of the i-th of n points on the outer ring, starting at the top. */
function orbit(i: number, n: number): [number, number] {
    const angle = (i / n) * 2 * Math.PI - Math.PI / 2;

    // Rounded so server and browser render identical markup.
    const round = (value: number) => Math.round(value * 100) / 100;

    return [round(50 + 38 * Math.cos(angle)), round(50 + 38 * Math.sin(angle))];
}
