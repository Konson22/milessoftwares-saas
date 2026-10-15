/**
 * Button looks shared by links across the public site. Links, not
 * <Button>, because most of them navigate (some to another host).
 */
const base =
    'inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2';

export const button = {
    primary: `${base} bg-primary text-primary-foreground shadow-lg shadow-primary/25 hover:bg-primary/90 focus-visible:outline-primary`,
    /** On the dark bands. */
    light: `${base} bg-white text-zinc-950 hover:bg-zinc-200 focus-visible:outline-white`,
    outlineDark: `${base} border border-white/15 bg-white/5 text-white hover:bg-white/10 focus-visible:outline-white`,
    outline: `${base} border bg-background hover:bg-accent focus-visible:outline-ring`,
};

export const textLink =
    'font-medium text-primary underline-offset-4 hover:underline';
