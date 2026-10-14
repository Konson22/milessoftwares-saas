import { Link } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { Badge } from '@/shared/components/ui/badge';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { productIcon, statusLabel } from '@/sites/public/components/product';
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
    const Icon = productIcon(product.code);

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
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    All applications
                </Link>
                <div className="mt-6 flex flex-wrap items-center gap-4">
                    <span className="inline-flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Icon className="size-6" aria-hidden />
                    </span>
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

                <div className="mt-10 flex flex-wrap gap-3">
                    <Link
                        href={`/demo?product=${product.code}`}
                        className="rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        {product.status === 'planned'
                            ? 'Tell me when it launches'
                            : `Book a ${product.name} demo`}
                    </Link>
                    {product.status !== 'planned' && plans.length > 0 && (
                        <a
                            href="#plans"
                            className="rounded-md border px-5 py-3 text-sm font-medium hover:bg-accent"
                        >
                            See plans
                        </a>
                    )}
                </div>
            </section>

            {plans.length > 0 && (
                <section
                    id="plans"
                    aria-labelledby="plans-heading"
                    className="scroll-mt-20 border-t bg-muted/30"
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
