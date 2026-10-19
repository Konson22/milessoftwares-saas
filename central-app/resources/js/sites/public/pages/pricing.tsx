import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import FaqList from '@/sites/public/components/faq';
import PageHero, {
    Accent,
    HeroBadge,
} from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import {
    button,
    textLink,
    trialLength,
} from '@/sites/public/components/styles';

type Props = {
    plans: Plan[];
    trialDays: number;
    canonical: string;
};

export default function Pricing({ plans, trialDays, canonical }: Props) {
    const trial = trialLength(trialDays);

    return (
        <>
            <Seo
                title="Pricing"
                description={`Plans for every Miles Global Softwares application, alone or bundled. Every application starts with a ${trial} free trial.`}
                canonical={canonical}
            />

            <PageHero
                padding={
                    plans.length > 0
                        ? 'pt-16 pb-36 sm:pt-24 sm:pb-40'
                        : 'pt-16 pb-20 sm:pt-24 sm:pb-24'
                }
                eyebrow={
                    <HeroBadge>
                        Every application starts with a {trial} free trial
                    </HeroBadge>
                }
                title={
                    <>
                        Simple pricing for <Accent>every application</Accent>
                    </>
                }
                intro="Pick the applications you need. Pay for what you use, and add more as your organization grows."
            />

            <section
                aria-label="Plans"
                className="mx-auto max-w-7xl px-4 sm:px-6"
            >
                {plans.length === 0 ? (
                    <div className="py-16 text-center">
                        <p>
                            Pricing is being finalised.{' '}
                            <Link href="/contact" className={textLink}>
                                Contact us
                            </Link>{' '}
                            for a quote, or try an application free while you
                            wait.
                        </p>
                        <Link
                            href="/start"
                            className={`mt-8 ${button.primary}`}
                        >
                            Start your free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                ) : (
                    // Pulled up over the hero.
                    <ul className="relative -mt-24 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
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
                <div className="mx-auto max-w-3xl px-4 py-24 sm:px-6">
                    <SectionHeading
                        id="faq-heading"
                        label="FAQs"
                        title="Common questions"
                    />
                    <div className="mt-12">
                        <FaqList trialDays={trialDays} />
                    </div>
                    <p className="mt-10 text-center text-sm text-slate-600">
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
