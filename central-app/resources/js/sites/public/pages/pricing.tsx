import { Link } from '@inertiajs/react';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import Seo from '@/sites/public/components/seo';

type Props = {
    plans: Plan[];
    canonical: string;
};

export default function Pricing({ plans, canonical }: Props) {
    return (
        <>
            <Seo
                title="Pricing"
                description="Plans for every Miles Global Softwares application, alone or bundled, with free trials."
                canonical={canonical}
            />

            <section className="mx-auto max-w-6xl px-4 py-16">
                <h1 className="text-4xl font-semibold tracking-tight">
                    Pricing
                </h1>
                <p className="mt-4 max-w-xl text-lg text-muted-foreground">
                    Pick the applications you need. Your whole team shares one
                    workspace and one subscription.
                </p>

                {plans.length === 0 ? (
                    <p className="mt-10">
                        Pricing is being finalised.{' '}
                        <Link
                            href="/contact"
                            className="font-medium text-primary hover:underline"
                        >
                            Contact us
                        </Link>{' '}
                        for a quote.
                    </p>
                ) : (
                    <ul className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((plan) => (
                            <PlanCard
                                key={plan.code}
                                plan={plan}
                                headingLevel="h2"
                            />
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}
