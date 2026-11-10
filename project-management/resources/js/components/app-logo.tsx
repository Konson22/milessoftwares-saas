import { cn } from '@miles/ui/lib/utils';

/**
 * The Tatook wordmark. `onDark` is the white version, for a navy band
 * that does not follow the colour scheme (the site footer); `light` is the
 * navy version always, for a white surface in either scheme (the sign-in card).
 */
export function AppLogo({
    className,
    onDark = false,
    light = false,
}: {
    className?: string;
    onDark?: boolean;
    light?: boolean;
}) {
    const shared = {
        alt: 'Tatook',
        width: 1625,
        height: 343,
    };

    if (onDark) {
        return (
            <img
                {...shared}
                src="/images/logo-site-light.png"
                className={className}
            />
        );
    }

    if (light) {
        return (
            <img
                {...shared}
                src="/images/logo-site.png"
                className={className}
            />
        );
    }

    return (
        <>
            <img
                {...shared}
                src="/images/logo-site.png"
                className={cn('dark:hidden', className)}
            />
            <img
                {...shared}
                src="/images/logo-site-light.png"
                className={cn('hidden dark:block', className)}
            />
        </>
    );
}
