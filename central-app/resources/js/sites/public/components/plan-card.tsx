import { Check } from 'lucide-react';
import { button } from '@/sites/public/components/styles';
import { register } from '@/routes';

export type Plan = {
    code: string;
    name: string;
    description: string | null;
    price: string;
    interval: 'monthly' | 'yearly' | 'custom';
    trialDays: number;
    isBundle: boolean;
    applications: string[];
};

const per: Record<Plan['interval'], string> = {
    monthly: 'per month',
    yearly: 'per year',
    custom: 'billed by agreement',
};

/**
 * headingLevel keeps the outline intact: h2 directly under a page title,
 * h3 inside a section that already has an h2. Bundles stand out with a
 * brand border and glow.
 */
export default function PlanCard({
    plan,
    headingLevel: Heading = 'h3',
}: {
    plan: Plan;
    headingLevel?: 'h2' | 'h3';
}) {
    return (
        <li
            className={`relative flex flex-col rounded-2xl border bg-card p-7 shadow-sm ${plan.isBundle ? 'border-primary shadow-xl ring-1 shadow-primary/15 ring-primary' : ''}`}
        >
            {plan.isBundle && (
                <span className="absolute -top-3 left-7 rounded-full bg-primary px-3 py-0.5 text-xs font-semibold text-primary-foreground shadow-md shadow-primary/30">
                    Bundle
                </span>
            )}
            <Heading className="text-lg font-semibold">{plan.name}</Heading>
            {plan.description && (
                <p className="mt-2 text-sm text-muted-foreground">
                    {plan.description}
                </p>
            )}
            <p className="mt-6 flex flex-wrap items-baseline gap-x-2">
                <span className="text-4xl font-semibold tracking-tight tabular-nums">
                    {plan.price}
                </span>
                {plan.price !== 'Free' && (
                    <span className="text-sm text-muted-foreground">
                        {per[plan.interval]}
                    </span>
                )}
            </p>
            <a
                href={register.url()}
                className={`mt-6 ${plan.isBundle ? button.primary : button.outline} py-2.5`}
            >
                {plan.trialDays > 0
                    ? `Start a ${plan.trialDays}-day trial`
                    : 'Get started'}
                <span className="sr-only"> with {plan.name}</span>
            </a>
            <div className="mt-7 border-t pt-6">
                <p className="text-sm font-medium">Includes</p>
                <ul className="mt-3 space-y-2.5 text-sm">
                    {plan.applications.map((application) => (
                        <li key={application} className="flex gap-2.5">
                            <span className="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
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
