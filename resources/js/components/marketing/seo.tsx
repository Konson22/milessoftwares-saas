import { Head } from '@inertiajs/react';

type Props = {
    title: string;
    description: string;
    canonical: string;
    /** Structured data for search engines, rendered as JSON-LD. */
    jsonLd?: Record<string, unknown>;
};

/**
 * Per-page search metadata. Rendered on the server (SSR), so crawlers see
 * it without running JavaScript. head-key avoids duplicate tags when Inertia
 * swaps pages on the client.
 */
export default function Seo({ title, description, canonical, jsonLd }: Props) {
    return (
        <Head title={title}>
            <meta
                head-key="description"
                name="description"
                content={description}
            />
            <link head-key="canonical" rel="canonical" href={canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta head-key="og:title" property="og:title" content={title} />
            <meta
                head-key="og:description"
                property="og:description"
                content={description}
            />
            <meta head-key="og:url" property="og:url" content={canonical} />
            <meta
                head-key="twitter:card"
                name="twitter:card"
                content="summary"
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
