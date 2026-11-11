import type { ImgHTMLAttributes } from 'react';

/** The Tatook mark alone; the dark-green T turns white in dark mode. */
export default function AppLogoIcon({
    className,
    alt = 'Tatook',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <>
            <img
                src="/images/logo-icon.png"
                alt={alt}
                className={`dark:hidden ${className ?? ''}`}
                {...props}
            />
            <img
                src="/images/logo-icon-light.png"
                alt={alt}
                className={`hidden dark:block ${className ?? ''}`}
                {...props}
            />
        </>
    );
}
