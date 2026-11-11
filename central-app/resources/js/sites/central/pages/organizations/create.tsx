import { Form, Head } from '@inertiajs/react';
import {
    Building2,
    Check,
    CircleAlert,
    Globe,
    LayoutGrid,
    MapPin,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import OrganizationController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationController';
import Heading from '@/shared/components/heading';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';
import { appIcon } from '@/shared/lib/app-icons';
import { workspaces } from '@/routes';
import {
    create as createOrganization,
    slugAvailability,
} from '@/routes/organizations';

type Props = {
    rootDomain: string;
    defaults: {
        country_code: string;
        default_currency: string;
        timezone: string;
    };
    /** Applications the new workspace can try free; selected comes from ?app=. */
    trial: {
        days: number;
        applications: { code: string; name: string; tagline: string | null }[];
        selected: string | null;
    };
};

type Availability =
    | { state: 'idle' }
    | { state: 'checking' }
    | { state: 'available' }
    | { state: 'unavailable'; errors: string[] };

type Country = {
    code: string;
    name: string;
    currency: string;
    zones: { id: string; label: string }[];
};

/** East Africa, South Sudan first; each with its currency and time zones. */
const eastAfrica: Country[] = [
    {
        code: 'SS',
        name: 'South Sudan',
        currency: 'SSP',
        zones: [{ id: 'Africa/Juba', label: 'Juba (UTC+2)' }],
    },
    {
        code: 'KE',
        name: 'Kenya',
        currency: 'KES',
        zones: [{ id: 'Africa/Nairobi', label: 'Nairobi (UTC+3)' }],
    },
    {
        code: 'UG',
        name: 'Uganda',
        currency: 'UGX',
        zones: [{ id: 'Africa/Kampala', label: 'Kampala (UTC+3)' }],
    },
    {
        code: 'TZ',
        name: 'Tanzania',
        currency: 'TZS',
        zones: [{ id: 'Africa/Dar_es_Salaam', label: 'Dar es Salaam (UTC+3)' }],
    },
    {
        code: 'RW',
        name: 'Rwanda',
        currency: 'RWF',
        zones: [{ id: 'Africa/Kigali', label: 'Kigali (UTC+2)' }],
    },
    {
        code: 'BI',
        name: 'Burundi',
        currency: 'BIF',
        zones: [{ id: 'Africa/Bujumbura', label: 'Bujumbura (UTC+2)' }],
    },
    {
        code: 'CD',
        name: 'DR Congo',
        currency: 'CDF',
        zones: [
            { id: 'Africa/Kinshasa', label: 'Kinshasa (UTC+1)' },
            { id: 'Africa/Lubumbashi', label: 'Lubumbashi (UTC+2)' },
        ],
    },
    {
        code: 'ET',
        name: 'Ethiopia',
        currency: 'ETB',
        zones: [{ id: 'Africa/Addis_Ababa', label: 'Addis Ababa (UTC+3)' }],
    },
    {
        code: 'SO',
        name: 'Somalia',
        currency: 'SOS',
        zones: [{ id: 'Africa/Mogadishu', label: 'Mogadishu (UTC+3)' }],
    },
    {
        code: 'DJ',
        name: 'Djibouti',
        currency: 'DJF',
        zones: [{ id: 'Africa/Djibouti', label: 'Djibouti (UTC+3)' }],
    },
    {
        code: 'ER',
        name: 'Eritrea',
        currency: 'ERN',
        zones: [{ id: 'Africa/Asmara', label: 'Asmara (UTC+3)' }],
    },
    {
        code: 'SD',
        name: 'Sudan',
        currency: 'SDG',
        zones: [{ id: 'Africa/Khartoum', label: 'Khartoum (UTC+2)' }],
    },
];

const currencyNames: Record<string, string> = {
    SSP: 'South Sudanese pound',
    KES: 'Kenyan shilling',
    UGX: 'Ugandan shilling',
    TZS: 'Tanzanian shilling',
    RWF: 'Rwandan franc',
    BIF: 'Burundian franc',
    CDF: 'Congolese franc',
    ETB: 'Ethiopian birr',
    SOS: 'Somali shilling',
    DJF: 'Djiboutian franc',
    ERN: 'Eritrean nakfa',
    SDG: 'Sudanese pound',
    USD: 'US dollar',
};

function countryFor(code: string): Country {
    return eastAfrica.find((country) => country.code === code) ?? eastAfrica[0];
}

/** The country's own currency, and US dollars, which many businesses here use. */
function currenciesFor(country: Country): string[] {
    return [country.currency, 'USD'];
}

const selectClass =
    'h-8 w-full min-w-0 rounded-md border border-input bg-transparent px-2 text-base outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

function suggestSlug(name: string): string {
    return name
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .replace(/^[^a-z]+/, '')
        .slice(0, 32)
        .replace(/-+$/, '');
}

function useSlugAvailability(slug: string): Availability {
    const [availability, setAvailability] = useState<Availability>({
        state: 'idle',
    });

    useEffect(() => {
        if (slug === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setAvailability({ state: 'checking' });

            fetch(slugAvailability.url({ query: { slug } }), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((body: { available: boolean; errors: string[] }) =>
                    setAvailability(
                        body.available
                            ? { state: 'available' }
                            : { state: 'unavailable', errors: body.errors },
                    ),
                )
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setAvailability({ state: 'idle' });
                    }
                });
        }, 350);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [slug]);

    return slug === '' ? { state: 'idle' } : availability;
}

/**
 * A new business: its names, its workspace address, the applications to
 * try free (any number of them) and where it is, with a summary beside the
 * form that keeps the address and the choices in view.
 */
export default function CreateOrganization({
    rootDomain,
    defaults,
    trial,
}: Props) {
    const [displayName, setDisplayName] = useState('');
    const [slug, setSlug] = useState('');
    const [slugEdited, setSlugEdited] = useState(false);
    const [picked, setPicked] = useState<string[]>(
        trial.selected ? [trial.selected] : [],
    );
    const availability = useSlugAvailability(slug);
    const [country, setCountry] = useState(() =>
        countryFor(defaults.country_code),
    );
    const [currency, setCurrency] = useState(() =>
        currenciesFor(country).includes(defaults.default_currency)
            ? defaults.default_currency
            : country.currency,
    );
    const [timezone, setTimezone] = useState(() =>
        country.zones.some((zone) => zone.id === defaults.timezone)
            ? defaults.timezone
            : country.zones[0].id,
    );

    // A new country brings its own currency and time zone.
    const chooseCountry = (code: string) => {
        const next = countryFor(code);
        setCountry(next);
        setCurrency(next.currency);
        setTimezone(next.zones[0].id);
    };

    const toggle = (code: string) =>
        setPicked((current) =>
            current.includes(code)
                ? current.filter((item) => item !== code)
                : [...current, code],
        );

    return (
        <>
            <Head title="New business" />

            <div className="space-y-6">
                <Heading
                    title="New business"
                    description="Your business gets its own workspace address. You become its owner."
                />

                <Form {...OrganizationController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                            <div className="space-y-5">
                                <Section
                                    step={1}
                                    icon={Building2}
                                    title="Your business"
                                    description="The name your team and customers know, and an email for the account."
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="display_name">
                                                Business name
                                            </Label>
                                            <Input
                                                id="display_name"
                                                name="display_name"
                                                required
                                                autoFocus
                                                placeholder="Juba Corner Pharmacy"
                                                value={displayName}
                                                onChange={(event) => {
                                                    setDisplayName(
                                                        event.target.value,
                                                    );

                                                    if (!slugEdited) {
                                                        setSlug(
                                                            suggestSlug(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        );
                                                    }
                                                }}
                                            />
                                            <InputError
                                                message={errors.display_name}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="contact_email">
                                                Contact email (optional)
                                            </Label>
                                            <Input
                                                id="contact_email"
                                                name="contact_email"
                                                type="email"
                                                placeholder="accounts@yourbusiness.com"
                                            />
                                            <InputError
                                                message={errors.contact_email}
                                            />
                                        </div>
                                    </div>
                                </Section>

                                <Section
                                    step={2}
                                    icon={Globe}
                                    title="Workspace address"
                                    description="Where you and your team sign in. Letters, numbers and dashes."
                                >
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="slug"
                                            className="sr-only"
                                        >
                                            Workspace address
                                        </Label>
                                        <div className="flex items-stretch overflow-hidden rounded-md border border-input bg-background shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50">
                                            <span className="hidden items-center border-r bg-muted px-3 text-sm text-muted-foreground sm:flex">
                                                https://
                                            </span>
                                            <input
                                                id="slug"
                                                name="slug"
                                                required
                                                value={slug}
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder="your-business"
                                                aria-describedby="slug-status"
                                                className="min-w-0 flex-1 bg-transparent px-3 py-2 text-sm font-medium outline-none placeholder:text-muted-foreground/70"
                                                onChange={(event) => {
                                                    setSlugEdited(true);
                                                    setSlug(
                                                        event.target.value.toLowerCase(),
                                                    );
                                                }}
                                            />
                                            <span className="hidden max-w-[45%] items-center truncate border-l bg-muted px-3 text-sm text-muted-foreground sm:flex">
                                                .{rootDomain}
                                            </span>
                                        </div>
                                        <p className="text-xs text-muted-foreground sm:hidden">
                                            Ends in .{rootDomain}
                                        </p>
                                        <SlugStatus
                                            availability={availability}
                                            host={`${slug}.${rootDomain}`}
                                        />
                                        <InputError message={errors.slug} />
                                    </div>
                                </Section>

                                {trial.applications.length > 0 && (
                                    <Section
                                        step={3}
                                        icon={LayoutGrid}
                                        title="Applications to try"
                                        description={`Pick any you want to try, free for ${trial.days} days. No payment details needed, and you can add more later.`}
                                        aside={
                                            <span className="hidden shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary sm:inline">
                                                {picked.length} selected
                                            </span>
                                        }
                                    >
                                        <fieldset>
                                            <legend className="sr-only">
                                                Applications to try free
                                            </legend>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {trial.applications.map(
                                                    (app) => (
                                                        <AppChoice
                                                            key={app.code}
                                                            app={app}
                                                            checked={picked.includes(
                                                                app.code,
                                                            )}
                                                            onToggle={() =>
                                                                toggle(app.code)
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </div>
                                            <InputError
                                                className="mt-2"
                                                message={
                                                    errors.trial_applications ??
                                                    Object.entries(errors).find(
                                                        ([key]) =>
                                                            key.startsWith(
                                                                'trial_applications.',
                                                            ),
                                                    )?.[1]
                                                }
                                            />
                                        </fieldset>
                                    </Section>
                                )}

                                <Section
                                    step={trial.applications.length > 0 ? 4 : 3}
                                    icon={MapPin}
                                    title="Country"
                                    description="Sets your currency and time zone. Change them later in settings."
                                >
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="country_code">
                                                Country
                                            </Label>
                                            <select
                                                id="country_code"
                                                name="country_code"
                                                required
                                                value={country.code}
                                                onChange={(event) =>
                                                    chooseCountry(
                                                        event.target.value,
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {eastAfrica.map((option) => (
                                                    <option
                                                        key={option.code}
                                                        value={option.code}
                                                    >
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.country_code}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="default_currency">
                                                Currency
                                            </Label>
                                            <select
                                                id="default_currency"
                                                name="default_currency"
                                                required
                                                value={currency}
                                                onChange={(event) =>
                                                    setCurrency(
                                                        event.target.value,
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {currenciesFor(country).map(
                                                    (code) => (
                                                        <option
                                                            key={code}
                                                            value={code}
                                                        >
                                                            {code} ·{' '}
                                                            {currencyNames[
                                                                code
                                                            ] ?? code}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    errors.default_currency
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="timezone">
                                                Time zone
                                            </Label>
                                            <select
                                                id="timezone"
                                                name="timezone"
                                                required
                                                value={timezone}
                                                onChange={(event) =>
                                                    setTimezone(
                                                        event.target.value,
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {country.zones.map((zone) => (
                                                    <option
                                                        key={zone.id}
                                                        value={zone.id}
                                                    >
                                                        {zone.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.timezone}
                                            />
                                        </div>
                                    </div>
                                </Section>
                            </div>

                            <Summary
                                name={displayName}
                                host={`${slug || 'your-business'}.${rootDomain}`}
                                apps={trial.applications.filter((app) =>
                                    picked.includes(app.code),
                                )}
                                days={trial.days}
                                processing={processing}
                                blocked={availability.state === 'unavailable'}
                            />
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}

/** One numbered part of the form, on a card. */
function Section({
    step,
    icon: Icon,
    title,
    description,
    aside,
    children,
}: {
    step: number;
    icon: typeof Building2;
    title: string;
    description: string;
    aside?: ReactNode;
    children: ReactNode;
}) {
    const id = `section-${step}`;

    return (
        <section
            aria-labelledby={id}
            className="rounded-xl border bg-card p-5 shadow-xs sm:p-6"
        >
            <div className="mb-5 flex items-start gap-4">
                <span className="relative inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="size-5" aria-hidden />
                    <span
                        aria-hidden
                        className="absolute -top-1.5 -right-1.5 inline-flex size-5 items-center justify-center rounded-full bg-primary text-[0.6875rem] font-bold text-primary-foreground ring-2 ring-card"
                    >
                        {step}
                    </span>
                </span>
                <div className="min-w-0 flex-1">
                    <h2 id={id} className="font-semibold">
                        {title}
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

/** An application to try: a card that ticks on and off. */
function AppChoice({
    app,
    checked,
    onToggle,
}: {
    app: Props['trial']['applications'][number];
    checked: boolean;
    onToggle: () => void;
}) {
    const Icon = appIcon(app.code);

    return (
        <label className="group relative flex cursor-pointer gap-3 rounded-lg border bg-background p-4 transition-[border-color,background-color,box-shadow] hover:border-primary/50 has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:checked]:shadow-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring">
            <input
                type="checkbox"
                name="trial_applications[]"
                value={app.code}
                checked={checked}
                onChange={onToggle}
                className="peer sr-only"
            />
            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-has-[:checked]:bg-primary group-has-[:checked]:text-primary-foreground">
                <Icon className="size-5" aria-hidden />
            </span>
            <span className="min-w-0 flex-1 pr-6">
                <span className="block font-medium">{app.name}</span>
                {app.tagline && (
                    <span className="mt-0.5 block text-sm leading-snug text-muted-foreground">
                        {app.tagline}
                    </span>
                )}
            </span>
            <span
                aria-hidden
                className="absolute top-3 right-3 inline-flex size-5 items-center justify-center rounded-full border-2 border-muted-foreground/30 text-transparent transition-colors group-has-[:checked]:border-primary group-has-[:checked]:bg-primary group-has-[:checked]:text-primary-foreground"
            >
                <Check className="size-3" strokeWidth={3.5} />
            </span>
        </label>
    );
}

/** The new workspace as it will be, and the button that creates it. */
function Summary({
    name,
    host,
    apps,
    days,
    processing,
    blocked,
}: {
    name: string;
    host: string;
    apps: Props['trial']['applications'];
    days: number;
    processing: boolean;
    blocked: boolean;
}) {
    return (
        <aside
            aria-label="Summary"
            className="overflow-hidden rounded-xl border bg-card shadow-sm lg:sticky lg:top-6"
        >
            <div className="relative overflow-hidden bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16] p-5 text-white">
                <span
                    aria-hidden
                    className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.12)_1px,transparent_1.5px)] [background-size:14px_14px] [mask-image:linear-gradient(to_left,black,transparent_70%)]"
                />
                <p className="relative truncate text-lg font-semibold">
                    {name || 'Your new business'}
                </p>
                <p className="relative mt-3 flex items-center gap-2 truncate rounded-md bg-white/10 px-3 py-2 font-mono text-xs text-[#c7f36b] ring-1 ring-white/15">
                    <Globe className="size-3.5 shrink-0" aria-hidden />
                    <span className="truncate">{host}</span>
                </p>
            </div>

            <div className="space-y-4 p-5">
                <div>
                    <p className="text-sm font-medium">Free trials</p>
                    {apps.length === 0 ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            None yet. Pick applications to try, or add them
                            later.
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-2">
                            {apps.map((app) => {
                                const Icon = appIcon(app.code);

                                return (
                                    <li
                                        key={app.code}
                                        className="flex items-center gap-2.5 text-sm"
                                    >
                                        <span className="inline-flex size-7 items-center justify-center rounded-md bg-primary/10 text-primary">
                                            <Icon
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </span>
                                        <span className="flex-1 font-medium">
                                            {app.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {days} days
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <p className="flex items-start gap-2 rounded-md bg-muted px-3 py-2.5 text-xs text-muted-foreground">
                    <Sparkles
                        className="mt-px size-3.5 shrink-0 text-primary"
                        aria-hidden
                    />
                    No payment details needed. You become the owner and can
                    invite your team afterwards.
                </p>

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={processing || blocked}
                >
                    {processing && <Spinner />}
                    Create business
                </Button>
            </div>
        </aside>
    );
}

function SlugStatus({
    availability,
    host,
}: {
    availability: Availability;
    host: string;
}) {
    return (
        <p id="slug-status" className="min-h-5 text-sm" aria-live="polite">
            {availability.state === 'checking' && (
                <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                    <Spinner className="size-3.5" />
                    Checking {host}…
                </span>
            )}
            {availability.state === 'available' && (
                <span className="inline-flex items-center gap-1.5 text-green-700 dark:text-green-400">
                    <Check className="size-4" aria-hidden />
                    {host} is available
                </span>
            )}
            {availability.state === 'unavailable' && (
                <span className="inline-flex items-center gap-1.5 text-destructive">
                    <CircleAlert className="size-4" aria-hidden />
                    {availability.errors[0]}
                </span>
            )}
        </p>
    );
}

CreateOrganization.layout = {
    breadcrumbs: [
        { title: 'Your businesses', href: workspaces() },
        { title: 'New business', href: createOrganization() },
    ],
};
