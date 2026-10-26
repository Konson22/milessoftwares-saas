/**
 * A photo for the top of an application's card, of the kind of business
 * it serves. Decorative (the card names the application), so it is
 * hidden from assistive technology.
 *
 * The photos are free Unsplash images, self-hosted in
 * public/images/products (see CREDITS.md there) because the CSP allows
 * images from 'self' only. Unknown codes get a general office photo.
 */

const photos: Record<string, string> = {
    pharmacy: 'pharmacy',
    retail: 'retail',
    restaurant: 'restaurant',
    inventory: 'inventory',
    finance: 'finance',
    advanced_accounting: 'finance',
    payroll: 'payroll',
    hr: 'hr',
    school: 'school',
    clinic: 'clinic',
    crm: 'crm',
};

/** The photo's URL for an application code. */
export function productPhoto(code: string): string {
    return `/images/products/${photos[code] ?? 'default'}.webp`;
}

export default function ProductArt({ code }: { code: string }) {
    return (
        <div aria-hidden className="relative h-44 overflow-hidden bg-site-soft">
            <img
                src={productPhoto(code)}
                alt=""
                width={800}
                height={400}
                loading="lazy"
                decoding="async"
                className="size-full object-cover"
            />
        </div>
    );
}
