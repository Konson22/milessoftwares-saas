import type { ReactNode } from 'react';
import { display, eyebrow } from '@/sites/public/components/styles';

/**
 * The band public pages open with, carrying the page's h1: centred, on a
 * pale blue wash that fades to white.
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
        <section className="relative isolate overflow-hidden bg-linear-to-b from-site-wash to-white">
            <HeroBackdrop />
            <div
                className={`mx-auto flex max-w-7xl flex-col items-center px-4 text-center sm:px-6 ${padding}`}
            >
                {badge}
                <h1
                    className={`max-w-4xl text-4xl leading-[1.1] sm:text-6xl ${display}`}
                >
                    {title}
                </h1>
                {intro && (
                    <p className="mt-6 max-w-2xl text-lg text-pretty text-slate-600">
                        {intro}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}

/** A faint grid fading out from the top, under the wash. */
export function HeroBackdrop() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgb(11_91_214/0.06)_1px,transparent_1px),linear-gradient(to_bottom,rgb(11_91_214/0.06)_1px,transparent_1px)] mask-[radial-gradient(ellipse_70%_60%_at_50%_0%,black,transparent)] bg-size-[48px_48px]"
        />
    );
}

/** The highlighted phrase of a heading, in the brand blue. */
export function Accent({ children }: { children: ReactNode }) {
    return <span className="text-brand-blue">{children}</span>;
}

/** Pill above a hero title, with a blue dot. */
export function HeroBadge({ children }: { children: ReactNode }) {
    return (
        <p className={`mb-7 ${eyebrow}`}>
            <span className="size-2 rounded-full bg-brand-blue" />
            {children}
        </p>
    );
}
