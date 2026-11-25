import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Plus, ScrollText, ShieldCheck } from 'lucide-react';
import {
    CtaBand,
    FeatureCards,
    StepBand,
    cardSurface,
} from '@/sites/public/components/blocks';
import {
    cloudPillars,
    cloudSteps,
    platformGroups,
} from '@/sites/public/components/content';
import { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, container, display } from '@/sites/public/components/styles';

type Props = {
    canonical: string;
};

/**
 * Miles Workspace: what every Miles Global Softwares product shares (sign-in, the
 * organization, application access, subscriptions, roles), then its security.
 */
export default function Platform({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Miles Workspace: one place for every app"
                description="One Miles Global Softwares account for every application: subscribe only to the apps you need, with roles per branch and an audit log across your organization."
                canonical={canonical}
            />

            {/*
              The navy hero: the copy and actions on the left; the
              workspace picture on the right from `lg`.
            */}
            <section className="site-dark relative isolate overflow-hidden bg-site-navy text-slate-200">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-24 -right-24 -z-10 hidden size-[28rem] rounded-full border-[28px] border-site-navy-light lg:block"
                />
                <div
                    className={`${container} grid items-center gap-12 pt-28 pb-16 sm:pt-32 lg:grid-cols-[1.05fr_1fr] lg:pb-20`}
                >
                    <div className="site-rise min-w-0">
                        <h1
                            className={`max-w-2xl text-[2rem] leading-[1.1] sm:text-[2.75rem] xl:text-[3.25rem] ${display}`}
                        >
                            Your business applications.{' '}
                            <span className="text-site-highlight">
                                One connected workspace.
                            </span>
                        </h1>
                        <p className="mt-5 max-w-xl text-base leading-relaxed text-pretty text-slate-200 sm:text-lg">
                            One account, one team and one set of permissions for
                            every Miles Global Softwares application you use.
                        </p>
                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            <Link href="/start" className={button.light}>
                                Get Started
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                            <Link
                                href="/pricing"
                                className={button.outlineDark}
                            >
                                See plans and pricing
                            </Link>
                        </div>
                    </div>
                    <div className="hidden lg:block">
                        <WorkspacePicture />
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                aria-labelledby="how-it-works-heading"
                className="scroll-mt-24 bg-white"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
                    <SectionHeading
                        id="how-it-works-heading"
                        title="Everything your applications share, set up once"
                        lead="Each Miles Global Softwares product does its own job. Your workspace holds what they have in common, so adding an application never means a new account, a new staff list or a new set of books."
                        align="left"
                    />
                    <div className="mt-12">
                        <FeatureCards features={cloudPillars} columns={3} />
                    </div>
                </div>
            </section>

            <div id="subscriptions" className="scroll-mt-24">
                <StepBand
                    id="subscriptions"
                    title={
                        <>
                            Pay for the applications{' '}
                            <Accent>you actually use.</Accent>
                        </>
                    }
                    lead="There is no bundle to buy. Your workspace starts with the application you need first, and grows one application at a time."
                    steps={cloudSteps}
                >
                    <Link href="/pricing" className={button.light}>
                        See plans and pricing
                    </Link>
                    <Link href="/products" className={button.outlineDark}>
                        Explore Miles Products
                    </Link>
                </StepBand>
            </div>

            {/* Security and control: one card per group. */}
            <section aria-label="Security and control" className="bg-site-wash">
                <div className="mx-auto grid max-w-7xl gap-6 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-2 lg:px-8">
                    {platformGroups.map((group) => (
                        <article
                            key={group.id}
                            id={group.id}
                            aria-labelledby={`${group.id}-heading`}
                            className={`${cardSurface} flex scroll-mt-24 flex-col overflow-hidden`}
                        >
                            <div className="site-dark relative overflow-hidden bg-site-navy px-7 pt-8 pb-7 sm:px-9">
                                <h2
                                    id={`${group.id}-heading`}
                                    className={`relative text-2xl ${display}`}
                                >
                                    {group.title}
                                </h2>
                                <p className="relative mt-2 max-w-lg text-sm leading-relaxed text-slate-200 sm:text-base">
                                    {group.lead}
                                </p>
                                <span
                                    aria-hidden
                                    className="absolute bottom-0 left-7 h-1 w-12 rounded-t-full bg-site-highlight sm:left-9"
                                />
                            </div>
                            <ul className="divide-y divide-site-line px-7 sm:px-9">
                                {group.points.map(
                                    ({ icon: Icon, title, body }) => (
                                        <li
                                            key={title}
                                            className="flex gap-4 py-5"
                                        >
                                            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-tint text-site-accent-ink">
                                                <Icon
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                            </span>
                                            <div>
                                                <h3 className="font-semibold text-site-ink">
                                                    {title}
                                                </h3>
                                                <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                                    {body}
                                                </p>
                                            </div>
                                        </li>
                                    ),
                                )}
                            </ul>
                        </article>
                    ))}
                </div>
            </section>

            <div className="bg-white pt-16 sm:pt-24">
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

const workspaceApps = ['Finance', 'Retail', 'HR'];
const team = [
    { initials: 'AK', tone: 'bg-site-accent-ink' },
    { initials: 'MJ', tone: 'bg-site-accent-deep' },
    { initials: 'SD', tone: 'bg-site-accent' },
    { initials: 'RT', tone: 'bg-site-accent-deep' },
];
const roles = ['Owner', 'Cashier · Main branch', 'Accountant'];

/**
 * The hero's picture: a workspace card with its applications, team and
 * roles, and two notes floating beside it. Decorative.
 */
function WorkspacePicture() {
    return (
        <div aria-hidden className="relative mx-auto max-w-md py-8">
            <div className="relative rotate-[-2deg] rounded-xl bg-white shadow-2xl ring-1 ring-site-navy-line">
                <div className="flex items-center gap-3 rounded-t-xl bg-site-accent-deep px-6 py-4">
                    <span className="aspect-square w-4 bg-site-highlight [clip-path:polygon(0_0,100%_0,58%_50%,58%_100%,26%_100%,26%_50%,0_50%)]" />
                    <span className="font-semibold text-white">
                        Your workspace
                    </span>
                    <span className="ml-auto flex gap-1.5">
                        {[0, 1, 2].map((i) => (
                            <i
                                key={i}
                                className="size-2 rounded-full bg-site-navy-line"
                            />
                        ))}
                    </span>
                </div>
                <div className="space-y-5 p-6 text-sm">
                    <div>
                        <p className="font-semibold text-slate-500">
                            Applications
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {workspaceApps.map((app) => (
                                <span
                                    key={app}
                                    className="inline-flex items-center gap-1.5 rounded-full bg-site-wash py-1 pr-3 pl-1.5 font-semibold text-site-ink ring-1 ring-site-line"
                                >
                                    <span className="inline-flex size-4 items-center justify-center rounded-full bg-site-accent-ink text-white">
                                        <Check
                                            className="size-3"
                                            strokeWidth={3}
                                        />
                                    </span>
                                    {app}
                                </span>
                            ))}
                            <span className="inline-flex items-center gap-1 rounded-full border border-dashed border-site-accent px-3 py-1 font-semibold text-site-accent-ink">
                                <Plus className="size-3.5" />
                                Add
                            </span>
                        </div>
                    </div>
                    <div>
                        <p className="font-semibold text-slate-500">Team</p>
                        <div className="mt-2 flex items-center">
                            {team.map((member) => (
                                <span
                                    key={member.initials}
                                    className={`-mr-2 inline-flex size-9 items-center justify-center rounded-full text-xs font-bold text-white ring-2 ring-white ${member.tone}`}
                                >
                                    {member.initials}
                                </span>
                            ))}
                            <span className="inline-flex size-9 items-center justify-center rounded-full bg-site-wash text-xs font-bold text-site-accent-ink ring-2 ring-white">
                                +8
                            </span>
                        </div>
                    </div>
                    <div>
                        <p className="font-semibold text-slate-500">Roles</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {roles.map((role) => (
                                <span
                                    key={role}
                                    className="rounded-lg bg-site-soft px-2.5 py-1 font-medium text-slate-700 ring-1 ring-site-line"
                                >
                                    {role}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <div className="absolute top-2 -left-6 site-float">
                <span className="inline-flex items-center gap-2 rounded-full bg-site-highlight px-4 py-2 text-sm font-bold text-site-night shadow-lg shadow-black/30">
                    <ShieldCheck className="size-4" />
                    One sign-in
                </span>
            </div>
            <div className="absolute -right-4 bottom-0 site-float-slow">
                <span className="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-bold text-site-ink shadow-lg shadow-black/30">
                    <ScrollText className="size-4 text-site-accent-ink" />
                    Audit log on
                </span>
            </div>
        </div>
    );
}
