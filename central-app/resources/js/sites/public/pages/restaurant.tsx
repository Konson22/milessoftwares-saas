import { Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    connectivityLabels,
    licensingLabels,
    platformLabels,
} from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import FaqList from '@/sites/public/components/faq';
import ImageHero, {
    onBlue,
    onBlueSoft,
    outlineOnBlue,
} from '@/sites/public/components/image-hero';
import {
    PrimaryAction,
    ProductSeo,
} from '@/sites/public/components/product-profile';
import type { ProductProfileProps } from '@/sites/public/components/product-profile';
import SectionHeading from '@/sites/public/components/section-heading';
import {
    button,
    container,
    panel,
    section,
} from '@/sites/public/components/styles';

/**
 * The blues of the hero picture and the site navy. White type keeps at
 * least 8:1 on `base`, `deep` and `deeper`; `ink` keeps 6:1 on white.
 */
const tone = {
    base: 'bg-[#00256e]',
    deep: 'bg-site-navy',
    deeper: 'bg-site-navy-light',
    ink: 'text-site-accent',
    soft: onBlueSoft,
};

/** What the restaurant app does, by area of the business. */
const featureGroups: { title: string; badge: string; points: string[] }[] = [
    {
        title: 'Floor and tables',
        badge: 'Floor',
        points: [
            'Every table on one screen: free, or with an open order',
            'Open an order on a table in one tap',
            'Move an order to another table when guests change seats',
        ],
    },
    {
        title: 'Orders',
        badge: 'Orders',
        points: [
            'Add dishes and drinks to an open order at any time',
            'Void a line or cancel an order, with a record of who did it',
            'Send new lines to the kitchen as they are added',
        ],
    },
    {
        title: 'Kitchen',
        badge: 'Kitchen',
        points: [
            'A kitchen screen with tickets as they come in',
            'Mark each ticket ready, then served',
            'Clear finished tickets to keep the screen tidy',
        ],
    },
    {
        title: 'Bills and payments',
        badge: 'Billing',
        points: [
            'Print the bill at the table or the counter',
            'Take payment and close the table',
            'Each paid bill becomes a sale on the till',
        ],
    },
    {
        title: 'Tills and shifts',
        badge: 'Tills',
        points: [
            'Open and close a till for each shift',
            'Hand a till over to the next cashier, who checks and receives it',
            'Daily sales by till and by cashier',
        ],
    },
    {
        title: 'Menu and drinks stock',
        badge: 'Stock',
        points: [
            'Dishes made to order and drinks kept in stock, each at its own price',
            'Record deliveries, opening stock and adjustments',
            'Drinks sold come off stock, with every movement listed',
        ],
    },
    {
        title: 'Branches and staff',
        badge: 'Branches',
        points: [
            'Several branches under one organization, with an overview of all',
            'Roles and permissions for each member of staff, per branch',
            'Waiters, storekeepers and administrators each see only their work',
        ],
    },
    {
        title: 'Waiter devices and books',
        badge: 'Devices',
        points: [
            'Waiters take orders on registered POS devices and phones',
            'Bills printed straight from terminal printers',
            'Add Finance & Accounting and paid bills post to your books',
        ],
    },
];

type Package = {
    name: string;
    blurb: string;
    monthly: number;
    annual: number;
    branches: number;
    administrators: number;
    waiters: number;
    storekeepers: number;
};

/** The subscription packages; prices are for the whole package, not per branch. */
const packages: Package[] = [
    {
        name: 'Starter',
        blurb: 'One branch with a small floor team.',
        monthly: 10,
        annual: 100,
        branches: 1,
        administrators: 1,
        waiters: 3,
        storekeepers: 1,
    },
    {
        name: 'Standard',
        blurb: 'A second branch, or a busier floor.',
        monthly: 25,
        annual: 250,
        branches: 2,
        administrators: 1,
        waiters: 6,
        storekeepers: 2,
    },
    {
        name: 'Premium',
        blurb: 'Up to five branches and a large team.',
        monthly: 50,
        annual: 500,
        branches: 5,
        administrators: 2,
        waiters: 20,
        storekeepers: 5,
    },
];

const unlimited = [
    'Employee records',
    'Menu items',
    'Tables',
    'Orders and transactions',
];

const addOns: { name: string; monthly: number; annual: number }[] = [
    { name: 'Additional branch', monthly: 10, annual: 100 },
    {
        name: 'Additional POS device + waiter account',
        monthly: 1,
        annual: 10,
    },
    { name: 'Additional storekeeper', monthly: 2, annual: 20 },
    { name: 'Additional administrator', monthly: 3, annual: 30 },
];

const rules = [
    'Prices apply to the entire package, not per branch.',
    'Each POS licence includes one registered POS device and one waiter account.',
    'Waiter account and POS device allowances always match.',
    'Allowances are shared across your subscribed branches.',
    'Annual subscriptions include two months free.',
    'Cloud hosting, software updates and standard remote support are included.',
    'Hardware, installation and on-site training are charged separately.',
];

/**
 * Restaurant ERP: the POS picture across the hero, the highlights in a
 * strip, the features as cards, the four steps of a table on a navy
 * band, the full feature list, who it is for, the subscription packages
 * and questions. Words come from the catalog; the packages, add-ons and
 * rules are this page's own (the registry's plans are not shown here).
 */
export default function RestaurantPage({
    product,
    trialDays,
    canonical,
}: ProductProfileProps) {
    const comingSoon = product.status === 'coming_soon';

    const glance = [
        {
            term: 'Platforms',
            values: product.offering.platforms.map((p) => platformLabels[p]),
        },
        {
            term: 'Connectivity',
            values: product.offering.connectivity.map(
                (c) => connectivityLabels[c],
            ),
        },
        {
            term: 'Licensing',
            values: product.offering.licensing.map((l) => licensingLabels[l]),
        },
    ];

    return (
        <>
            <ProductSeo product={product} canonical={canonical} />

            <ImageHero
                picture={{
                    src: '/images/hero/restaurant-hero.webp',
                    small: '/images/hero/restaurant-hero-1280.webp',
                    base: tone.base,
                }}
                title={product.headline}
                intro={product.subhead}
            >
                <PrimaryAction
                    product={product}
                    trialDays={trialDays}
                    className={onBlue}
                />
                <a href="#plans" className={outlineOnBlue}>
                    See plans
                </a>
            </ImageHero>

            {/* Highlights */}
            {product.highlights && product.highlights.length > 0 && (
                <section
                    aria-label="Highlights"
                    className="border-b border-site-line bg-white"
                >
                    <ul
                        className={`${container} grid gap-x-6 gap-y-4 py-6 sm:grid-cols-2 lg:grid-cols-5 lg:py-8`}
                    >
                        {product.highlights.map((highlight) => (
                            <li
                                key={highlight}
                                className="border-l-2 border-site-accent pl-3 text-sm font-semibold text-site-ink"
                            >
                                {highlight}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/* Features */}
            {product.features.length > 0 && (
                <section
                    aria-labelledby="features-heading"
                    className={`bg-site-wash ${section}`}
                >
                    <div className={container}>
                        <SectionHeading
                            id="features-heading"
                            title="Everything the floor and the kitchen need"
                            lead={product.overview?.[0] ?? product.summary}
                        />
                        <ul className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {product.features.map((feature) => (
                                <li
                                    key={feature.title}
                                    className={`${panel} flex flex-col border-t-4 border-t-site-accent p-6`}
                                >
                                    <h3 className="text-lg font-semibold text-site-ink">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-relaxed text-site-muted">
                                        {feature.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {/* How a table runs */}
            {product.workflow.length > 0 && (
                <section
                    aria-labelledby="workflow-heading"
                    className={`site-dark ${tone.deep} ${section}`}
                >
                    <div className={container}>
                        <SectionHeading
                            id="workflow-heading"
                            title="How a table runs"
                            lead="Four steps from the moment guests sit down to the moment the table is free again."
                        />
                        <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {product.workflow.map((step, i) => (
                                <li
                                    key={step.title}
                                    className={`rounded-xl ${tone.deeper} p-6`}
                                >
                                    <span className="inline-flex size-10 items-center justify-center rounded-full bg-white text-base font-bold text-site-navy">
                                        {i + 1}
                                    </span>
                                    <h3 className="mt-5 text-lg font-semibold text-white">
                                        {step.title}
                                    </h3>
                                    <p
                                        className={`mt-2 text-sm leading-relaxed ${tone.soft}`}
                                    >
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            )}

            {/* Full feature list */}
            <section
                aria-labelledby="feature-list-heading"
                className={`bg-site-wash ${section}`}
            >
                <div className={container}>
                    <SectionHeading
                        id="feature-list-heading"
                        title="Full feature list"
                        lead="The full list, from the first order of the day to closing the till."
                    />
                    <ul className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {featureGroups.map((group, i) => (
                            <li
                                key={group.title}
                                className="rounded-xl border-2 border-site-ink bg-white p-1.5"
                            >
                                <div className="flex h-full flex-col rounded-lg border border-dashed border-site-silver px-5 pt-5 pb-6">
                                    <Emblem
                                        index={i}
                                        word={group.badge}
                                        className="mx-auto w-full max-w-[13rem]"
                                    />
                                    <h3 className="mt-4 text-center text-sm font-bold tracking-[0.14em] text-site-ink uppercase">
                                        {group.title}
                                    </h3>
                                    <div
                                        aria-hidden
                                        className="mx-auto mt-3 flex items-center gap-2 text-site-accent"
                                    >
                                        <span className="h-0.5 w-8 bg-site-ink" />
                                        <span className="text-xs">★</span>
                                        <span className="h-0.5 w-8 bg-site-ink" />
                                    </div>
                                    <ul className="mt-4 space-y-2.5 text-sm leading-relaxed text-site-body">
                                        {group.points.map((point) => (
                                            <li
                                                key={point}
                                                className="border-l-2 border-site-accent pl-3"
                                            >
                                                {point}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            {/* Who it is for, and what it includes */}
            <section
                aria-labelledby="fit-heading"
                className={`bg-white ${section}`}
            >
                <div className={`${container} grid gap-8 lg:grid-cols-2`}>
                    <div className={`${panel} p-6 sm:p-8`}>
                        <h2
                            id="fit-heading"
                            className="text-2xl font-semibold tracking-tight text-site-ink"
                        >
                            Made for your kitchen
                        </h2>
                        <p className="mt-3 leading-relaxed text-site-body">
                            {product.audience}
                        </p>
                        <dl className="mt-6 grid gap-5 border-t border-site-line pt-6 sm:grid-cols-3">
                            {glance.map(({ term, values }) => (
                                <div key={term}>
                                    <dt className="text-sm font-semibold text-site-muted">
                                        {term}
                                    </dt>
                                    {values.map((value) => (
                                        <dd
                                            key={value}
                                            className="mt-1 text-sm font-medium text-site-ink"
                                        >
                                            {value}
                                        </dd>
                                    ))}
                                </div>
                            ))}
                        </dl>
                    </div>

                    {product.includes.length > 0 && (
                        <div className={`${panel} p-6 sm:p-8`}>
                            <h2 className="text-2xl font-semibold tracking-tight text-site-ink">
                                What comes with it
                            </h2>
                            <p className="mt-3 leading-relaxed text-site-body">
                                Everything shares one sign-in and one
                                organization. Add Finance and every paid bill
                                posts to your books.
                            </p>
                            <ul className="mt-6 divide-y divide-site-line border-t border-site-line">
                                {product.includes.map((application) => {
                                    const required =
                                        application.requirement === 'required';

                                    return (
                                        <li
                                            key={application.code}
                                            className="flex items-center justify-between gap-4 py-4"
                                        >
                                            <span className="font-semibold text-site-ink">
                                                {application.listed &&
                                                application.url !==
                                                    `/${product.url}` ? (
                                                    <Link
                                                        href={application.url}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {application.name}
                                                    </Link>
                                                ) : (
                                                    application.name
                                                )}
                                            </span>
                                            <span
                                                className={`text-sm font-semibold ${required ? tone.ink : 'text-site-muted'}`}
                                            >
                                                {required
                                                    ? 'Included'
                                                    : 'Optional add-on'}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}
                </div>
            </section>

            {/* Plans */}
            <section
                id="plans"
                aria-labelledby="plans-heading"
                className={`scroll-mt-20 border-y border-site-line bg-site-wash ${section}`}
            >
                <div className={container}>
                    <SectionHeading
                        id="plans-heading"
                        title="Subscription packages"
                        lead={
                            comingSoon
                                ? `${product.name} is not released yet. Join the waitlist and we will tell you when it opens.`
                                : product.trial
                                  ? `Every package starts with a ${trialDays}-day free trial. Nothing is charged unless you choose a package.`
                                  : `${product.name} is ${product.status === 'early_access' ? 'in early access' : 'available'}. Talk to us to set it up for your restaurant.`
                        }
                    />
                    <Pricing product={product} trialDays={trialDays} />
                </div>
            </section>

            {/* Questions */}
            {product.faqs.length > 0 && (
                <section
                    aria-labelledby="product-faq-heading"
                    className={`${container} grid gap-6 ${section} lg:grid-cols-[0.8fr_1.2fr] lg:gap-14`}
                >
                    <h2
                        id="product-faq-heading"
                        className="text-[1.625rem] leading-tight font-semibold tracking-tight text-site-ink sm:text-3xl"
                    >
                        Questions
                    </h2>
                    <FaqList items={product.faqs} />
                </section>
            )}

            {/* Closing call to action */}
            <section
                aria-labelledby="restaurant-cta-heading"
                className="px-4 pb-16 sm:px-6 sm:pb-24 lg:px-8"
            >
                <div
                    className={`site-dark mx-auto grid max-w-7xl items-center gap-8 rounded-xl ${tone.base} px-6 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1fr_auto]`}
                >
                    <div className="text-center lg:text-left">
                        <h2
                            id="restaurant-cta-heading"
                            className="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl sm:leading-tight"
                        >
                            Run your floor on {product.name}
                        </h2>
                        <p className={`mt-4 max-w-xl ${tone.soft}`}>
                            {product.summary}
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-center gap-3">
                        <PrimaryAction
                            product={product}
                            trialDays={trialDays}
                            className={onBlue}
                        />
                        <Link href="/contact" className={outlineOnBlue}>
                            Talk to us
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

/**
 * The packages with a monthly / annual switch, the add-ons, Enterprise and
 * the subscription rules. Every package starts with the product's main
 * action (its free trial while one is open); Enterprise goes to contact.
 */
function Pricing({
    product,
    trialDays,
}: {
    product: CatalogEntry;
    trialDays: number;
}) {
    const [annual, setAnnual] = useState(false);
    const per = annual ? '/ year' : '/ month';

    const toggle = (value: boolean, label: string) => (
        <button
            type="button"
            aria-pressed={annual === value}
            onClick={() => setAnnual(value)}
            className={`h-10 rounded-md px-4 text-sm font-semibold transition-colors ${annual === value ? 'bg-site-navy text-white' : 'text-site-ink hover:bg-site-tint'}`}
        >
            {label}
        </button>
    );

    const allowances = (pkg: Package): [string, string | number][] => [
        ['Branches', pkg.branches],
        ['Administrators', pkg.administrators],
        ['Waiter POS devices and accounts', pkg.waiters],
        ['Storekeepers', pkg.storekeepers],
        ...unlimited.map((term): [string, string] => [term, 'Unlimited']),
    ];

    return (
        <>
            <div className="mt-10 flex flex-col items-center gap-2">
                <div
                    role="group"
                    aria-label="Billing period"
                    className="inline-flex rounded-lg border border-site-line bg-white p-1"
                >
                    {toggle(false, 'Monthly')}
                    {toggle(true, 'Annually')}
                </div>
                <p className={`text-sm font-semibold ${tone.ink}`}>
                    Pay annually and get two months free
                </p>
            </div>

            <ul className="mt-10 grid gap-5 lg:grid-cols-3">
                {packages.map((pkg) => (
                    <li
                        key={pkg.name}
                        className={`${panel} flex flex-col p-6 sm:p-8`}
                    >
                        <h3 className="text-xl font-semibold text-site-ink">
                            {pkg.name}
                        </h3>
                        <p className="mt-1 text-sm text-site-muted">
                            {pkg.blurb}
                        </p>
                        <p className="mt-6 flex items-baseline gap-2">
                            <span className="font-public-display text-4xl font-bold tracking-tight text-site-ink tabular-nums">
                                ${annual ? pkg.annual : pkg.monthly}
                            </span>
                            <span className="text-sm text-site-muted">
                                {per}
                            </span>
                        </p>
                        <p className="mt-1 text-sm text-site-muted">
                            {annual
                                ? `Instead of $${pkg.monthly * 12} paid monthly`
                                : `or $${pkg.annual} a year`}
                        </p>
                        <PrimaryAction
                            product={product}
                            trialDays={trialDays}
                            className={`mt-6 w-full ${button.primary}`}
                        />
                        <dl className="mt-6 space-y-3 border-t border-site-line pt-5 text-sm">
                            {allowances(pkg).map(([term, value]) => (
                                <div
                                    key={term}
                                    className="flex items-baseline justify-between gap-4"
                                >
                                    <dt className="text-site-body">{term}</dt>
                                    <dd className="font-semibold text-site-ink tabular-nums">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </li>
                ))}
            </ul>

            <div className="mt-8 grid gap-5 lg:grid-cols-[1.4fr_1fr]">
                <div className={`${panel} p-6 sm:p-8`}>
                    <h3 className="text-xl font-semibold text-site-ink">
                        Add-ons
                    </h3>
                    <p className="mt-1 text-sm text-site-muted">
                        Grow any package one piece at a time.
                    </p>
                    <div className="mt-5 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-site-line text-site-muted">
                                    <th
                                        scope="col"
                                        className="py-2 pr-4 font-semibold"
                                    >
                                        Add-on
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-4 text-right font-semibold"
                                    >
                                        Monthly
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 text-right font-semibold"
                                    >
                                        Annually
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-site-line">
                                {addOns.map((addOn) => (
                                    <tr key={addOn.name}>
                                        <th
                                            scope="row"
                                            className="py-3 pr-4 font-medium text-site-ink"
                                        >
                                            {addOn.name}
                                        </th>
                                        <td
                                            className={`py-3 pr-4 text-right tabular-nums ${annual ? 'text-site-muted' : 'font-semibold text-site-ink'}`}
                                        >
                                            ${addOn.monthly}
                                        </td>
                                        <td
                                            className={`py-3 text-right tabular-nums ${annual ? 'font-semibold text-site-ink' : 'text-site-muted'}`}
                                        >
                                            ${addOn.annual}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    className={`site-dark flex flex-col rounded-xl ${tone.deep} p-6 sm:p-8`}
                >
                    <h3 className="text-xl font-semibold text-white">
                        Enterprise
                    </h3>
                    <p className="mt-4 font-public-display text-3xl font-bold tracking-tight text-white">
                        Custom pricing
                    </p>
                    <p className={`mt-3 flex-1 leading-relaxed ${tone.soft}`}>
                        Set your own allowances of branches, POS devices, waiter
                        accounts, administrators and storekeepers, for larger
                        groups and chains.
                    </p>
                    <Link
                        href={`/contact?subject=${encodeURIComponent(`Enterprise: ${product.name}`)}`}
                        className={`mt-6 ${onBlue}`}
                    >
                        Talk to us
                    </Link>
                </div>
            </div>

            <div className="mt-8">
                <h3 className="text-base font-semibold text-site-ink">
                    Subscription rules
                </h3>
                <ul className="mt-4 grid gap-x-8 gap-y-3 text-sm leading-relaxed text-site-body sm:grid-cols-2">
                    {rules.map((rule) => (
                        <li
                            key={rule}
                            className="border-l-2 border-site-accent pl-3"
                        >
                            {rule}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

/**
 * The outlines of the feature emblems, in a 200 × 150 box centred on
 * (100, 75): a seal, a shield, a hexagon, an octagon, a wide tag and a
 * crest. Taken in turn, so neighbouring cards differ.
 */
const emblemShapes = [
    'M38 75a62 62 0 1 0 124 0a62 62 0 1 0 -124 0Z',
    'M42 18H158V78C158 112 132 130 100 140C68 130 42 112 42 78Z',
    'M100 10L158 42V108L100 140L42 108V42Z',
    'M72 12H128L164 46V104L128 138H72L36 104V46Z',
    'M58 18H142L172 75L142 132H58L28 75Z',
    'M100 10L150 28L160 75L150 122L100 140L50 122L40 75L50 28Z',
];

/**
 * A vintage badge in solid colours: the shape with an inset white line,
 * a number on top, a white swallowtail ribbon with the word, and three
 * stars below. Navy and blue alternate. Decorative: the card's heading
 * carries the name.
 */
function Emblem({
    index,
    word,
    className,
}: {
    index: number;
    word: string;
    className?: string;
}) {
    const shape = emblemShapes[index % emblemShapes.length];
    const fill = index % 2 === 0 ? '#071a3d' : '#0756b8';

    return (
        <svg
            viewBox="0 0 200 150"
            aria-hidden
            className={`font-public-display ${className ?? ''}`}
        >
            <path d={shape} fill={fill} />
            <path
                d={shape}
                fill="none"
                stroke="#ffffff"
                strokeWidth="2"
                transform="translate(100 75) scale(0.86) translate(-100 -75)"
            />
            <text
                x="100"
                y="47"
                textAnchor="middle"
                fill="#ffffff"
                fontSize="11"
                fontWeight="700"
                letterSpacing="3"
            >
                {`NO. ${String(index + 1).padStart(2, '0')}`}
            </text>
            <path
                d="M8 63H192L181 79L192 95H8L19 79Z"
                fill="#ffffff"
                stroke={fill}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            <path d="M24 68H176M24 90H176" stroke={fill} strokeWidth="1" />
            <text
                x="100"
                y="85"
                textAnchor="middle"
                fill={fill}
                fontSize="17"
                fontWeight="800"
                letterSpacing="2.5"
            >
                {word.toUpperCase()}
            </text>
            <text
                x="100"
                y="115"
                textAnchor="middle"
                fill="#ffffff"
                fontSize="11"
                letterSpacing="5"
            >
                ★★★
            </text>
        </svg>
    );
}
