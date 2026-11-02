import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Globe, Monitor, Smartphone } from 'lucide-react';
import { whyMiles } from '@/sites/public/components/content';
import { Accent } from '@/sites/public/components/page-hero';
import { button, container, display } from '@/sites/public/components/styles';

type Reason = (typeof whyMiles)[number];

/**
 * Why Miles Global Softwares, on the home page: the four reasons around a
 * hub, the Miles mark in concentric circles, with dashed connectors from
 * each card to it (one workspace, everything joined to it). On small
 * screens the hub is hidden and the cards stack. Pale blue band, white
 * cards, the logo blue for icons and accents; no navy backgrounds.
 * Wording comes from whyMiles in content.ts (same order).
 */
export default function WhyMiles() {
    const left = whyMiles.slice(0, 2);
    const right = whyMiles.slice(2);

    return (
        <section
            aria-labelledby="why-heading"
            className="relative isolate overflow-hidden bg-site-soft"
        >
            {/* Faint dot grid behind the hub. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(var(--color-site-accent)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_center,black,transparent_70%)] bg-size-[22px_22px] opacity-15"
            />
            <div className={`${container} py-20 sm:py-28`}>
                <div className="mx-auto max-w-3xl text-center">
                    <h2
                        id="why-heading"
                        className={`${display} text-3xl sm:text-4xl lg:text-5xl`}
                    >
                        Why <Accent>Miles Global Softwares</Accent>
                    </h2>
                    <p className="mt-5 text-base leading-relaxed text-slate-600 sm:text-lg">
                        Software made for the trade you run, on the screens you
                        use, in one workspace, with people who set it up and
                        stay to support it.
                    </p>
                </div>

                <div className="mt-14 grid gap-5 lg:mt-20 lg:grid-cols-[1fr_auto_1fr] lg:items-center lg:gap-14">
                    <ul className="grid gap-5">
                        {left.map((item, i) => (
                            <ReasonCard
                                key={item.title}
                                item={item}
                                number={i + 1}
                                side="left"
                            />
                        ))}
                    </ul>

                    <Hub />

                    <ul className="grid gap-5">
                        {right.map((item, i) => (
                            <ReasonCard
                                key={item.title}
                                item={item}
                                number={i + 3}
                                side="right"
                            />
                        ))}
                    </ul>
                </div>

                <div className="mt-14 flex justify-center">
                    <Link href="/about" className={button.outline}>
                        About us
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                </div>
            </div>
        </section>
    );
}

function ReasonCard({
    item,
    number,
    side,
}: {
    item: Reason;
    number: number;
    side: 'left' | 'right';
}) {
    const Icon = item.icon;
    // A dashed line from the card's inner edge towards the hub (wide screens only).
    const connector =
        side === 'left'
            ? 'lg:after:-right-14 lg:after:left-auto'
            : 'lg:after:-left-14 lg:after:right-auto';

    return (
        <li
            className={`group relative rounded-3xl bg-white p-6 shadow-sm ring-1 ring-site-line transition-[box-shadow,transform] duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-site-accent/10 sm:p-7 lg:after:absolute lg:after:top-1/2 lg:after:w-14 lg:after:border-t-2 lg:after:border-dashed lg:after:border-site-accent/40 lg:after:content-[''] ${connector}`}
        >
            <div className="flex items-center gap-4">
                <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-site-accent text-white shadow-md shadow-site-accent/30 transition-transform duration-300 group-hover:scale-105">
                    <Icon className="size-5" aria-hidden />
                </span>
                <h3 className="flex-1 text-lg font-semibold text-site-ink">
                    {item.title}
                </h3>
                <span
                    aria-hidden
                    className="font-public-display text-3xl font-semibold text-site-accent/20 tabular-nums"
                >
                    {String(number).padStart(2, '0')}
                </span>
            </div>
            <p className="mt-4 text-sm leading-relaxed text-slate-600">
                {item.body}
            </p>
            <ul className="mt-5 flex flex-wrap gap-2">
                {item.points.map((point) => (
                    <li
                        key={point}
                        className="inline-flex items-center gap-1.5 rounded-full bg-site-wash px-3 py-1.5 text-xs font-medium text-site-ink"
                    >
                        <Check
                            className="size-3.5 shrink-0 text-site-accent-ink"
                            strokeWidth={2.5}
                            aria-hidden
                        />
                        {point}
                    </li>
                ))}
            </ul>
        </li>
    );
}

/** The Miles mark in three circles, with the screens it runs on beneath. */
function Hub() {
    return (
        <div aria-hidden className="hidden flex-col items-center lg:flex">
            <div className="relative flex size-72 items-center justify-center rounded-full bg-site-accent/5 ring-1 ring-site-accent/15">
                <div className="absolute inset-0 animate-[spin_40s_linear_infinite] rounded-full border-2 border-dashed border-site-accent/20 motion-reduce:animate-none" />
                <div className="flex size-52 items-center justify-center rounded-full bg-site-accent/10 ring-1 ring-site-accent/20">
                    <div className="flex size-32 items-center justify-center rounded-full bg-white shadow-xl shadow-site-accent/20 ring-1 ring-site-line">
                        <img
                            src="/images/logo-icon.png"
                            alt=""
                            className="size-20 object-contain"
                        />
                    </div>
                </div>
            </div>
            <div className="mt-6 flex items-center gap-2">
                {[Globe, Monitor, Smartphone].map((Icon, i) => (
                    <span
                        key={i}
                        className="inline-flex size-9 items-center justify-center rounded-full bg-white text-site-accent shadow-sm ring-1 ring-site-line"
                    >
                        <Icon className="size-4" />
                    </span>
                ))}
            </div>
            <p className="mt-3 text-sm font-medium text-slate-600">
                Web, Windows and Android
            </p>
        </div>
    );
}
