import { useEffect, useState } from 'react';

const interval = 6000;

type Slide = { src: string; caption: string };

/**
 * Illustrations for the home hero (public/images/hero/web, transparent
 * WebP made from the PNGs beside it). Cross-fades from one to the next,
 * pauses while hovered, and stays on the first for visitors who prefer
 * reduced motion. The pictures are illustrative, so their figures are
 * hidden from assistive technology; the caption names what is shown.
 */
const slides: Slide[] = [
    {
        src: '/images/hero/web/ecosystem.webp',
        caption: 'One platform for every industry',
    },
    // Add the ERP and HR illustrations here once they are exported with
    // real transparency (the current PNGs have the checkerboard baked in).
];

export default function HeroSlides() {
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        const prefersReduced =
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (paused || slides.length < 2 || prefersReduced) {
            return;
        }

        const timer = window.setTimeout(
            () => setActive((i) => (i + 1) % slides.length),
            interval,
        );

        return () => window.clearTimeout(timer);
    }, [active, paused]);

    return (
        <figure
            className="relative mx-auto w-full max-w-2xl"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            <div aria-hidden className="relative aspect-[16/9]">
                {slides.map((slide, i) => (
                    <img
                        key={slide.src}
                        src={slide.src}
                        alt=""
                        width={1400}
                        height={787}
                        decoding="async"
                        fetchPriority={i === 0 ? 'high' : 'low'}
                        loading={i === 0 ? 'eager' : 'lazy'}
                        className={`absolute inset-0 size-full object-contain drop-shadow-2xl transition-[opacity,transform] duration-1000 ease-out ${i === active ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0'}`}
                    />
                ))}
            </div>

            <figcaption className="mt-4 flex items-center justify-center gap-4">
                <span
                    key={active}
                    aria-live="polite"
                    className="hero-caption text-sm font-semibold text-white/85"
                >
                    {slides[active].caption}
                </span>
                {slides.length > 1 && (
                    <span className="flex items-center gap-1.5">
                        {slides.map((slide, i) => (
                            <button
                                key={slide.src}
                                type="button"
                                aria-label={`Show slide ${i + 1}: ${slide.caption}`}
                                aria-current={i === active ? 'true' : undefined}
                                onClick={() => setActive(i)}
                                className={`relative h-1.5 overflow-hidden rounded-full bg-white/30 transition-all duration-500 hover:bg-white/60 ${i === active ? 'w-8' : 'w-1.5'}`}
                            >
                                {i === active && (
                                    <span
                                        key={paused ? 'paused' : 'running'}
                                        className={`hero-progress absolute inset-0 origin-left rounded-full bg-white ${paused ? 'is-paused' : ''}`}
                                        style={{
                                            animationDuration: `${interval}ms`,
                                        }}
                                    />
                                )}
                            </button>
                        ))}
                    </span>
                )}
            </figcaption>
        </figure>
    );
}
