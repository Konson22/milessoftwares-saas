import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import FaqList, { pricingFaq } from '@/sites/public/components/faq';
import { DeploymentOptions } from '@/sites/public/components/offerings';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
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
    // Monthly and yearly plans side by side get a switch; plans billed by
    // agreement always show.
    const hasBoth =
        plans.some((plan) => plan.interval === 'monthly') &&
        plans.some((plan) => plan.interval === 'yearly');
    const [period, setPeriod] = useState<'monthly' | 'yearly'>('monthly');
    const shown = hasBoth
        ? plans.filter(
              (plan) => plan.interval === 'custom' || plan.interval === period,
          )
        : plans;

    return (
        <>
            <Seo
                title="Pricing"
                description={`ERP pricing: cloud plans with desktop and mobile apps included, or a one-time purchase for an offline single branch. Start a ${trial} free trial.`}
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Pay for the ERP you use.{' '}
                        <Accent>Add apps as you grow.</Accent>
                    </>
                }
                intro={`Every ERP includes Finance & Accounting. Subscribe in the cloud, or buy once to run a single branch offline. Start with a ${trial} free trial, no payment details needed.`}
            />

            <section
                aria-label="Plans"
                className="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16"
            >
                {plans.length === 0 ? (
                    <div className="py-8 text-center">
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
                    <>
                        {hasBoth && (
                            <div className="mb-10 flex justify-center">
                                <div
                                    role="group"
                                    aria-label="Billing period"
                                    className="inline-flex rounded-full bg-site-soft p-1.5 ring-1 ring-site-line"
                                >
                                    {(['monthly', 'yearly'] as const).map(
                                        (option) => (
                                            <button
                                                key={option}
                                                type="button"
                                                aria-pressed={period === option}
                                                onClick={() =>
                                                    setPeriod(option)
                                                }
                                                className={`rounded-full px-6 py-2.5 text-sm font-bold transition-colors ${period === option ? 'site-gradient text-white' : 'text-slate-600 hover:text-site-ink'}`}
                                            >
                                                {option === 'monthly'
                                                    ? 'Monthly'
                                                    : 'Yearly'}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}
                        <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {shown.map((plan) => (
                                <PlanCard
                                    key={plan.code}
                                    plan={plan}
                                    headingLevel="h2"
                                />
                            ))}
                        </ul>
                    </>
                )}
            </section>

            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="scroll-mt-24 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="ways-heading"
                        title={
                            <>
                                Subscribe, or <Accent>buy it once</Accent>
                            </>
                        }
                        lead="Cloud plans include the desktop and mobile apps that sync. A single branch that runs offline can buy its ERP once, with no subscription."
                    />
                    <div className="mt-10 sm:mt-14">
                        <DeploymentOptions trialDays={trialDays} />
                    </div>
                </div>
            </section>

            <section aria-labelledby="faq-heading">
                <div className="mx-auto max-w-3xl px-4 py-24 sm:px-6">
                    <SectionHeading id="faq-heading" title="Common questions" />
                    <div className="mt-12">
                        <FaqList items={pricingFaq(trialDays)} />
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
