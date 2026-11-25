import { Head } from '@inertiajs/react';
import {
    businessAddress,
    socialLinks,
    supportEmail,
    whatsappNumber,
} from '@/sites/public/components/social';

export const siteName = 'Miles Global Softwares';

type Props = {
    title: string;
    description: string;
    canonical: string;
    /** Structured data for search engines, rendered as JSON-LD. */
    jsonLd?: Record<string, unknown>;
    /** The picture shown when the page is shared; a path on this site. */
    image?: string;
    /** Keep the page out of search results (a step in a flow, not a landing page). */
    noindex?: boolean;
};

/** The company that makes Miles Global Softwares. */
export const companyName = 'Miles Global Link Co. Ltd';

/**
 * Miles Global Softwares for structured data on any page: the brand, with Miles Global
 * Link as the company behind it.
 */
export function organization(origin: string): Record<string, unknown> {
    const telephone = whatsappNumber.replace(/ /g, '');

    return {
        '@type': 'Organization',
        '@id': `${origin}/#organization`,
        name: siteName,
        // "Miles Global Softwares" alone reads as a misspelling of "tattoo" to search
        // engines; the alternate names tie it to business software.
        alternateName: ['Miles ERP', 'Miles Business Software'],
        legalName: companyName,
        url: `${origin}/`,
        logo: {
            '@type': 'ImageObject',
            url: `${origin}/images/logo-icon.png?v=20261126c`,
        },
        image: `${origin}/images/logo-with-text.png?v=20261126c`,
        description:
            'Miles Global Softwares is ERP and business software for pharmacies, shops, restaurants, schools and petrol stations, with accounting, HR and payroll, made by Miles Global Link Co. Ltd.',
        slogan: 'Bring it all together',
        email: supportEmail,
        telephone,
        address: postalAddress(),
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
            telephone,
            email: supportEmail,
            areaServed: businessAddress.countryCode,
            availableLanguage: 'English',
        },
    };
}

/** The office as schema.org's PostalAddress. */
export function postalAddress(): Record<string, unknown> {
    return {
        '@type': 'PostalAddress',
        streetAddress: businessAddress.street,
        addressLocality: businessAddress.locality,
        addressCountry: businessAddress.countryCode,
    };
}

/**
 * Miles Global Softwares as software (the platform as a whole; each product page describes
 * its own application).
 */
export function softwareApplication(origin: string): Record<string, unknown> {
    return {
        '@type': 'SoftwareApplication',
        '@id': `${origin}/#software`,
        name: siteName,
        alternateName: 'Miles ERP',
        url: `${origin}/`,
        applicationCategory: 'BusinessApplication',
        applicationSubCategory: 'Enterprise resource planning (ERP)',
        operatingSystem: 'Web, Windows, Android',
        description:
            'ERP and business software: pharmacy, retail, restaurant, school and petrol station ERPs with finance and accounting, HR, payroll and project management under one account.',
        featureList: [
            'Pharmacy ERP',
            'Retail and wholesale POS',
            'Restaurant ERP',
            'School management',
            'Petrol station management',
            'Finance and accounting',
            'HR and payroll',
            'Project management',
        ],
        publisher: { '@id': `${origin}/#organization` },
        provider: { '@id': `${origin}/#organization` },
    };
}

/** The browser and social title: "Page - Miles Global Softwares", or as given when it leads with the brand. */
export function fullTitle(title: string): string {
    return title.startsWith(`${siteName} |`) ? title : `${title} - ${siteName}`;
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
    noindex = false,
}: Props) {
    const imageUrl = new URL(image, canonical).href;
    const shownTitle = fullTitle(title);

    return (
        <Head title={title}>
            <meta
                head-key="description"
                name="description"
                content={description}
            />
            <link head-key="canonical" rel="canonical" href={canonical} />
            {noindex && (
                <meta head-key="robots" name="robots" content="noindex" />
            )}
            <meta head-key="og:type" property="og:type" content="website" />
            <meta
                head-key="og:site_name"
                property="og:site_name"
                content={siteName}
            />
            <meta head-key="og:locale" property="og:locale" content="en_US" />
            <meta
                head-key="og:title"
                property="og:title"
                content={shownTitle}
            />
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
                content={shownTitle}
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
