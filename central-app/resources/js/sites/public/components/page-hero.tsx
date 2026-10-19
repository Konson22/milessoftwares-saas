import type { ReactNode } from 'react';
import { darkBand, display, eyebrow } from '@/sites/public/components/styles';

/**
 * The band public pages open with, carrying the page's h1: deep navy with
 * soft orange and blue light, rings and dot grids, text centred. Content
 * that follows can overlap its lower edge (pages pull their first card up
 * with a negative margin).
 */
export default function PageHero({
    eyebrow: badge,
    title,
    intro,
    children,
    padding = 'pt-16 pb-20 sm:pt-24 sm:pb-24',
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    padding?: string;
}) {
    return (
        <section className={darkBand}>
            <HeroBackdrop />
            <div
                className={`mx-auto flex max-w-7xl flex-col items-center px-4 text-center sm:px-6 ${padding}`}
            >
                {badge}
                <h1
                    className={`mt-0 max-w-4xl text-[2.125rem] leading-[1.1] sm:text-6xl ${display}`}
                >
                    {title}
                </h1>
                {intro && (
                    <p className="mt-5 max-w-2xl text-base text-pretty text-slate-300 sm:mt-6 sm:text-lg">
                        {intro}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}

/**
 * Light and shapes behind a navy band: an orange glow on one side, a blue
 * one on the other, two thin rings and two dot grids. Decorative only.
 */
export function HeroBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="absolute -top-40 -left-32 size-[34rem] rounded-full bg-site-accent/20 blur-3xl" />
            <div className="absolute -right-40 -bottom-48 size-[36rem] rounded-full bg-brand-blue/30 blur-3xl" />
            <div className="absolute top-12 right-[8%] size-40 rounded-full border border-white/10" />
            <div className="absolute top-24 right-[10%] size-24 rounded-full border-2 border-site-accent/40" />
            <div className="site-dots absolute bottom-10 left-[6%] h-28 w-40 text-white/15" />
            <div className="site-dots absolute top-16 left-[42%] hidden h-16 w-28 text-site-accent/30 lg:block" />
            <div className="absolute inset-x-0 bottom-0 h-px bg-linear-to-r from-transparent via-white/15 to-transparent" />
        </div>
    );
}

/** The highlighted phrase of a heading: orange, in the shade that reads on its background. */
export function Accent({ children }: { children: ReactNode }) {
    return (
        <span className="text-site-accent-ink in-[.site-dark]:text-site-accent-glow">
            {children}
        </span>
    );
}

/** Label above a hero title. */
export function HeroBadge({ children }: { children: ReactNode }) {
    return <p className={`mb-6 ${eyebrow}`}>{children}</p>;
}
