/**
 * The full Miles Global Softwares logo: the globe and the name. `inverted`
 * is the version with light lettering, for dark backgrounds.
 */
export default function AppLogo({
    inverted = false,
    className = 'h-14 w-auto',
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
                    ? '/images/logo-with-text-light.png?v=20261126c'
                    : '/images/logo-with-text.png?v=20261126c'
            }
            alt={alt}
            width={640}
            height={320}
            className={className}
        />
    );
}
