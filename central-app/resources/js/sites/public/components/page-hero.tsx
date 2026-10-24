import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { darkBand, display, tints } from '@/sites/public/components/styles';

/**
 * The band public pages open with: deep indigo → violet under the
 * header, a faint constellation, centred title and intro, ending on
 * a straight edge. The top padding clears the fixed header.
 */
export default function PageHero({
    above,
    title,
    intro,
    children,
    padding = 'pt-32 pb-20 sm:pt-40 sm:pb-24',
}: {
    above?: ReactNode;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    padding?: string;
}) {
    return (
        <section className={darkBand}>
            <HeroBackdrop />
            <div
                className={`site-rise mx-auto flex max-w-7xl flex-col items-center px-4 text-center sm:px-6 ${padding}`}
            >
                {above}
                <h1
                    className={`mt-0 max-w-4xl text-[1.75rem] leading-[1.15] sm:text-6xl ${display}`}
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
 * Soft gradient blobs, rings and a dotted patch behind a light section.
 * Decorative only.
 */
export function LightBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <div className="site-float-slow absolute -top-32 -left-24 size-[28rem] rounded-full bg-site-accent/10 blur-3xl" />
            <div className="site-float absolute -right-24 -bottom-40 size-[30rem] rounded-full bg-site-sky/10 blur-3xl" />
            <div className="absolute top-16 right-[7%] hidden size-28 rounded-full border-2 border-dashed border-site-accent/25 md:block" />
            <div className="site-float absolute top-[22%] left-[6%] hidden size-4 rounded-full bg-site-aqua/70 md:block" />
            <div className="site-float-slow absolute right-[16%] bottom-[18%] hidden size-3 rounded-full bg-site-sky/70 md:block" />
            <div className="site-dots absolute bottom-6 left-[12%] hidden h-24 w-40 text-site-accent/20 md:block" />
        </div>
    );
}

/**
 * Behind a dark band: a constellation of points joined by faint lines,
 * a slanted slab and a ring. Decorative only.
 */
export function HeroBackdrop() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
            <Constellation />
            <div className="site-skew absolute -top-40 right-[-12rem] h-[34rem] w-[38rem] rounded-[4rem] bg-linear-to-br from-white/[0.07] to-transparent" />
            <div className="absolute top-28 left-[6%] hidden size-40 rounded-full border border-white/10 md:block" />
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

/** The highlighted phrase of a heading: the blue → violet gradient. */
export function Accent({ children }: { children: ReactNode }) {
    return <span className="site-gradient-text">{children}</span>;
}

/**
 * An icon on a soft tinted square with a slanted twin behind it, coloured
 * by its place in a set (see tints).
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
        <span className="relative inline-flex size-16 shrink-0 items-center justify-center">
            <span
                aria-hidden
                className={`absolute inset-0 rotate-12 rounded-2xl opacity-60 transition-transform duration-300 group-hover:rotate-45 ${tint}`}
            />
            <span
                className={`relative inline-flex size-14 items-center justify-center rounded-2xl ${tint}`}
            >
                <Icon className="size-6" aria-hidden />
            </span>
        </span>
    );
}
