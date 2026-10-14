import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    KeyRound,
    Receipt,
    ShieldCheck,
} from 'lucide-react';
import { Badge } from '@/shared/components/ui/badge';
import { productIcon, statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';
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

            <section className="relative overflow-hidden">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 -top-40 -z-10 mx-auto h-96 max-w-4xl rounded-full bg-primary/10 blur-3xl"
                />
                <div className="mx-auto max-w-6xl px-4 py-20 sm:py-28">
                    <p className="inline-flex rounded-full border bg-background px-3 py-1 text-sm text-muted-foreground">
                        Business software by Miles Global Link Co. Ltd
                    </p>
                    <h1 className="mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                        One account for all your business software.
                    </h1>
                    <p className="mt-6 max-w-xl text-lg text-pretty text-muted-foreground">
                        Run every part of your organization from one workspace:
                        one sign-in for your team, one place to manage who can
                        do what, one subscription.
                    </p>
                    <div className="mt-10 flex flex-wrap gap-3">
                        <a
                            href={register.url()}
                            className="inline-flex items-center gap-2 rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Create your workspace
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                        <Link
                            href="/demo"
                            className="rounded-md border bg-background px-5 py-3 text-sm font-medium hover:bg-accent"
                        >
                            Book a demo
                        </Link>
                    </div>
                </div>
            </section>

            <section
                id="products"
                aria-labelledby="products-heading"
                className="scroll-mt-20 border-t bg-muted/30"
            >
                <div className="mx-auto max-w-6xl px-4 py-20">
                    <h2
                        id="products-heading"
                        className="text-3xl font-semibold tracking-tight"
                    >
                        Applications
                    </h2>
                    <p className="mt-3 max-w-xl text-muted-foreground">
                        Start with the one you need and add more as you grow.
                        They all share your team, your structure and your
                        subscription.
                    </p>
                    {products.length === 0 ? (
                        <p className="mt-8 text-muted-foreground">
                            Our applications are being prepared.{' '}
                            <Link
                                href="/contact"
                                className="font-medium text-primary hover:underline"
                            >
                                Contact us
                            </Link>{' '}
                            to hear first.
                        </p>
                    ) : (
                        <ul className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
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

            <section aria-labelledby="platform-heading" className="border-t">
                <div className="mx-auto max-w-6xl px-4 py-20">
                    <h2
                        id="platform-heading"
                        className="max-w-2xl text-3xl font-semibold tracking-tight"
                    >
                        Built as one platform, not a pile of tools
                    </h2>
                    <ul className="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                        {platform.map(({ icon: Icon, title, body }) => (
                            <li key={title}>
                                <span className="inline-flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Icon className="size-5" aria-hidden />
                                </span>
                                <h3 className="mt-4 font-semibold">{title}</h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section aria-labelledby="cta-heading" className="px-4 pb-20">
                <div className="mx-auto max-w-6xl rounded-2xl bg-primary px-6 py-14 text-center text-primary-foreground sm:px-12">
                    <h2
                        id="cta-heading"
                        className="text-3xl font-semibold tracking-tight"
                    >
                        Ready to bring your business together?
                    </h2>
                    <p className="mx-auto mt-4 max-w-xl opacity-90">
                        Create a workspace for your organization, or talk to us
                        and we will walk you through it.
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        <a
                            href={register.url()}
                            className="rounded-md bg-background px-5 py-3 text-sm font-medium text-foreground hover:bg-background/90"
                        >
                            Create your workspace
                        </a>
                        <Link
                            href="/pricing"
                            className="rounded-md border border-primary-foreground/40 px-5 py-3 text-sm font-medium hover:bg-primary-foreground/10"
                        >
                            See pricing
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

function ProductCard({ product }: { product: Product }) {
    const Icon = productIcon(product.code);

    return (
        <li className="group relative flex flex-col rounded-xl border bg-background p-6 transition-shadow hover:shadow-md has-[a:focus-visible]:ring-2 has-[a:focus-visible]:ring-ring">
            <div className="flex items-start justify-between gap-2">
                <span className="inline-flex size-11 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="size-5" aria-hidden />
                </span>
                {statusLabel[product.status] && (
                    <Badge variant="secondary">
                        {statusLabel[product.status]}
                    </Badge>
                )}
            </div>
            <h3 className="mt-5 text-lg font-semibold">
                {/* The whole card is clickable through this link. */}
                <Link
                    href={`/products/${product.code}`}
                    className="after:absolute after:inset-0 after:rounded-xl focus-visible:outline-none"
                >
                    {product.name}
                </Link>
            </h3>
            <p className="mt-2 flex-1 text-sm text-muted-foreground">
                {product.tagline ?? product.description}
            </p>
            <span
                aria-hidden
                className="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary"
            >
                Learn more
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
            </span>
        </li>
    );
}
