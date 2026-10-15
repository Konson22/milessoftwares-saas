import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import PageHero from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import Seo from '@/sites/public/components/seo';
import { textLink } from '@/sites/public/components/styles';

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

            <PageHero
                padding={
                    plans.length > 0
                        ? 'pt-16 pb-40 sm:pt-20 sm:pb-44'
                        : 'pt-16 pb-20 sm:pt-20 sm:pb-24'
                }
                className="text-center [&_h1]:mx-auto [&_p]:mx-auto"
                title="Pricing"
                intro="Pick the applications you need. Your whole team shares one workspace and one subscription."
            />

            <section aria-label="Plans" className="mx-auto max-w-6xl px-4">
                {plans.length === 0 ? (
                    <p className="py-16 text-center">
                        Pricing is being finalised.{' '}
                        <Link href="/contact" className={textLink}>
                            Contact us
                        </Link>{' '}
                        for a quote.
                    </p>
                ) : (
                    // Pulled up over the dark band.
                    <ul className="relative -mt-28 grid gap-6 pb-8 sm:grid-cols-2 lg:grid-cols-3">
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

            <section aria-labelledby="faq-heading">
                <div className="mx-auto max-w-3xl px-4 py-24">
                    <h2
                        id="faq-heading"
                        className="text-center text-3xl font-semibold tracking-tight"
                    >
                        Common questions
                    </h2>
                    <div className="mt-10 space-y-3">
                        {faq.map(({ question, answer }) => (
                            <details
                                key={question}
                                className="group rounded-xl border bg-card px-5 py-4 transition-shadow open:shadow-md"
                            >
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium [&::-webkit-details-marker]:hidden">
                                    {question}
                                    <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-muted transition-transform group-open:rotate-45">
                                        <Plus className="size-4" aria-hidden />
                                    </span>
                                </summary>
                                <p className="mt-3 pr-10 text-muted-foreground">
                                    {answer}
                                </p>
                            </details>
                        ))}
                    </div>
                    <p className="mt-10 text-center text-sm text-muted-foreground">
                        Something else?{' '}
                        <Link href="/contact" className={textLink}>
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
