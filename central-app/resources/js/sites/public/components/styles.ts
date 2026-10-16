import { create as createOrganization } from '@/routes/organizations';

/**
 * Pill buttons shared by links across the public site. Links, not
 * <Button>, because most of them navigate (some to another host).
 *
 * The site is always light: white and cool-grey sections, a pale blue
 * wash behind the hero, near-black ink and the logo's brand blue.
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2';

export const button = {
    /** Main action. */
    primary: `${base} bg-brand-blue text-white shadow-sm shadow-brand-blue/30 hover:bg-brand-blue-dark focus-visible:outline-brand-blue`,
    /** Strong secondary action. */
    dark: `${base} bg-site-ink text-white hover:bg-site-ink/85 focus-visible:outline-site-ink`,
    /** Main action on the blue call-to-action card. */
    light: `${base} bg-white text-site-ink hover:bg-white/90 focus-visible:outline-white`,
    /** Secondary action on the blue call-to-action card. */
    outlineDark: `${base} border border-white/40 text-white hover:bg-white/10 focus-visible:outline-white`,
    /** Secondary action on white or grey. */
    outline: `${base} border border-site-line bg-white text-site-ink hover:border-brand-blue/40 hover:text-brand-blue focus-visible:outline-brand-blue`,
};

export const textLink =
    'font-semibold text-brand-blue underline-offset-4 hover:underline';

/** Small white pill above a heading. */
export const eyebrow =
    'inline-flex items-center gap-2 rounded-full border border-site-line bg-white px-3.5 py-1.5 text-sm font-medium text-site-ink shadow-xs';

/** Heading type; size is set where it is used. */
export const display = 'font-bold tracking-tight text-balance text-site-ink';

/** Rounded white card with a hairline border, lifting on hover. */
export const card =
    'rounded-2xl border border-site-line bg-white shadow-xs transition-shadow hover:shadow-lg hover:shadow-site-ink/5';

/** Soft blue square behind an icon. */
export const iconChip =
    'inline-flex shrink-0 items-center justify-center rounded-xl bg-brand-blue/10 text-brand-blue';

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
