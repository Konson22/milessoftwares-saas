import { Link } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import PageHero from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { productIcon, statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

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

            <PageHero
                eyebrow={
                    <>
                        <Link
                            href="/#products"
                            className="inline-flex items-center gap-1 text-sm text-zinc-400 transition-colors hover:text-white"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            All applications
                        </Link>
                        <div className="mt-8 mb-6 flex items-center gap-3">
                            <span className="inline-flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-primary to-indigo-600 text-white shadow-lg shadow-primary/40">
                                <Icon className="size-7" aria-hidden />
                            </span>
                            {statusLabel[product.status] && (
                                <span className="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-zinc-200">
                                    {statusLabel[product.status]}
                                </span>
                            )}
                        </div>
                    </>
                }
                title={product.name}
                intro={product.tagline}
            >
                {product.description && (
                    <p className="mt-4 max-w-2xl text-zinc-300">
                        {product.description}
                    </p>
                )}
                <div className="mt-10 flex flex-wrap gap-3">
                    <Link
                        href={`/demo?product=${product.code}`}
                        className={button.primary}
                    >
                        {product.status === 'planned'
                            ? 'Tell me when it launches'
                            : `Book a ${product.name} demo`}
                    </Link>
                    {product.status !== 'planned' && plans.length > 0 && (
                        <a href="#plans" className={button.outlineDark}>
                            See plans
                        </a>
                    )}
                </div>
            </PageHero>

            {product.features.length > 0 && (
                <section aria-label="Features" className="relative">
                    <ul className="mx-auto grid max-w-6xl gap-4 px-4 py-20 sm:grid-cols-2 lg:grid-cols-3">
                        {product.features.map((feature) => (
                            <li
                                key={feature}
                                className="flex items-start gap-3 rounded-2xl border bg-card p-5 shadow-sm"
                            >
                                <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Check className="size-4" aria-hidden />
                                </span>
                                <span className="pt-1 font-medium">
                                    {feature}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {plans.length > 0 && (
                <section
                    id="plans"
                    aria-labelledby="plans-heading"
                    className="scroll-mt-16 border-t bg-muted/40"
                >
                    <div className="mx-auto max-w-6xl px-4 py-20">
                        <h2
                            id="plans-heading"
                            className="text-3xl font-semibold tracking-tight"
                        >
                            Plans that include {product.name}
                        </h2>
                        <ul className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
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
