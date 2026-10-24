/** Where people can reach us besides the contact form. */
export const whatsappNumber = '+211 920 079 070';

export const socialLinks = [
    {
        name: 'WhatsApp',
        label: `WhatsApp ${whatsappNumber}`,
        href: 'https://wa.me/211920079070',
        // Simple Icons, CC0.
        path: 'M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.48-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.31-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42',
    },
    {
        name: 'Facebook',
        label: 'Facebook',
        href: 'https://www.facebook.com/profile.php?id=61595274547445',
        path: 'M9.1 23.69v-7.98H6.62v-3.67H9.1v-1.58c0-4.09 1.85-5.98 5.86-5.98.76 0 2.07.15 2.61.3v3.32c-.28-.03-.78-.04-1.39-.04-1.97 0-2.73.75-2.73 2.68v1.3h3.92l-.67 3.67h-3.25V24C19.4 23.28 24 18.18 24 12 24 5.37 18.63 0 12 0S0 5.37 0 12c0 5.63 3.87 10.35 9.1 11.69',
    },
];

/** A round brand icon linking out, for the footer and the contact page. */
export function SocialIcon({
    link,
    className = '',
}: {
    link: (typeof socialLinks)[number];
    className?: string;
}) {
    return (
        <a
            href={link.href}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`${link.label} (opens in a new tab)`}
            className={`inline-flex size-10 items-center justify-center rounded-full transition-colors ${className}`}
        >
            <svg
                viewBox="0 0 24 24"
                className="size-5"
                fill="currentColor"
                aria-hidden
            >
                <path d={link.path} />
            </svg>
        </a>
    );
}
