import { getStarted, trial } from '@/routes';

/**
 * Buttons shared by links across the public site. Links, not <Button>,
 * because most of them navigate (some to another host).
 *
 * One height (44px, a full touch target), one radius and one weight for
 * every variant; only the colours change. Green is the main action on
 * white, lime (with dark text) the main action on the dark green hero and
 * bands, and white with a hairline the secondary one. Focus rings come
 * from the site-wide :focus-visible rule in app.css. `aria-disabled` dims
 * a link that cannot be followed yet, without moving the layout.
 */
const base =
    'inline-flex h-11 items-center justify-center gap-2 rounded-lg px-5 text-sm font-semibold whitespace-nowrap transition-colors duration-150 aria-disabled:pointer-events-none aria-disabled:bg-slate-200 aria-disabled:text-slate-500 disabled:pointer-events-none disabled:bg-slate-200 disabled:text-slate-500';

export const button = {
    /** Main action on white: dark blue, white text (6.9:1). */
    primary: `${base} bg-site-accent text-white shadow-sm hover:bg-site-accent-hover`,
    /** Emphasised alternative on white (Request a demo). */
    green: `${base} bg-site-green text-white shadow-sm hover:bg-site-green-hover`,
    /** Strong secondary action on light. */
    dark: `${base} bg-site-navy text-white hover:bg-site-night`,
    /** Main action on the navy hero or a band: bright blue, white text (5:1). */
    light: `${base} bg-site-lime text-white hover:bg-site-lime-hover`,
    /** Secondary action on navy: a white outline. */
    outlineDark: `${base} border border-white text-white hover:bg-site-navy-light`,
    /** Secondary action on white or silver: a blue outline. */
    outline: `${base} border border-site-accent bg-white text-site-accent hover:bg-site-tint`,
};

export const textLink =
    'font-semibold text-site-accent underline-offset-4 hover:text-site-accent-hover hover:underline in-[.site-dark]:text-white';

/** Heading type; size is set where it is used (see `type`). */
export const display =
    'font-semibold tracking-tight text-balance text-site-ink in-[.site-dark]:text-white';

/**
 * The type scale. Hero and section headings grow with the screen but stay
 * modest on phones; body copy is 16–18px with a relaxed line height.
 */
export const type = {
    hero: 'text-[2rem] leading-[1.1] sm:text-[2.5rem] lg:text-[3.25rem]',
    section: 'text-[1.625rem] leading-tight sm:text-3xl lg:text-[2.25rem]',
    subheading: 'text-lg leading-snug sm:text-xl',
    lead: 'text-base leading-relaxed text-pretty text-site-muted sm:text-lg in-[.site-dark]:text-slate-300',
    body: 'text-base leading-relaxed text-site-body',
    caption: 'text-sm leading-normal text-site-muted',
};

/**
 * Interactive surface (product/plan cards, FAQ rows that open): a hairline
 * box with a faint shadow whose border turns blue on hover.
 */
export const card =
    'rounded-xl border border-site-line bg-white shadow-xs transition-[border-color,box-shadow] duration-200 hover:border-site-accent hover:shadow-md';

/**
 * The compact product card: a navy surface with a blue icon, a white name,
 * a silver gray line and a light blue link. It lifts and takes a blue edge
 * on hover or keyboard focus. Carries `site-dark`, so status pills and the
 * focus ring inside switch to their forms for dark backgrounds.
 */
export const darkCard =
    'site-dark group flex h-full flex-col rounded-xl bg-site-navy p-6 shadow-[0_1px_2px_rgb(8_15_31/0.12),0_12px_28px_-14px_rgb(8_15_31/0.5)] ring-1 ring-site-navy-line transition-[box-shadow,transform,--tw-ring-color] duration-200 hover:-translate-y-0.5 hover:ring-site-leaf focus-visible:ring-site-leaf';

/** The blue icon square on a navy card. */
export const darkIcon =
    'inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-site-navy-light text-site-leaf ring-1 ring-site-navy-line transition-colors group-hover:bg-site-accent group-hover:text-white';

/** Static surface (feature tiles, steps): same chrome, no hover. */
export const panel = 'rounded-xl border border-site-line bg-white shadow-xs';

/** The page width and gutter every section uses. */
export const container = 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8';

/** Vertical rhythm of a page section. */
export const section = 'py-16 sm:py-20 lg:py-24';

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

/** Pale blue square behind a blue icon. */
export const iconChip =
    'bg-site-tint text-site-accent inline-flex shrink-0 items-center justify-center rounded-lg in-[.site-dark]:bg-site-navy-light in-[.site-dark]:text-white';

/** A solid navy band: headings, labels and links inside switch to their light forms. */
export const darkBand =
    'site-dark relative isolate overflow-hidden bg-site-navy text-slate-300';

/**
 * Colour pairs for icon boxes, taken in turn by a row of features: dark
 * blue on pale blue, then electric blue on the same, so a row is not all
 * one shade. Icons keep 3:1 on their box.
 */
export const tints = [
    'bg-site-tint text-site-accent in-[.site-dark]:bg-site-navy-light in-[.site-dark]:text-white',
    'bg-site-leaf-tint text-site-green in-[.site-dark]:bg-site-navy-light in-[.site-dark]:text-white',
] as const;

/** A small status label (Early access, Coming soon). */
export const badge =
    'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold';

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
 * register first (sign-in is a link away) and brought back to it; then they choose the
 * organization it is for, or create one. An ERP product (NGO) is started as itself.
 */
export function trialUrl(code?: string, product?: string | null): string {
    if (product) {
        return trial.url({ query: { product } });
    }

    return trial.url(code ? { query: { app: code } } : undefined);
}

/**
 * A plan's own call to action: its free trial, or asking for it (paid plans
 * are switched on by Miles Global Softwares once paid). Both keep the plan through signing
 * up and choosing the organization.
 */
export function planUrl(code: string, action: 'trial' | 'subscribe'): string {
    return getStarted.url({
        query: action === 'trial' ? { plan: code } : { plan: code, action },
    });
}
