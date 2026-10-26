import type { ReactNode } from 'react';
import type { Feature } from '@/sites/public/components/content';
import { HeroBackdrop, IconBox } from '@/sites/public/components/page-hero';
import { card, darkBand } from '@/sites/public/components/styles';

/**
 * Icon cards in a grid, as feature lists across the site read: icon,
 * title and a sentence or two. Two columns from sm, `columns` from lg.
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
        <ul className={`grid gap-4 sm:grid-cols-2 sm:gap-6 ${lg}`}>
            {features.map(({ icon, title, body }, i) => (
                <li
                    key={title}
                    className={`${card} group relative flex items-start gap-5 overflow-hidden p-6 text-site-ink in-[.site-dark]:border-transparent sm:flex-col sm:gap-0 sm:p-8`}
                >
                    <span
                        aria-hidden
                        className="absolute inset-x-0 bottom-0 h-1 origin-left scale-x-0 bg-site-accent transition-transform duration-300 group-hover:scale-x-100"
                    />
                    <IconBox icon={icon} index={i} />
                    <div>
                        <Heading className="text-lg font-semibold sm:mt-6">
                            {title}
                        </Heading>
                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600 sm:mt-2">
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
        <section aria-labelledby={id} className="px-4 pb-16 sm:px-6 sm:pb-28">
            <div
                className={`${darkBand} mx-auto grid max-w-7xl items-center gap-8 rounded-[2rem] px-6 py-14 sm:px-14 sm:py-20 lg:grid-cols-[1fr_auto]`}
            >
                <HeroBackdrop />
                <div className="text-center lg:text-left">
                    <h2
                        id={id}
                        className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-[2.6rem] sm:leading-tight"
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

/** A small label over a section heading. */
export function Eyebrow({ children }: { children: ReactNode }) {
    return (
        <p className="mb-3 text-sm font-bold tracking-wider text-site-accent-ink uppercase in-[.site-dark]:text-white/80">
            {children}
        </p>
    );
}

/** Where a waitlist button leads: the contact form, subject filled in. */
export function waitlistUrl(name: string): string {
    return `/contact?subject=${encodeURIComponent(`Waitlist: ${name}`)}`;
}
