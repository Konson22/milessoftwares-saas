import { Check } from 'lucide-react';
import { Badge } from '@/shared/components/ui/badge';
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
 * h3 inside a section that already has an h2.
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
            className={`flex flex-col rounded-xl border bg-background p-6 ${plan.isBundle ? 'border-primary shadow-sm' : ''}`}
        >
            <div className="flex items-center gap-2">
                <Heading className="text-lg font-semibold">{plan.name}</Heading>
                {plan.isBundle && <Badge variant="secondary">Bundle</Badge>}
            </div>
            <p className="mt-3">
                <span className="text-3xl font-semibold tabular-nums">
                    {plan.price}
                </span>{' '}
                {plan.price !== 'Free' && (
                    <span className="text-sm text-muted-foreground">
                        {per[plan.interval]}
                    </span>
                )}
            </p>
            {plan.description && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {plan.description}
                </p>
            )}
            <p className="mt-5 text-sm font-medium">Includes</p>
            <ul className="mt-2 flex-1 space-y-2 text-sm">
                {plan.applications.map((application) => (
                    <li key={application} className="flex gap-2">
                        <Check
                            className="mt-0.5 size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        {application}
                    </li>
                ))}
            </ul>
            <a
                href={register.url()}
                className="mt-6 rounded-md bg-primary px-4 py-2 text-center text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
                {plan.trialDays > 0
                    ? `Start a ${plan.trialDays}-day trial`
                    : 'Get started'}
                <span className="sr-only"> with {plan.name}</span>
            </a>
        </li>
    );
}
