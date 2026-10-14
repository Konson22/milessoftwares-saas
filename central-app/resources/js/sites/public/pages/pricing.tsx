import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
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

            <section aria-labelledby="faq-heading" className="border-t">
                <div className="mx-auto max-w-3xl px-4 py-16">
                    <h2
                        id="faq-heading"
                        className="text-2xl font-semibold tracking-tight"
                    >
                        Common questions
                    </h2>
                    <div className="mt-8 divide-y border-y">
                        {faq.map(({ question, answer }) => (
                            <details key={question} className="group py-4">
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium [&::-webkit-details-marker]:hidden">
                                    {question}
                                    <ChevronDown
                                        className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                                        aria-hidden
                                    />
                                </summary>
                                <p className="mt-3 text-muted-foreground">
                                    {answer}
                                </p>
                            </details>
                        ))}
                    </div>
                    <p className="mt-8 text-sm text-muted-foreground">
                        Something else?{' '}
                        <Link
                            href="/contact"
                            className="font-medium text-primary hover:underline"
                        >
                            Ask us
                        </Link>
                        .
                    </p>
                </div>
            </section>
        </>
    );
}

const faq = [
    {
        question: 'Can I try an application before paying?',
        answer: 'Plans with a free trial show its length on the plan. Your workspace is ready as soon as you create it.',
    },
    {
        question: 'Can I add more applications later?',
        answer: 'Yes. Your organization keeps one workspace and one subscription, and new applications use the team, branches and roles you have already set up.',
    },
    {
        question: 'Do my people need a separate account for each application?',
        answer: 'No. Everyone signs in once, and you decide which applications and which parts of them each person can use.',
    },
    {
        question: 'We need something different. Can you help?',
        answer: 'Larger organizations and special arrangements are billed by agreement. Contact us or book a demo and we will put together a quote.',
    },
];
