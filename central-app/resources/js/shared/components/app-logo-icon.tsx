import type { ImgHTMLAttributes } from 'react';

/** The Miles mark alone; its navy link turns white in dark mode. */
export default function AppLogoIcon({
    className,
    alt = 'Miles Global Softwares',
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
