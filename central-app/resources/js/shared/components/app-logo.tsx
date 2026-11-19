/**
 * The full Miles Softwares logo: the mark and the name. `inverted`
 * is the version with light lettering, for dark backgrounds.
 */
export default function AppLogo({
    inverted = false,
    className = 'h-10 w-auto',
    alt = 'Miles Softwares',
}: {
    inverted?: boolean;
    className?: string;
    alt?: string;
}) {
    return (
        <img
            src={
                inverted
                    ? '/images/logo-with-text-light.png?v=20261120'
                    : '/images/logo-with-text.png?v=20261120'
            }
            alt={alt}
            width={771}
            height={230}
            className={className}
        />
    );
}
