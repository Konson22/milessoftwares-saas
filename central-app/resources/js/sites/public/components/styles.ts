import { trial } from '@/routes';

/**
 * Pill buttons shared by links across the public site. Links, not
 * <Button>, because most of them navigate (some to another host).
 *
 * In the logo's colours only, all solid (no gradients): white surfaces, the
 * logo blue for the main action and highlights, deep navy for the footer
 * and closing bands (see the site-* colours in resources/css/app.css).
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-full px-7 py-3.5 text-sm font-bold tracking-wide transition-[color,background-color,border-color,transform] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98]';

export const button = {
    /** Main action: solid blue. */
    primary: `${base} bg-site-accent-ink text-white hover:-translate-y-0.5 hover:bg-site-navy focus-visible:outline-site-accent`,
    /** Strong secondary action on light. */
    dark: `${base} bg-site-navy text-white hover:bg-site-night focus-visible:outline-site-navy`,
    /** Main action on a dark or gradient band. */
    light: `${base} bg-white text-site-ink hover:-translate-y-0.5 focus-visible:outline-white`,
    /** Secondary action on a dark or gradient band. */
    outlineDark: `${base} border border-white/40 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white`,
    /** Secondary action on white or lavender. */
    outline: `${base} border border-site-line bg-white text-site-ink hover:border-site-accent/50 hover:text-site-accent-ink focus-visible:outline-site-accent`,
};

export const textLink =
    'font-semibold text-site-accent-ink underline-offset-4 hover:underline in-[.site-dark]:text-white';

/** Heading type; size is set where it is used. */
export const display =
    'font-semibold tracking-tight text-balance text-site-ink in-[.site-dark]:text-white';

/**
 * Interactive surface (product/plan cards, FAQ rows that open): rounded,
 * flat, lifts a little on hover.
 */
export const card =
    'rounded-3xl border border-site-line bg-white transition-[border-color,transform] duration-300 hover:-translate-y-1 hover:border-site-accent/30';

/** Static surface (feature tiles, steps): same chrome, no hover. */
export const panel = 'rounded-3xl border border-site-line bg-white';

/**
 * A row of cards that swipes sideways on small screens (the next card
 * peeks in) and becomes a grid from `lg`, so long lists do not stack
 * into a tall column. Bleeds to the screen edge inside the page gutter.
 * Pair with railItem on each card.
 */
export const rail =
    'site-rail -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pt-2 pb-6 sm:-mx-6 sm:scroll-px-6 sm:gap-6 sm:px-6 lg:mx-0 lg:grid lg:grid-cols-3 lg:overflow-visible lg:px-0 lg:pt-0 lg:pb-0';

export const railItem =
    'w-[82%] max-w-sm shrink-0 snap-start sm:w-[calc(50%-0.75rem)] sm:max-w-none lg:w-auto';

/** Gradient square behind a white icon. */
export const iconChip =
    'bg-site-accent-ink inline-flex shrink-0 items-center justify-center rounded-2xl text-white';

/** A solid navy band: headings, labels and links inside switch to their light forms. */
export const darkBand =
    'site-dark relative isolate overflow-hidden bg-site-navy text-slate-300';

/**
 * Colour pairs for icon boxes, taken in turn by a row of features. One
 * for now, the logo blue on its own tint (a navy tint read as grey);
 * the icon keeps 3:1 on it.
 */
export const tints = ['bg-site-accent/10 text-site-accent-ink'] as const;

/** "2-month" for 60 days, "14-day" otherwise. */
export function trialLength(days: number): string {
    return days >= 30 && days % 30 === 0 ? `${days / 30}-month` : `${days}-day`;
}

/** "2 months" for 60 days, "14 days" otherwise. */
export function trialDuration(days: number): string {
    return days >= 30 && days % 30 === 0
        ? `${days / 30} month${days === 30 ? '' : 's'}`
        : `${days} days`;
}

/**
 * Where "Start free trial" leads: the trial page on the app host, with the
 * application preselected. A visitor who is not signed in is sent to
 * register first (sign-in is a link away) and brought back; someone without a business is
 * sent on to create one with the same trial chosen.
 */
export function trialUrl(code?: string): string {
    return trial.url(code ? { query: { app: code } } : undefined);
}
