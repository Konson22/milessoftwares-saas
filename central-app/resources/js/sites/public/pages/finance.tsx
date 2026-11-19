import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { erpCallToAction } from '@/sites/public/components/erp-product';
import type { Plan } from '@/sites/public/components/plan-card';
import ProductProfile from '@/sites/public/components/product-profile';
import type { ProductProfileProps } from '@/sites/public/components/product-profile';
import { button, card } from '@/sites/public/components/styles';

/**
 * Finance & Accounting: the product profile, with its own plan cards
 * (Finance on its own, or bundled with an ERP).
 */
export default function FinancePage(props: ProductProfileProps) {
    return (
        <ProductProfile
            {...props}
            renderPlan={(plan) => <FinancePlan key={plan.code} plan={plan} />}
        />
    );
}

/** Same destinations as the shared plan card, in a box of even height. */
function FinancePlan({ plan }: { plan: Plan }) {
    const standalone = !plan.isBundle;
    const per = {
        monthly: '/ month',
        yearly: '/ year',
        custom: 'billed by agreement',
    }[plan.interval];

    return (
        <li
            className={`${card} flex h-full flex-col p-5 ${standalone ? 'border-site-accent ring-1 ring-site-accent' : ''}`}
        >
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-lg font-semibold tracking-tight text-slate-900">
                    {plan.name}
                </h3>
                <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${standalone ? 'bg-site-accent-ink text-white' : 'bg-slate-100 text-slate-700'}`}
                >
                    {standalone ? 'Finance plan' : 'Bundle'}
                </span>
            </div>
            {plan.product && (
                <p className="mt-1 text-sm font-semibold">
                    <Link
                        href={`/erp/${plan.product.code}`}
                        className="text-slate-900 underline-offset-4 hover:underline"
                    >
                        {plan.product.name}
                    </Link>
                    {plan.product.status === 'pilot' && ' · Early access'}
                </p>
            )}
            {plan.description && (
                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                    {plan.description}
                </p>
            )}
            <p className="mt-4 flex flex-wrap items-baseline gap-x-2">
                <span className="font-public-display text-3xl font-bold tracking-tight text-slate-900 tabular-nums">
                    {plan.price}
                </span>
                {plan.price !== 'Free' && (
                    <span className="text-sm text-slate-600">{per}</span>
                )}
            </p>
            <div className="mt-5 flex-1 border-t border-site-line pt-4">
                <p className="text-sm font-semibold text-slate-900">Includes</p>
                <ul className="mt-2 space-y-2 text-sm text-slate-700">
                    {plan.applications.map((application) => (
                        <li key={application} className="flex gap-2">
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                aria-hidden
                            />
                            {application}
                        </li>
                    ))}
                </ul>
            </div>
            {plan.product ? (
                <Link href="/contact" className={`mt-5 ${button.outline}`}>
                    {erpCallToAction[plan.product.status]}
                    <span className="sr-only"> about {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            ) : (
                <Link
                    href="/start"
                    className={`mt-5 ${standalone ? button.primary : button.outline}`}
                >
                    Start free trial
                    <span className="sr-only"> of {plan.name}</span>
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
            )}
        </li>
    );
}
