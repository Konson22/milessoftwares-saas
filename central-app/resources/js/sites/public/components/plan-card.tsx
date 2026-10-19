import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { erpCallToAction } from '@/sites/public/components/erp-product';
import type { ErpProduct } from '@/sites/public/components/erp-product';
import { button, card } from '@/sites/public/components/styles';

export type Plan = {
    code: string;
    name: string;
    description: string | null;
    price: string;
    interval: 'monthly' | 'yearly' | 'custom';
    trialDays: number;
    isBundle: boolean;
    applications: string[];
    product: {
        code: string;
        name: string;
        status: ErpProduct['status'];
    } | null;
};

const per: Record<Plan['interval'], string> = {
    monthly: '/ month',
    yearly: '/ year',
    custom: 'billed by agreement',
};

/**
 * headingLevel keeps the outline intact: h2 directly under a page title,
 * h3 inside a section that already has an h2. Bundles are the highlighted
 * card, outlined in the accent.
 *
 * Every plan starts the same way: a free trial of the application from a
 * new workspace (/start); billing follows once it ends. A plan that sells
 * an ERP product starts with a conversation instead: a trial covers one
 * application, and the product needs several.
 */
export default function PlanCard({
    plan,
    headingLevel: Heading = 'h3',
}: {
    plan: Plan;
    headingLevel?: 'h2' | 'h3';
}) {
    const featured = plan.isBundle;

    return (
        <li
            className={`${card} relative flex flex-col p-8 ${featured ? 'border-site-accent ring-1 ring-site-accent' : ''}`}
        >
            <div className="flex items-start justify-between gap-4">
                <Heading className="text-xl font-semibold tracking-tight">
                    {plan.name}
                </Heading>
                {featured && (
                    <span className="rounded-full bg-site-accent-ink px-3 py-1 text-xs font-bold tracking-wide text-white uppercase">
                        Bundle
                    </span>
                )}
            </div>
            {plan.product && (
                <p className="mt-2 text-sm font-semibold">
                    <Link
                        href={`/erp/${plan.product.code}`}
                        className="text-site-accent-ink hover:underline"
                    >
                        {plan.product.name}
                    </Link>
                    {plan.product.status === 'pilot' && ' · Pilot'}
                </p>
            )}
            {plan.description && (
                <p className="mt-2 text-sm text-slate-600">
                    {plan.description}
                </p>
            )}
            <p className="mt-8 flex flex-wrap items-baseline gap-x-2">
                <span className="font-public-display text-4xl font-bold tracking-tight tabular-nums">
                    {plan.price}
                </span>
                {plan.price !== 'Free' && (
                    <span className="text-sm text-slate-600">
                        {per[plan.interval]}
                    </span>
                )}
            </p>
            {plan.product ? (
                <Link
                    href="/contact"
                    className={`mt-7 ${featured ? button.primary : button.outline}`}
                >
                    {erpCallToAction[plan.product.status]}
                    <span className="sr-only"> about {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            ) : (
                <Link
                    href="/start"
                    className={`mt-7 ${featured ? button.primary : button.outline}`}
                >
                    Start free trial
                    <span className="sr-only"> of {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            )}
            <div className="mt-8 border-t border-site-line pt-7">
                <p className="text-sm font-semibold">Includes</p>
                <ul className="mt-4 space-y-3 text-sm">
                    {plan.applications.map((application) => (
                        <li key={application} className="flex gap-2.5">
                            <span className="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-site-accent text-white">
                                <Check className="size-3" aria-hidden />
                            </span>
                            {application}
                        </li>
                    ))}
                </ul>
            </div>
        </li>
    );
}
