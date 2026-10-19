import { create as createOrganization } from '@/routes/organizations';

/**
 * Pill buttons shared by links across the public site. Links, not
 * <Button>, because most of them navigate (some to another host).
 *
 * The site alternates deep navy bands (hero, figures, footer, inside a
 * `site-dark` element) with white and cool-grey sections; the accent is
 * orange. See the site-* colours in resources/css/app.css for which
 * orange goes where.
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-full px-7 py-3.5 text-sm font-bold tracking-wide transition-[color,background-color,border-color,box-shadow,transform] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98]';

export const button = {
    /** Main action: the orange gradient, on light or dark. */
    primary: `${base} bg-linear-to-r from-[#cf440c] to-[#b93a0b] text-white shadow-lg shadow-site-accent/25 hover:-translate-y-0.5 hover:from-[#b93a0b] hover:to-[#a3320a] hover:shadow-xl hover:shadow-site-accent/30 focus-visible:outline-site-accent`,
    /** Strong secondary action on light. */
    dark: `${base} bg-site-navy text-white hover:bg-site-night focus-visible:outline-site-navy`,
    /** Main action on an orange band. */
    light: `${base} bg-white text-site-ink shadow-sm hover:bg-white/90 focus-visible:outline-white`,
    /** Secondary action on navy or orange. */
    outlineDark: `${base} border border-white/35 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white`,
    /** Secondary action on white or grey. */
    outline: `${base} border border-site-line bg-white text-site-ink hover:border-site-accent/50 hover:text-site-accent-ink focus-visible:outline-site-accent`,
};

export const textLink =
    'font-semibold text-site-accent-ink underline-offset-4 hover:underline in-[.site-dark]:text-site-accent-glow';

/**
 * Section label above a heading: orange capitals after a short rule, as
 * industrial sites mark their sections.
 */
export const eyebrow =
    'inline-flex items-center gap-3 text-xs font-bold tracking-[0.18em] text-site-accent-ink uppercase before:h-0.5 before:w-8 before:rounded-full before:bg-site-accent in-[.site-dark]:text-site-accent-glow';

/** Heading type; size is set where it is used. */
export const display =
    'font-semibold tracking-tight text-balance text-site-ink in-[.site-dark]:text-white';

/**
 * Interactive surface (product/plan cards, FAQ rows that open): lifts on
 * hover, with an orange edge, so the affordance matches the content.
 */
export const card =
    'rounded-2xl border border-site-line bg-white shadow-sm shadow-site-navy/5 transition-[border-color,box-shadow,transform] duration-300 hover:-translate-y-1 hover:border-site-accent/40 hover:shadow-xl hover:shadow-site-navy/10';

/** Static surface (feature tiles, steps): same chrome, no lift. */
export const panel =
    'rounded-2xl border border-site-line bg-white shadow-sm shadow-site-navy/5';

/** Soft orange square behind an icon. */
export const iconChip =
    'inline-flex shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink';

/** A navy band: headings, labels and links inside switch to their light forms. */
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
