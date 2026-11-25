import type { ImgHTMLAttributes } from 'react';

/** The Miles Global Softwares mark alone: the globe with its blue and green swooshes. */
export default function AppLogoIcon({
    className,
    alt = 'Miles Global Softwares',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <>
            <img
                src="/images/logo-icon.png?v=20261126c"
                alt={alt}
                className={`dark:hidden ${className ?? ''}`}
                {...props}
            />
            <img
                src="/images/logo-icon-light.png?v=20261126c"
                alt={alt}
                className={`hidden dark:block ${className ?? ''}`}
                {...props}
            />
        </>
    );
}
