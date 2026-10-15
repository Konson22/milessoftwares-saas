import type { ReactNode } from 'react';

/**
 * The dark band every public page opens with, carrying the page's h1.
 * It stays dark in both themes; the header above it is dark too, so the
 * two read as one surface.
 */
export default function PageHero({
    eyebrow,
    title,
    intro,
    children,
    className = '',
    padding = 'pt-16 pb-20 sm:pt-20 sm:pb-24',
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    className?: string;
    padding?: string;
}) {
    return (
        <section
            className={`relative isolate overflow-hidden bg-zinc-950 text-zinc-300 ${className}`}
        >
            <HeroBackdrop />
            <div className={`mx-auto max-w-6xl px-4 ${padding}`}>
                {eyebrow}
                <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance text-white sm:text-5xl">
                    {title}
                </h1>
                {intro && (
                    <p className="mt-5 max-w-xl text-lg text-pretty text-zinc-400">
                        {intro}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}

/** Grid lines and a brand glow behind the dark band. */
export function HeroBackdrop() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 -z-10 overflow-hidden"
        >
            <div className="absolute inset-0 bg-[linear-gradient(to_right,rgb(255_255_255/0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.05)_1px,transparent_1px)] mask-[radial-gradient(ellipse_70%_60%_at_50%_0%,black,transparent)] bg-size-[48px_48px]" />
            <div className="absolute -top-48 left-1/2 h-112 w-4xl -translate-x-1/2 rounded-full bg-primary/30 blur-[120px]" />
            <div className="absolute inset-x-0 bottom-0 h-px bg-linear-to-r from-transparent via-white/15 to-transparent" />
        </div>
    );
}
