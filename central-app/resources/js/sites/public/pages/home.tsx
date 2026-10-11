import { Link } from '@inertiajs/react';
import { Badge } from '@/shared/components/ui/badge';
import { statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';
import { register } from '@/routes';

type Props = {
    products: Product[];
    canonical: string;
};

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

            <section className="mx-auto max-w-6xl px-4 py-16">
                <p className="text-sm font-medium text-primary">
                    Miles Global Link Co. Ltd
                </p>
                <h1 className="mt-3 max-w-2xl text-4xl font-semibold tracking-tight">
                    One account for all your business software.
                </h1>
                <p className="mt-4 max-w-xl text-lg text-muted-foreground">
                    Run every part of your organization from one workspace: one
                    sign-in for your team, one place to manage who can do what,
                    one subscription.
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <a
                        href={register.url()}
                        className="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Create your workspace
                    </a>
                    <Link
                        href="/demo"
                        className="rounded-md border px-5 py-2.5 text-sm font-medium hover:bg-accent"
                    >
                        Book a demo
                    </Link>
                </div>
            </section>

            <section
                id="products"
                aria-labelledby="products-heading"
                className="border-t bg-muted/30"
            >
                <div className="mx-auto max-w-6xl px-4 py-16">
                    <h2
                        id="products-heading"
                        className="text-2xl font-semibold"
                    >
                        Applications
                    </h2>
                    {products.length === 0 ? (
                        <p className="mt-4 text-muted-foreground">
                            Our applications are being prepared. Contact us to
                            hear first.
                        </p>
                    ) : (
                        <ul className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {products.map((product) => (
                                <li
                                    key={product.code}
                                    className="flex flex-col rounded-xl border bg-background p-6"
                                >
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-lg font-semibold">
                                            <Link
                                                href={`/products/${product.code}`}
                                                className="hover:underline"
                                            >
                                                {product.name}
                                            </Link>
                                        </h3>
                                        {statusLabel[product.status] && (
                                            <Badge variant="secondary">
                                                {statusLabel[product.status]}
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-2 flex-1 text-sm text-muted-foreground">
                                        {product.tagline ?? product.description}
                                    </p>
                                    <Link
                                        href={`/products/${product.code}`}
                                        className="mt-4 text-sm font-medium text-primary hover:underline"
                                    >
                                        Learn more
                                        <span className="sr-only">
                                            {' '}
                                            about {product.name}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </section>
        </>
    );
}
