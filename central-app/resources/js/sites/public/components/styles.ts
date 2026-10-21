import { create as createOrganization } from '@/routes/organizations';

/**
 * Pill buttons shared by links across the public site. Links, not
 * <Button>, because most of them navigate (some to another host).
 *
 * Light-first surfaces with a blue → violet gradient for the main action
 * and highlights; deep indigo for the footer and closing bands (see the
 * site-* colours and .site-gradient in resources/css/app.css).
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-full px-7 py-3.5 text-sm font-bold tracking-wide transition-[color,background-color,border-color,box-shadow,transform] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98]';

export const button = {
    /** Main action: the gradient, lifted by a soft coloured shadow. */
    primary: `${base} site-gradient text-white shadow-lg shadow-site-accent/25 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-site-violet/30 focus-visible:outline-site-accent`,
    /** Strong secondary action on light. */
    dark: `${base} bg-site-navy text-white hover:bg-site-night focus-visible:outline-site-navy`,
    /** Main action on a dark or gradient band. */
    light: `${base} bg-white text-site-ink shadow-lg shadow-site-night/20 hover:-translate-y-0.5 focus-visible:outline-white`,
    /** Secondary action on a dark or gradient band. */
    outlineDark: `${base} border border-white/40 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white`,
    /** Secondary action on white or lavender. */
    outline: `${base} border border-site-line bg-white text-site-ink hover:border-site-accent/50 hover:text-site-accent-ink focus-visible:outline-site-accent`,
};

export const textLink =
    'font-semibold text-site-accent-ink underline-offset-4 hover:underline in-[.site-dark]:text-white';

/**
 * Section label above a heading: a soft pill with a gradient dot.
 */
export const eyebrow =
    'inline-flex items-center gap-2 rounded-full bg-site-accent/10 px-4 py-1.5 text-xs font-bold tracking-[0.14em] text-site-accent-ink uppercase before:size-1.5 before:rounded-full before:bg-site-accent in-[.site-dark]:bg-white/10 in-[.site-dark]:text-white in-[.site-dark]:before:bg-site-accent-glow';

/** Heading type; size is set where it is used. */
export const display =
    'font-semibold tracking-tight text-balance text-site-ink in-[.site-dark]:text-white';

/**
 * Interactive surface (product/plan cards, FAQ rows that open): rounded,
 * soft shadow, lifts a little on hover.
 */
export const card =
    'rounded-3xl border border-site-line bg-white shadow-[0_10px_40px_-18px_rgba(22,24,55,0.18)] transition-[border-color,box-shadow,transform] duration-300 hover:-translate-y-1 hover:border-site-accent/30 hover:shadow-[0_24px_60px_-20px_rgba(37,117,252,0.35)]';

/** Static surface (feature tiles, steps): same chrome, no hover. */
export const panel =
    'rounded-3xl border border-site-line bg-white shadow-[0_10px_40px_-18px_rgba(22,24,55,0.18)]';

/** Gradient square behind a white icon. */
export const iconChip =
    'site-gradient inline-flex shrink-0 items-center justify-center rounded-2xl text-white shadow-md shadow-site-accent/25';

/** A deep indigo band: headings, labels and links inside switch to their light forms. */
export const darkBand =
    'site-dark relative isolate overflow-hidden bg-site-night text-slate-300';

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
 * Where "Start free trial" leads: creating a workspace on the app host,
 * with the application preselected. Signing in or registering happens on
 * the way if needed, and brings the visitor back here.
 */
export function trialUrl(code?: string): string {
    return createOrganization.url(code ? { query: { app: code } } : undefined);
}
