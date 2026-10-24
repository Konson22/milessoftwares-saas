import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({
    className,
    alt = 'Miles Global Softwares',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/images/logo-icon.png"
            alt={alt}
            className={className}
            {...props}
        />
    );
}
