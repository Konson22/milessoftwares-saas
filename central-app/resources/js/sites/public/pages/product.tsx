import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Badge } from '@/shared/components/ui/badge';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';

type Props = {
    product: Product;
    plans: Plan[];
    canonical: string;
};

export default function ProductPage({ product, plans, canonical }: Props) {
    const summary =
        product.tagline ??
        product.description ??
        `${product.name} by Miles Global Softwares.`;

    return (
        <>
            <Seo
                title={product.name}
                description={summary}
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'SoftwareApplication',
                    name: product.name,
                    applicationCategory: 'BusinessApplication',
                    description: summary,
                    url: canonical,
                }}
            />

            <section className="mx-auto max-w-6xl px-4 py-16">
                <Link
                    href="/#products"
                    className="text-sm text-muted-foreground hover:underline"
                >
                    All applications
                </Link>
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <h1 className="text-4xl font-semibold tracking-tight">
                        {product.name}
                    </h1>
                    {statusLabel[product.status] && (
                        <Badge variant="secondary">
                            {statusLabel[product.status]}
                        </Badge>
                    )}
                </div>
                {product.tagline && (
                    <p className="mt-4 max-w-2xl text-lg text-muted-foreground">
                        {product.tagline}
                    </p>
                )}
                {product.description && (
                    <p className="mt-4 max-w-2xl">{product.description}</p>
                )}

                {product.features.length > 0 && (
                    <ul className="mt-8 grid max-w-3xl gap-3 sm:grid-cols-2">
                        {product.features.map((feature) => (
                            <li key={feature} className="flex gap-2">
                                <Check
                                    className="mt-0.5 size-5 shrink-0 text-primary"
                                    aria-hidden
                                />
                                {feature}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-10">
                    <Link
                        href={`/demo?product=${product.code}`}
                        className="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        {product.status === 'planned'
                            ? 'Tell me when it launches'
                            : `Book a ${product.name} demo`}
                    </Link>
                </div>
            </section>

            {plans.length > 0 && (
                <section
                    aria-labelledby="plans-heading"
                    className="border-t bg-muted/30"
                >
                    <div className="mx-auto max-w-6xl px-4 py-16">
                        <h2
                            id="plans-heading"
                            className="text-2xl font-semibold"
                        >
                            Plans that include {product.name}
                        </h2>
                        <ul className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard key={plan.code} plan={plan} />
                            ))}
                        </ul>
                    </div>
                </section>
            )}
        </>
    );
}
