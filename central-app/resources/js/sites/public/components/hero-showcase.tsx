import { useEffect, useState } from 'react';
import { productIcon } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import { productPhoto } from '@/sites/public/components/product-art';
import { iconChip } from '@/sites/public/components/styles';

const interval = 5000;

/**
 * Photos of the businesses each application serves, for the home hero.
 * Cross-fades from one application to the next with a slow zoom, and a
 * caption naming the application. Pauses while hovered, and stays on the
 * first slide when the visitor prefers reduced motion. The dots let
 * visitors pick a slide.
 */
export default function HeroShowcase({ products }: { products: Product[] }) {
    const slides = products.slice(0, 6);
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
    }, [active, paused, slides.length]);

    const current = slides[active];
    const Icon = productIcon(current.code);

    return (
        <div
            className="relative overflow-hidden rounded-2xl border border-site-line bg-site-night"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            <div aria-hidden className="relative aspect-[4/3]">
                {slides.map((product, i) => (
                    <img
                        key={product.code}
                        src={productPhoto(product.code)}
                        alt=""
                        width={800}
                        height={400}
                        decoding="async"
                        loading={i === 0 ? 'eager' : 'lazy'}
                        className={`hero-slide absolute inset-0 size-full object-cover transition-opacity duration-1000 ease-in-out ${i === active ? 'is-active opacity-100' : 'opacity-0'}`}
                    />
                ))}
                {/* Navy wash so the caption reads on any photo. */}
                <span className="absolute inset-0 bg-linear-to-t from-site-night/85 via-site-night/10 to-transparent" />
            </div>

            <div className="absolute inset-x-0 bottom-0 flex items-end justify-between gap-4 p-5 sm:p-7">
                <div
                    key={current.code}
                    aria-live="polite"
                    className="hero-caption flex min-w-0 items-center gap-3 rounded-2xl bg-white/95 py-2.5 pr-5 pl-2.5 text-site-ink"
                >
                    <span className={`${iconChip} size-10 shrink-0 rounded-xl`}>
                        <Icon className="size-5" aria-hidden />
                    </span>
                    <span className="min-w-0">
                        <span className="block font-public-display font-semibold">
                            {current.name}
                        </span>
                        {current.tagline && (
                            <span className="block truncate text-xs text-slate-600">
                                {current.tagline}
                            </span>
                        )}
                    </span>
                </div>

                {slides.length > 1 && (
                    <div className="flex shrink-0 items-center gap-1.5">
                        {slides.map((product, i) => (
                            <button
                                key={product.code}
                                type="button"
                                aria-label={`Show ${product.name}`}
                                aria-current={i === active ? 'true' : undefined}
                                onClick={() => setActive(i)}
                                className={`relative h-1.5 overflow-hidden rounded-full bg-white/35 transition-all duration-500 hover:bg-white/60 ${i === active ? 'w-8' : 'w-1.5'}`}
                            >
                                {i === active && (
                                    <span
                                        key={paused ? 'paused' : 'running'}
                                        className={`hero-progress absolute inset-0 origin-left rounded-full bg-site-accent ${paused ? 'is-paused' : ''}`}
                                        style={{
                                            animationDuration: `${interval}ms`,
                                        }}
                                    />
                                )}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
