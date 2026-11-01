import { trial } from '@/routes';

/**
 * Buttons shared by links across the public site. Links, not <Button>,
 * because most of them navigate (some to another host).
 *
 * Clean and light, in the logo's colours only (no gradients): white and
 * soft-blue surfaces, the logo orange for the main action, the logo blue
 * for links and icons, deep navy for the closing band (see the site-* colours in
 * resources/css/app.css). Squarer corners than pills, so they line up with
 * the rest of the layout.
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold transition-[color,background-color,border-color,box-shadow] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2';

export const button = {
    /** Main action: solid orange (the logo orange, darkened to 4.9:1 for white text). */
    primary: `${base} bg-site-orange-ink text-white shadow-sm shadow-site-orange-ink/25 hover:bg-site-orange-deep focus-visible:outline-site-orange`,
    /** Strong secondary action on light. */
    dark: `${base} bg-site-navy text-white hover:bg-site-night focus-visible:outline-site-navy`,
    /** Main action on a dark band. */
    light: `${base} bg-site-orange-ink text-white hover:bg-site-orange-deep focus-visible:outline-white`,
    /** Secondary action on a dark or gradient band. */
    outlineDark: `${base} border border-white/40 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white`,
    /** Secondary action on white or lavender. */
    outline: `${base} border border-site-line bg-white text-site-ink hover:border-site-orange/50 hover:text-site-orange-ink focus-visible:outline-site-orange`,
};

export const textLink =
    'font-semibold text-site-accent-ink underline-offset-4 hover:underline in-[.site-dark]:text-white';

/** Heading type; size is set where it is used. */
export const display =
    'font-semibold tracking-tight text-balance text-site-ink in-[.site-dark]:text-white';

/**
 * Interactive surface (product/plan cards, FAQ rows that open): a flat
 * hairline box whose border and shadow come up on hover.
 */
export const card =
    'rounded-2xl border border-site-line bg-white transition-[border-color,box-shadow] duration-200 hover:border-site-accent/40 hover:shadow-lg hover:shadow-site-night/5';

/** Static surface (feature tiles, steps): same chrome, no hover. */
export const panel = 'rounded-2xl border border-site-line bg-white';

/** The page width and gutter every section uses. */
export const container = 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8';

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

/** Solid blue square behind a white icon. */
export const iconChip =
    'bg-site-accent-ink inline-flex shrink-0 items-center justify-center rounded-xl text-white';

/** A solid navy band: headings, labels and links inside switch to their light forms. */
export const darkBand =
    'site-dark relative isolate overflow-hidden bg-site-navy text-slate-300';

/**
 * Colour pairs for icon boxes, taken in turn by a row of features: the
 * logo blue and the logo orange, each on its own tint (a navy tint read
 * as grey); the icon keeps 3:1 on it.
 */
export const tints = [
    'bg-site-accent/10 text-site-accent-ink',
    'bg-site-orange-wash text-site-orange-ink',
] as const;

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
