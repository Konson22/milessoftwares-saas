import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
} from '@/sites/public/components/blocks';
import {
    cloudPillars,
    cloudSteps,
    platformGroups,
} from '@/sites/public/components/content';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, panel } from '@/sites/public/components/styles';

type Props = {
    canonical: string;
};

/**
 * Miles Workspace: what every Miles product shares (sign-in, the
 * organization, application access, subscriptions, roles), then its security.
 */
export default function Platform({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Miles Workspace: one place for every application"
                description="Miles Workspace gives your organization one account for every Miles application, subscriptions for only the apps you need, roles per branch and an audit log."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Your business applications.{' '}
                        <Accent>One connected workspace.</Accent>
                    </>
                }
                intro="One account, one team and one set of permissions for every Miles application you use."
            />

            <section
                id="how-it-works"
                aria-labelledby="how-it-works-heading"
                className="scroll-mt-24"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <Eyebrow>How Miles Workspace works</Eyebrow>
                    <SectionHeading
                        id="how-it-works-heading"
                        title="Everything your applications share, set up once"
                        lead="Each Miles product does its own job. Your workspace holds what they have in common, so adding an application never means a new account, a new staff list or a new set of books."
                        align="left"
                    />
                    <div className="mt-10">
                        <FeatureGrid features={cloudPillars} columns={3} />
                    </div>
                </div>
            </section>

            <section
                id="subscriptions"
                aria-labelledby="subscriptions-heading"
                className="scroll-mt-24 border-y border-site-line bg-white"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <Eyebrow>Subscriptions</Eyebrow>
                    <SectionHeading
                        id="subscriptions-heading"
                        title={
                            <>
                                Pay for the applications{' '}
                                <Accent>you actually use.</Accent>
                            </>
                        }
                        lead="There is no bundle to buy. Your workspace starts with the application you need first, and grows one application at a time."
                        align="left"
                    />
                    <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {cloudSteps.map((step, i) => (
                            <li
                                key={step.title}
                                className={`${panel} p-6 sm:p-7`}
                            >
                                <span className="inline-flex size-11 items-center justify-center rounded-full bg-site-accent-ink font-public-display text-sm font-bold text-white">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                <h3 className="mt-5 text-lg font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-10 flex flex-wrap gap-3">
                        <Link href="/pricing" className={button.outline}>
                            See plans and pricing
                        </Link>
                        <Link href="/#products" className={button.outline}>
                            Explore Miles Products
                        </Link>
                    </div>
                </div>
            </section>

            {platformGroups.map((group, i) => (
                <section
                    key={group.id}
                    id={group.id}
                    aria-labelledby={`${group.id}-heading`}
                    className={`scroll-mt-24 ${i % 2 === 1 ? 'border-y border-site-line bg-white' : ''}`}
                >
                    <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                        <Eyebrow>Security and control</Eyebrow>
                        <SectionHeading
                            id={`${group.id}-heading`}
                            title={group.title}
                            lead={group.lead}
                            align="left"
                        />
                        <div className="mt-10">
                            <FeatureGrid features={group.points} />
                        </div>
                    </div>
                </section>
            ))}

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="platform-cta-heading"
                    title={
                        <>
                            Start with one application.{' '}
                            <Accent>Add the rest when you are ready.</Accent>
                        </>
                    }
                    body="Create your workspace with a free trial, or ask us how it would fit your organization."
                >
                    <Link href="/start" className={button.light}>
                        Get Started
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/contact" className={button.outlineDark}>
                        Talk to us
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
