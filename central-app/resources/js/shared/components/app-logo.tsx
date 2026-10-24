/**
 * The full Miles Global Softwares logo: the mark and the name. `inverted`
 * is the version with light lettering, for dark backgrounds.
 */
export default function AppLogo({
    inverted = false,
    className = 'h-10 w-auto',
    alt = 'Miles Global Softwares',
}: {
    inverted?: boolean;
    className?: string;
    alt?: string;
}) {
    return (
        <img
            src={
                inverted
                    ? '/images/logo-with-text-light.png'
                    : '/images/logo-with-text.png'
            }
            alt={alt}
            width={870}
            height={240}
            className={className}
        />
    );
}
