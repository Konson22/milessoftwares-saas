import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { ProductTile, statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import {
    button,
    card,
    darkBand,
    trialDuration,
    trialLength,
    trialUrl,
} from '@/sites/public/components/styles';

type Props = {
    product: Product;
    plans: Plan[];
    trialDays: number;
    canonical: string;
};

export default function ProductPage({
    product,
    plans,
    trialDays,
    canonical,
}: Props) {
    const summary =
        product.tagline ??
        product.description ??
        `${product.name} by Miles Global Softwares.`;
    const trialable = product.status !== 'planned';

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
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 transition-colors hover:text-site-accent-ink"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            All applications
                        </Link>
                        <div className="mt-8 mb-6 flex items-center gap-3">
                            <ProductTile code={product.code} size="lg" />
                            {statusLabel[product.status] && (
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${product.status === 'beta' ? 'site-gradient text-white' : 'bg-white text-slate-700 ring-1 ring-site-line'}`}
                                >
                                    {statusLabel[product.status]}
                                </span>
                            )}
                        </div>
                    </>
                }
                title={<Accent>{product.name}</Accent>}
                intro={product.tagline}
            >
                {product.description && (
                    <p className="mt-3 max-w-2xl text-slate-600">
                        {product.description}
                    </p>
                )}
                <div className="mt-9 flex flex-wrap justify-center gap-3">
                    {trialable ? (
                        <a
                            href={trialUrl(product.code)}
                            className={button.primary}
                        >
                            Start {trialLength(trialDays)} free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    ) : (
                        <Link href="/contact" className={button.primary}>
                            Tell me when it launches
                        </Link>
                    )}
                    {trialable && plans.length > 0 && (
                        <a href="#plans" className={button.outline}>
                            See plans
                        </a>
                    )}
                </div>
            </PageHero>

            {product.features.length > 0 && (
                <section
                    aria-labelledby="features-heading"
                    className="mx-auto max-w-7xl px-4 py-24 sm:px-6"
                >
                    <SectionHeading
                        id="features-heading"
                        label="Features"
                        title={
                            <>
                                What <Accent>{product.name}</Accent> does
                            </>
                        }
                    />
                    <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {product.features.map((feature) => (
                            <li
                                key={feature}
                                className={`${card} flex items-start gap-4 p-6`}
                            >
                                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink">
                                    <Check className="size-5" aria-hidden />
                                </span>
                                <span className="pt-2 font-semibold">
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
                    className="scroll-mt-20 border-y border-site-line bg-site-soft"
                >
                    <div className="mx-auto max-w-7xl px-4 py-24 sm:px-6">
                        <SectionHeading
                            id="plans-heading"
                            label="Pricing"
                            title={`Plans that include ${product.name}`}
                        />
                        <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard key={plan.code} plan={plan} />
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {trialable && (
                <section
                    aria-labelledby="try-heading"
                    className="px-4 py-16 sm:px-6 sm:py-24"
                >
                    <div
                        className={`${darkBand} mx-auto flex max-w-7xl flex-col items-center gap-6 rounded-[2rem] px-6 py-14 text-center sm:px-12`}
                    >
                        <h2
                            id="try-heading"
                            className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl"
                        >
                            Try {product.name} free for{' '}
                            {trialDuration(trialDays)}
                        </h2>
                        <p className="max-w-xl text-slate-300">
                            Create your workspace and start today. No payment
                            details are needed.
                        </p>
                        <a
                            href={trialUrl(product.code)}
                            className={button.light}
                        >
                            Start free trial
                            <span className="sr-only"> of {product.name}</span>
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </div>
                </section>
            )}
        </>
    );
}
