import type { ReactNode } from 'react';
import type { Feature } from '@/sites/public/components/content';
import { HeroBackdrop, IconBox } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import { darkBand } from '@/sites/public/components/styles';

/**
 * Features in a grid, as feature lists across the site read: icon, title
 * and a sentence or two under a hairline, no boxes. Two columns from sm,
 * `columns` from lg.
 */
export function FeatureGrid({
    features,
    columns = 3,
    headingLevel: Heading = 'h3',
}: {
    features: Feature[];
    columns?: 2 | 3 | 4;
    headingLevel?: 'h2' | 'h3';
}) {
    const lg = {
        2: 'lg:grid-cols-2',
        3: 'lg:grid-cols-3',
        4: 'lg:grid-cols-4',
    }[columns];

    return (
        <ul className={`grid gap-x-10 gap-y-10 sm:grid-cols-2 ${lg}`}>
            {features.map(({ icon, title, body }, i) => (
                <li
                    key={title}
                    className="flex items-start gap-4 border-t border-site-line pt-6 text-site-ink in-[.site-dark]:border-white/15 in-[.site-dark]:text-white sm:flex-col sm:gap-0"
                >
                    <IconBox icon={icon} index={i} />
                    <div>
                        <Heading className="text-base font-semibold sm:mt-5 sm:text-lg">
                            {title}
                        </Heading>
                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600 in-[.site-dark]:text-slate-300 sm:mt-2">
                            {body}
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * Features as cards: a dark green icon chip, title and a sentence or two
 * on a white card that lifts on hover. Five features in three columns
 * become two wide cards over three.
 */
export function FeatureCards({
    features,
    columns = 3,
    headingLevel: Heading = 'h3',
}: {
    features: Feature[];
    columns?: 2 | 3 | 4;
    headingLevel?: 'h2' | 'h3';
}) {
    const bento = columns === 3 && features.length === 5;
    const lg = bento
        ? 'lg:grid-cols-6'
        : { 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4' }[
              columns
          ];

    return (
        <ul className={`grid gap-5 sm:grid-cols-2 ${lg}`}>
            {features.map(({ icon: Icon, title, body }, i) => (
                <li
                    key={title}
                    className={`${cardSurface} group p-6 sm:p-7 ${bento ? (i < 2 ? 'lg:col-span-3' : 'lg:col-span-2') : ''}`}
                >
                    <span className="inline-flex size-12 items-center justify-center rounded-2xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight shadow-md shadow-site-night/20 motion-safe:transition-transform motion-safe:duration-300 group-hover:-rotate-6">
                        <Icon className="size-6" aria-hidden />
                    </span>
                    <Heading className="mt-5 text-lg font-semibold text-site-ink">
                        {title}
                    </Heading>
                    <p className="mt-2 text-sm leading-relaxed text-slate-600">
                        {body}
                    </p>
                </li>
            ))}
        </ul>
    );
}

/** The white card surface of the redesigned pages, with a hover lift. */
export const cardSurface =
    'rounded-xl bg-white ring-1 ring-site-line shadow-[0_1px_2px_rgb(5_69_27/0.06),0_10px_30px_-12px_rgb(5_69_27/0.18)] transition-[box-shadow,transform] duration-300 hover:-translate-y-1 hover:shadow-[0_2px_4px_rgb(5_69_27/0.08),0_24px_48px_-16px_rgb(5_69_27/0.3)]';

/** Two digits, as numbered steps read: 01, 02, … */
const ordinal = (i: number) => String(i + 1).padStart(2, '0');

/**
 * Numbered steps on a dark green band: a heading, the steps in a row of
 * cards joined by a dashed line, and optional actions under them.
 */
export function StepBand({
    id,
    title,
    lead,
    steps,
    children,
    className = '',
}: {
    id: string;
    title: ReactNode;
    lead?: string;
    steps: { title: string; body: string }[];
    children?: ReactNode;
    className?: string;
}) {
    return (
        <section
            aria-labelledby={`${id}-heading`}
            className={`site-dark relative isolate overflow-hidden bg-[radial-gradient(circle_at_15%_100%,#11512b,#063519_55%,#032a16)] text-slate-300 ${className}`}
        >
            <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
                <SectionHeading
                    id={`${id}-heading`}
                    title={title}
                    lead={lead}
                    align="left"
                />
                <ol className="relative mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <span
                        aria-hidden
                        className="absolute top-11 right-[12%] left-[12%] hidden border-t-2 border-dashed border-white/15 lg:block"
                    />
                    {steps.map((step, i) => (
                        <li
                            key={step.title}
                            className="relative rounded-xl bg-white/[0.06] p-6 ring-1 ring-white/10 backdrop-blur-sm transition-colors hover:bg-white/[0.09] sm:p-7"
                        >
                            <span className="inline-flex size-10 items-center justify-center rounded-full bg-site-highlight font-public-display text-sm font-semibold text-site-night shadow-[0_0_0_6px_rgb(139_209_3/0.15)]">
                                {ordinal(i)}
                            </span>
                            <h3 className="mt-5 text-lg font-semibold text-white">
                                {step.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-300">
                                {step.body}
                            </p>
                        </li>
                    ))}
                </ol>
                {children && (
                    <div className="mt-10 flex flex-wrap gap-3">{children}</div>
                )}
            </div>
        </section>
    );
}

/**
 * The closing band most pages end with: a slab with a
 * heading, a line and the actions beside it (deep navy).
 */
export function CtaBand({
    id,
    title,
    body,
    children,
}: {
    id: string;
    title: ReactNode;
    body: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            className="px-4 pb-16 sm:px-6 sm:pb-24 lg:px-8"
        >
            <div
                className={`${darkBand} mx-auto grid max-w-7xl items-center gap-8 rounded-lg px-6 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1fr_auto]`}
            >
                <HeroBackdrop />
                <div className="text-center lg:text-left">
                    <h2
                        id={id}
                        className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl sm:leading-tight"
                    >
                        {title}
                    </h2>
                    <p className="mt-4 max-w-xl text-slate-300 lg:max-w-2xl">
                        {body}
                    </p>
                </div>
                <div className="flex flex-wrap justify-center gap-3">
                    {children}
                </div>
            </div>
        </section>
    );
}

/** Where a waitlist button leads: the contact form, subject filled in. */
export function waitlistUrl(name: string): string {
    return `/contact?subject=${encodeURIComponent(`Waitlist: ${name}`)}`;
}
