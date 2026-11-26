import type { ReactNode } from 'react';
import { container } from '@/sites/public/components/styles';

const buttonBase =
    'inline-flex h-11 items-center justify-center gap-2 rounded-lg px-5 text-sm font-semibold whitespace-nowrap transition-colors duration-150';

/** White with navy type, the main action on a blue picture or band. */
export const onBlue = `${buttonBase} bg-white text-site-navy hover:bg-site-tint`;

/** A white outline, the second action on a blue picture or band. */
export const outlineOnBlue = `${buttonBase} border border-white text-white hover:bg-site-navy-light`;

/** Body type on the blue pictures and bands (over 8:1 on them). */
export const onBlueSoft = 'text-[#dce8ff]';

export type HeroPicture = {
    /** The full-size picture (2172 × 724, devices on the right). */
    src: string;
    /** The same picture 1280 wide. */
    small: string;
    /** A background class in the colour of the picture's plain left side. */
    base: string;
};

/**
 * Where the picture turns from a block above the copy into the band's
 * background. `lg` suits pictures whose devices start past 55% of their
 * width; `xl` (narrower copy, wider crop above it until then) suits
 * pictures whose devices start near the middle. Whole class strings, so
 * Tailwind sees them.
 */
const layouts = {
    lg: {
        sizes: '(min-width: 1024px) 100vw, 230vw',
        img: 'mt-[calc(4.5rem+1px)] block aspect-[4/3] w-full object-cover object-right sm:aspect-[16/9] lg:absolute lg:inset-0 lg:-z-10 lg:mt-0 lg:aspect-auto lg:size-full',
        row: 'flex items-center pt-6 pb-14 sm:pt-8 sm:pb-16 lg:min-h-[34rem] lg:pt-28 lg:pb-20',
        copy: 'site-rise max-w-xl lg:max-w-[46%]',
        title: 'text-[2.25rem] leading-[1.08] font-semibold tracking-tight text-balance text-white sm:text-5xl lg:text-[3.5rem]',
    },
    xl: {
        sizes: '(min-width: 1280px) 100vw, 190vw',
        img: 'mt-[calc(4.5rem+1px)] block aspect-[16/10] w-full object-cover object-right sm:aspect-[2/1] xl:absolute xl:inset-0 xl:-z-10 xl:mt-0 xl:aspect-auto xl:size-full',
        row: 'flex items-center pt-6 pb-14 sm:pt-8 sm:pb-16 xl:min-h-[32rem] xl:pt-28 xl:pb-16',
        copy: 'site-rise max-w-2xl xl:max-w-[27rem]',
        title: 'text-[2.25rem] leading-[1.08] font-semibold tracking-tight text-balance text-white sm:text-5xl xl:text-[2.875rem]',
    },
} as const;

/**
 * A product page's opening band on a wide picture whose devices sit on
 * its right and whose left is plain blue. From `from` (`lg` by default)
 * the picture fills the band behind the copy; below it, its right part
 * (the devices) is a block above the copy, under the fixed header (4.5rem
 * and its border).
 */
export default function ImageHero({
    picture,
    title,
    intro,
    children,
    from = 'lg',
}: {
    picture: HeroPicture;
    title: ReactNode;
    intro?: ReactNode;
    children?: ReactNode;
    from?: keyof typeof layouts;
}) {
    const layout = layouts[from];

    return (
        <section
            className={`site-dark relative isolate overflow-hidden ${picture.base}`}
        >
            <img
                src={picture.src}
                srcSet={`${picture.small} 1280w, ${picture.src} 2172w`}
                sizes={layout.sizes}
                alt=""
                aria-hidden
                fetchPriority="high"
                className={layout.img}
            />
            <div className={`${container} ${layout.row}`}>
                <div className={layout.copy}>
                    <h1 className={layout.title}>{title}</h1>
                    {intro && (
                        <p
                            className={`mt-5 max-w-lg text-base leading-relaxed text-pretty sm:text-lg ${onBlueSoft}`}
                        >
                            {intro}
                        </p>
                    )}
                    {children && (
                        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                            {children}
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
