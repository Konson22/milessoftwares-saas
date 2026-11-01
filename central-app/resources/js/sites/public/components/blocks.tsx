import type { ReactNode } from 'react';
import type { Feature } from '@/sites/public/components/content';
import { HeroBackdrop, IconBox } from '@/sites/public/components/page-hero';
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
                className={`${darkBand} mx-auto grid max-w-7xl items-center gap-8 rounded-2xl px-6 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1fr_auto]`}
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
