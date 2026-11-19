import type { ImgHTMLAttributes } from 'react';

/** The Miles Softwares mark alone; the dark-green T turns white in dark mode. */
export default function AppLogoIcon({
    className,
    alt = 'Miles Softwares',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <>
            <img
                src="/images/logo-icon.png?v=20261120"
                alt={alt}
                className={`dark:hidden ${className ?? ''}`}
                {...props}
            />
            <img
                src="/images/logo-icon-light.png?v=20261120"
                alt={alt}
                className={`hidden dark:block ${className ?? ''}`}
                {...props}
            />
        </>
    );
}
