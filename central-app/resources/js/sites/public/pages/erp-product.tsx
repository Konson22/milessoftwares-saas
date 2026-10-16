import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Plus } from 'lucide-react';
import {
    ErpStatusBadge,
    erpCallToAction,
} from '@/sites/public/components/erp-product';
import type { ErpProduct } from '@/sites/public/components/erp-product';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import { ProductTile } from '@/sites/public/components/product';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, card } from '@/sites/public/components/styles';

type Props = {
    product: ErpProduct;
    plans: Plan[];
    canonical: string;
};

export default function ErpProductPage({ product, plans, canonical }: Props) {
    const summary =
        product.tagline ??
        product.description ??
        `${product.name} by Miles Softwares.`;

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
                            href="/#erp"
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 transition-colors hover:text-brand-blue"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            All ERP products
                        </Link>
                        <div className="mt-8 mb-6 flex items-center gap-3">
                            <ProductTile
                                code={
                                    product.applications[0]?.code ??
                                    product.code
                                }
                                size="lg"
                            />
                            <ErpStatusBadge status={product.status} />
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
                    <Link href="/contact" className={button.primary}>
                        {erpCallToAction[product.status]}
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    {plans.length > 0 && (
                        <a href="#plans" className={button.outline}>
                            See plans
                        </a>
                    )}
                </div>
            </PageHero>

            <section
                aria-labelledby="included-heading"
                className="mx-auto max-w-7xl px-4 py-24 sm:px-6"
            >
                <SectionHeading
                    id="included-heading"
                    label="What it is made of"
                    title={
                        <>
                            The applications in <Accent>{product.name}</Accent>
                        </>
                    }
                    lead="Every Miles ERP product runs on the same platform and posts to the same Finance books."
                />
                <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {product.applications.map((application) => (
                        <li
                            key={application.code}
                            className={`${card} flex items-start gap-4 p-6`}
                        >
                            <ProductTile code={application.code} size="sm" />
                            <div>
                                <p className="font-semibold">
                                    {application.listed ? (
                                        <Link
                                            href={`/products/${application.code}`}
                                            className="hover:text-brand-blue"
                                        >
                                            {application.name}
                                        </Link>
                                    ) : (
                                        application.name
                                    )}
                                </p>
                                <p className="mt-1 inline-flex items-center gap-1.5 text-sm text-slate-600">
                                    {application.requirement === 'required' ? (
                                        <>
                                            <Check
                                                className="size-4 text-emerald-500"
                                                aria-hidden
                                            />
                                            Included
                                        </>
                                    ) : (
                                        <>
                                            <Plus
                                                className="size-4 text-slate-400"
                                                aria-hidden
                                            />
                                            Optional add-on
                                        </>
                                    )}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </section>

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
                            title={`Plans for ${product.name}`}
                        />
                        <ul className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
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
