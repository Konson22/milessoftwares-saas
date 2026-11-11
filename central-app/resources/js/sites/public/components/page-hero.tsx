import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { container, display, tints } from '@/sites/public/components/styles';

/**
 * The short band every page but home opens with: white under the
 * header with a faint dot grid, a hairline below, and a left-aligned
 * title with one line of intro. The top padding clears the fixed header
 * (h-16) with a modest gap, so the page content starts high. `above` is a
 * small line over the title (a back link or a step); `children`, at most
 * one or two actions, sit beside the copy from `lg` rather than under it.
 */
export default function PageHero({
    above,
    title,
    intro,
    children,
}: {
    above?: ReactNode;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <section className="relative isolate overflow-hidden border-b border-site-line bg-site-soft">
            <GridBackdrop />
            <div
                className={`site-rise ${container} flex flex-col gap-x-12 gap-y-6 pt-24 pb-10 sm:pt-28 sm:pb-14 lg:flex-row lg:items-end lg:justify-between`}
            >
                <div className="min-w-0">
                    {above && <div className="mb-4">{above}</div>}
                    <SpeedMark className="mb-4" />
                    <h1
                        className={`max-w-4xl text-[2rem] leading-[1.1] sm:text-[2.75rem] lg:text-5xl ${display}`}
                    >
                        {title}
                    </h1>
                    {intro && (
                        <p className="mt-4 max-w-2xl text-base leading-relaxed text-pretty text-slate-600 sm:text-lg">
                            {intro}
                        </p>
                    )}
                </div>
                {children && (
                    <div className="flex shrink-0 flex-wrap items-center gap-3">
                        {children}
                    </div>
                )}
            </div>
        </section>
    );
}

/**
 * The dark green opening band of the main pages: the title, the intro and
 * up to a few actions, with `aside` beside them from `lg` (a picture, or
 * links). `asideClassName` decides where the aside shows; by default only
 * from `lg`, as pictures are decorative.
 */
export function DarkHero({
    title,
    intro,
    children,
    aside,
    asideClassName = 'hidden lg:block',
}: {
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    aside?: ReactNode;
    asideClassName?: string;
}) {
    return (
        <section className="site-dark relative isolate overflow-hidden bg-[radial-gradient(circle_at_78%_40%,#11512b,#063519_55%,#032a16_100%)]">
            <HeroBackdrop />
            <div
                className={`${container} grid items-center gap-10 pt-24 pb-12 sm:pt-28 sm:pb-14 ${aside ? 'lg:grid-cols-[1.05fr_1fr]' : ''}`}
            >
                <div className="site-rise min-w-0">
                    <SpeedMark className="mb-5" />
                    <h1
                        className={`max-w-2xl text-[2.25rem] leading-[1.08] sm:text-5xl lg:text-[3.25rem] ${display}`}
                    >
                        {title}
                    </h1>
                    {intro && (
                        <p className="mt-5 max-w-xl text-base leading-relaxed text-pretty text-slate-200 sm:text-lg">
                            {intro}
                        </p>
                    )}
                    {children && (
                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            {children}
                        </div>
                    )}
                </div>
                {aside && <div className={asideClassName}>{aside}</div>}
            </div>
        </section>
    );
}

/**
 * A faint dot grid that fades out towards the copy, behind a light hero.
 * Decorative only.
 */
export function GridBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="site-dots absolute inset-y-0 right-0 w-full [mask-image:linear-gradient(to_left,black,transparent_75%)] text-site-accent/15 lg:w-2/3" />
        </div>
    );
}

/**
 * A ring, two dots and a dotted patch behind a light section, in the
 * logo's blues and navy. Decorative only.
 */
export function LightBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="absolute top-16 right-[7%] hidden size-28 rounded-full border-2 border-dashed border-site-highlight/30 md:block" />
            <div className="site-float absolute top-[22%] left-[6%] hidden size-4 rounded-full bg-site-highlight/70 md:block" />
            <div className="site-float-slow absolute right-[16%] bottom-[18%] hidden size-3 rounded-full bg-site-navy/40 md:block" />
            <div className="site-dots absolute bottom-6 left-[12%] hidden h-24 w-40 text-site-accent/20 md:block" />
        </div>
    );
}

/**
 * Behind a dark band: a constellation of points joined by faint lines
 * and a ring. Decorative only.
 */
export function HeroBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <Constellation />
            <div className="absolute top-28 left-[6%] hidden size-40 rounded-full border border-site-highlight/25 md:block" />
            <div className="site-dots absolute bottom-16 left-[10%] h-24 w-40 text-white/15" />
        </div>
    );
}

/** Points of the constellation, in a 1200 × 600 box. */
const stars: [number, number][] = [
    [60, 80],
    [180, 40],
    [250, 150],
    [120, 230],
    [330, 260],
    [420, 110],
    [520, 60],
    [600, 190],
    [700, 90],
    [780, 220],
    [880, 70],
    [960, 170],
    [1060, 60],
    [1140, 160],
    [1100, 300],
    [980, 330],
    [860, 380],
    [720, 340],
    [580, 420],
    [430, 380],
    [300, 450],
    [160, 400],
    [60, 500],
    [240, 560],
    [500, 540],
    [760, 500],
    [940, 520],
    [1120, 470],
];

/** Pairs of points close enough to join. */
const links = stars.flatMap(([x1, y1], i) =>
    stars
        .slice(i + 1)
        .filter(([x2, y2]) => Math.hypot(x2 - x1, y2 - y1) < 175)
        .map(([x2, y2]) => [x1, y1, x2, y2] as const),
);

function Constellation() {
    return (
        <svg
            viewBox="0 0 1200 600"
            preserveAspectRatio="xMidYMid slice"
            className="absolute inset-0 size-full text-white"
        >
            <g stroke="currentColor" strokeOpacity="0.09" strokeWidth="1">
                {links.map(([x1, y1, x2, y2]) => (
                    <line
                        key={`${x1}-${y1}-${x2}-${y2}`}
                        x1={x1}
                        y1={y1}
                        x2={x2}
                        y2={y2}
                    />
                ))}
            </g>
            {stars.map(([x, y], i) => (
                <circle
                    key={`${x}-${y}`}
                    cx={x}
                    cy={y}
                    r={i % 4 === 0 ? 2.6 : 1.6}
                    fill="currentColor"
                    className="site-twinkle"
                    style={{
                        opacity: 0.5,
                        animationDelay: `${(i % 7) * 0.6}s`,
                    }}
                />
            ))}
        </svg>
    );
}

/**
 * The highlighted phrase of a heading, in the logo cyan: its darker form
 * on light (5.5:1), a lighter cyan on a charcoal band (4.6:1).
 */
export function Accent({ children }: { children: ReactNode }) {
    return (
        <span className="text-site-accent-ink in-[.site-dark]:text-site-highlight">
            {children}
        </span>
    );
}

/**
 * An icon on a soft tinted square, coloured by its place in a set (see
 * tints).
 */
export function IconBox({
    icon: Icon,
    index = 0,
}: {
    icon: LucideIcon;
    index?: number;
}) {
    const tint = tints[index % tints.length];

    return (
        <span
            className={`inline-flex size-11 shrink-0 items-center justify-center rounded-xl ${tint}`}
        >
            <Icon className="size-5" aria-hidden />
        </span>
    );
}

/**
 * The logo's two shapes, as a small rule over a heading: a charcoal dash
 * and two cyans. Decorative only.
 */
export function SpeedMark({ className = '' }: { className?: string }) {
    return (
        <span aria-hidden className={`flex items-center gap-1.5 ${className}`}>
            <span className="h-1.5 w-3 rounded-full bg-site-navy in-[.site-dark]:bg-white/40" />
            <span className="h-1.5 w-6 rounded-full bg-site-accent-ink in-[.site-dark]:bg-site-highlight" />
            <span className="h-1.5 w-10 rounded-full bg-site-accent" />
        </span>
    );
}
