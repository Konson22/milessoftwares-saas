import type { ReactNode } from 'react';
import { display, eyebrow } from '@/sites/public/components/styles';

/**
 * The band public pages open with: a soft lavender wash with floating
 * shapes, centred title and intro. Content below sits flush.
 */
export default function PageHero({
    eyebrow: badge,
    title,
    intro,
    children,
    padding = 'pt-16 pb-16 sm:pt-24 sm:pb-24',
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    padding?: string;
}) {
    return (
        <section className="relative isolate overflow-hidden border-b border-site-line bg-linear-to-b from-site-wash via-site-soft to-white">
            <LightBackdrop />
            <div
                className={`site-rise mx-auto flex max-w-7xl flex-col items-center px-4 text-center sm:px-6 ${padding}`}
            >
                {badge}
                <h1
                    className={`mt-0 max-w-4xl text-[2.125rem] leading-[1.1] sm:text-6xl ${display}`}
                >
                    {title}
                </h1>
                {intro && (
                    <p className="mt-5 max-w-2xl text-base text-pretty text-slate-600 sm:mt-6 sm:text-lg">
                        {intro}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}

/**
 * Soft gradient blobs, rings and a dotted patch behind a light hero.
 * Decorative only.
 */
export function LightBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="site-float-slow absolute -top-32 -left-24 size-[28rem] rounded-full bg-site-accent/15 blur-3xl" />
            <div className="site-float absolute -right-24 -bottom-40 size-[30rem] rounded-full bg-site-violet/15 blur-3xl" />
            <div className="absolute top-16 right-[7%] hidden size-28 rounded-full border-2 border-dashed border-site-accent/25 md:block" />
            <div className="site-float absolute top-[22%] left-[6%] hidden size-4 rounded-full bg-site-coral/70 md:block" />
            <div className="site-float-slow absolute right-[16%] bottom-[18%] hidden size-3 rounded-full bg-site-purple/70 md:block" />
            <div className="site-dots absolute bottom-6 left-[12%] hidden h-24 w-40 text-site-accent/20 md:block" />
        </div>
    );
}

/**
 * Soft colour washes behind a dark band. Decorative only.
 */
export function HeroBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="absolute -top-40 -left-32 size-[34rem] rounded-full bg-site-accent/25 blur-3xl" />
            <div className="absolute -right-40 -bottom-48 size-[36rem] rounded-full bg-site-violet/30 blur-3xl" />
            <div className="absolute top-12 right-[8%] size-40 rounded-full border border-white/10" />
            <div className="site-dots absolute bottom-8 left-[10%] h-24 w-40 text-white/15" />
        </div>
    );
}

/** The highlighted phrase of a heading: the blue → violet gradient. */
export function Accent({ children }: { children: ReactNode }) {
    return <span className="site-gradient-text">{children}</span>;
}

/** Label above a hero title. */
export function HeroBadge({ children }: { children: ReactNode }) {
    return <p className={`mb-6 ${eyebrow}`}>{children}</p>;
}
