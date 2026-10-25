import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { useState } from 'react';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import FaqList, { pricingFaq } from '@/sites/public/components/faq';
import {
    DeploymentOptions,
    quoteUrl,
} from '@/sites/public/components/offerings';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import PlanCard from '@/sites/public/components/plan-card';
import type { Plan } from '@/sites/public/components/plan-card';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import {
    button,
    display,
    panel,
    textLink,
    trialDuration,
    trialLength,
} from '@/sites/public/components/styles';

type Props = {
    plans: Plan[];
    catalog: CatalogEntry[];
    trialDays: number;
    canonical: string;
};

/** Ready-made, customized and fully custom software, and how each is priced. */
const purchasePaths = [
    {
        title: 'Ready-made software',
        points: [
            'Subscription licences, monthly or yearly',
            'One-time desktop licences where offered',
            'Free trial on products open for one',
        ],
        href: '#plans',
        cta: 'See the plans',
    },
    {
        title: 'Customizing a Miles product',
        points: [
            'The product licence',
            'A setup or customization charge where applicable',
        ],
        href: quoteUrl('Quote: ERP customization'),
        cta: 'Request a quotation',
    },
    {
        title: 'Fully custom software',
        points: [
            'Requirements assessment',
            'Project quotation',
            'Optional maintenance agreement',
        ],
        href: quoteUrl('Project: Custom software'),
        cta: 'Discuss your project',
    },
];

export default function Pricing({
    plans,
    catalog,
    trialDays,
    canonical,
}: Props) {
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
                description={`Subscriptions for ready-made Miles software, one-time licences where offered, and quotations for customization and custom software. ${trial} free trial.`}
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Pay for the software you use.{' '}
                        <Accent>Add more as you grow.</Accent>
                    </>
                }
                intro={`Finance & Accounting is an optional add-on to any ERP. Subscribe to a ready-made product, buy a standalone offline edition once where one is offered, or ask us for a quotation. Products open for trial can be tried free for ${trialDuration(trialDays)}, no payment details needed.`}
            />

            <section
                id="plans"
                aria-label="Plans"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-12 sm:px-6 sm:py-16"
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
                id="ways-to-buy"
                aria-labelledby="buy-heading"
                className="scroll-mt-24 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="buy-heading"
                        title={
                            <>
                                Three ways to <Accent>buy</Accent>
                            </>
                        }
                        lead="Ready-made software has a published price. Customization and custom software are priced by quotation."
                    />
                    <ul className="mt-10 grid gap-5 sm:mt-14 lg:grid-cols-3">
                        {purchasePaths.map((path) => (
                            <li
                                key={path.title}
                                className={`${panel} flex flex-col p-7 sm:p-8`}
                            >
                                <h3 className={`text-xl ${display}`}>
                                    {path.title}
                                </h3>
                                <ul className="mt-4 flex-1 space-y-2.5 text-sm text-slate-700">
                                    {path.points.map((point) => (
                                        <li
                                            key={point}
                                            className="flex items-start gap-2.5"
                                        >
                                            <Check
                                                className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                                aria-hidden
                                            />
                                            {point}
                                        </li>
                                    ))}
                                </ul>
                                {/* An anchor on this page is a plain link. */}
                                {path.href.startsWith('#') ? (
                                    <a
                                        href={path.href}
                                        className={`mt-7 self-start ${button.outline}`}
                                    >
                                        {path.cta}
                                    </a>
                                ) : (
                                    <Link
                                        href={path.href}
                                        className={`mt-7 self-start ${button.outline}`}
                                    >
                                        {path.cta}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <SectionHeading
                    id="ways-heading"
                    title={
                        <>
                            Subscribe, or <Accent>buy it once</Accent>
                        </>
                    }
                    lead="Where a product has desktop or mobile apps that sync, they come with its cloud plan. Where a standalone offline edition is offered, it is bought once, with no subscription."
                />
                <div className="mt-10 sm:mt-14">
                    <DeploymentOptions
                        catalog={catalog}
                        trialDays={trialDays}
                    />
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
