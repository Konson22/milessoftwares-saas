import { Head } from '@inertiajs/react';
import { socialLinks, whatsappNumber } from '@/sites/public/components/social';

export const siteName = 'Tatook';

type Props = {
    title: string;
    description: string;
    canonical: string;
    /** Structured data for search engines, rendered as JSON-LD. */
    jsonLd?: Record<string, unknown>;
    /** The picture shown when the page is shared; a path on this site. */
    image?: string;
};

/** The company that makes Tatook. */
export const companyName = 'Miles Global Link Co. Ltd';

/**
 * Tatook for structured data on any page: the brand, with Miles Global
 * Link as the company behind it.
 */
export function organization(origin: string): Record<string, unknown> {
    return {
        '@type': 'Organization',
        '@id': `${origin}/#organization`,
        name: siteName,
        url: `${origin}/`,
        logo: `${origin}/images/logo-icon.png`,
        slogan: 'Business software that works together',
        parentOrganization: {
            '@type': 'Organization',
            name: companyName,
            alternateName: 'Miles Global Link',
        },
        sameAs: socialLinks
            .filter((link) => link.name !== 'WhatsApp')
            .map((link) => link.href),
        contactPoint: {
            '@type': 'ContactPoint',
            contactType: 'customer support',
            telephone: whatsappNumber.replace(/ /g, ''),
            availableLanguage: 'English',
        },
    };
}

/**
 * Per-page search metadata. Rendered on the server (SSR), so crawlers see
 * it without running JavaScript. head-key avoids duplicate tags when Inertia
 * swaps pages on the client.
 */
export default function Seo({
    title,
    description,
    canonical,
    jsonLd,
    image = '/images/og-image.jpg',
}: Props) {
    const imageUrl = new URL(image, canonical).href;
    const fullTitle = `${title} - ${siteName}`;

    return (
        <Head title={title}>
            <meta
                head-key="description"
                name="description"
                content={description}
            />
            <link head-key="canonical" rel="canonical" href={canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta
                head-key="og:site_name"
                property="og:site_name"
                content={siteName}
            />
            <meta head-key="og:locale" property="og:locale" content="en_US" />
            <meta head-key="og:title" property="og:title" content={fullTitle} />
            <meta
                head-key="og:description"
                property="og:description"
                content={description}
            />
            <meta head-key="og:url" property="og:url" content={canonical} />
            <meta head-key="og:image" property="og:image" content={imageUrl} />
            <meta
                head-key="og:image:alt"
                property="og:image:alt"
                content={`${siteName}: ERP solutions and business applications`}
            />
            <meta
                head-key="twitter:card"
                name="twitter:card"
                content="summary_large_image"
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content={fullTitle}
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content={description}
            />
            <meta
                head-key="twitter:image"
                name="twitter:image"
                content={imageUrl}
            />
            {jsonLd && (
                <script
                    head-key="jsonld"
                    type="application/ld+json"
                    // Server-built object, serialized; never user-supplied HTML.
                    dangerouslySetInnerHTML={{
                        __html: JSON.stringify(jsonLd).replace(/</g, '\\u003c'),
                    }}
                />
            )}
        </Head>
    );
}
