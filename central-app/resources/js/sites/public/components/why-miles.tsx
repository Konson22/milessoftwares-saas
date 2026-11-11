import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { whyMiles } from '@/sites/public/components/content';
import { Accent } from '@/sites/public/components/page-hero';
import { button, container, display } from '@/sites/public/components/styles';

type Reason = (typeof whyMiles)[number];

/**
 * Why Tatook, on the home page: the four reasons around a
 * picture of the Tatook workspace on every screen, with dashed connectors
 * from each card to it. On small screens the picture sits between the
 * cards. Pale blue band, white
 * cards, the logo blue for icons and accents; no navy backgrounds.
 * Wording comes from whyMiles in content.ts (same order).
 */
export default function WhyMiles() {
    const left = whyMiles.slice(0, 2);
    const right = whyMiles.slice(2);

    return (
        <section
            id="why-tatook"
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
                        Why <Accent>Tatook</Accent>
                    </h2>
                    <p className="mt-5 text-base leading-relaxed text-slate-600 sm:text-lg">
                        Software made for the trade you run, on the screens you
                        use, in one workspace, with people who set it up and
                        stay to support it.
                    </p>
                </div>

                <div className="mt-14 grid gap-5 lg:mt-20 lg:grid-cols-[1fr_auto_1fr] lg:items-center lg:gap-6">
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
            ? 'lg:after:-right-6 lg:after:left-auto'
            : 'lg:after:-left-6 lg:after:right-auto';

    return (
        <li
            className={`group relative rounded-xl bg-white p-6 transition-transform duration-300 hover:-translate-y-1 sm:p-7 lg:after:absolute lg:after:top-1/2 lg:after:w-6 lg:after:border-t-2 lg:after:border-dashed lg:after:border-site-accent/40 lg:after:content-[''] ${connector}`}
        >
            <div className="flex items-center gap-4">
                <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-site-accent text-white transition-transform duration-300 group-hover:scale-105">
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

/**
 * The Tatook workspace on a laptop, tablet and phone with the trades around
 * it. Its white background multiplies into the band and the edges fade, so
 * it sits on the section with no frame.
 */
function Hub() {
    return (
        <figure className="mx-auto w-full max-w-md lg:w-[24rem] lg:max-w-none xl:w-[32rem]">
            <img
                src="/images/home/tatook-ecosystem.webp"
                srcSet="/images/home/tatook-ecosystem-700.webp 700w, /images/home/tatook-ecosystem.webp 1190w"
                sizes="(min-width: 1280px) 32rem, (min-width: 1024px) 24rem, 28rem"
                alt="Tatook on a laptop, tablet and phone, joined to shop, team, device and cloud icons"
                width={1190}
                height={800}
                loading="lazy"
                decoding="async"
                className="w-full mix-blend-multiply [mask-composite:intersect] [mask-image:linear-gradient(to_right,transparent,black_5%,black_95%,transparent),linear-gradient(transparent,black_5%,black_95%,transparent)]"
            />
        </figure>
    );
}
