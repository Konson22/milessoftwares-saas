import { Form, Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import OrganizationController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationController';
import Heading from '@/shared/components/heading';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';
import { dashboard } from '@/routes';
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

export default function CreateOrganization({
    rootDomain,
    defaults,
    trial,
}: Props) {
    const [displayName, setDisplayName] = useState('');
    const [slug, setSlug] = useState('');
    const [slugEdited, setSlugEdited] = useState(false);
    const availability = useSlugAvailability(slug);

    return (
        <>
            <Head title="New business" />

            <div className="max-w-xl space-y-3">
                <Heading
                    title="New business"
                    description="Your business gets its own workspace address. You become its owner."
                />

                <Form
                    {...OrganizationController.store.form()}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="display_name">
                                    Business name
                                </Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    required
                                    autoFocus
                                    value={displayName}
                                    onChange={(event) => {
                                        setDisplayName(event.target.value);

                                        if (!slugEdited) {
                                            setSlug(
                                                suggestSlug(event.target.value),
                                            );
                                        }
                                    }}
                                />
                                <InputError message={errors.display_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="legal_name">
                                    Registered legal name
                                </Label>
                                <Input
                                    id="legal_name"
                                    name="legal_name"
                                    required
                                />
                                <InputError message={errors.legal_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="slug">Workspace address</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="slug"
                                        name="slug"
                                        required
                                        value={slug}
                                        autoComplete="off"
                                        spellCheck={false}
                                        aria-describedby="slug-status"
                                        onChange={(event) => {
                                            setSlugEdited(true);
                                            setSlug(
                                                event.target.value.toLowerCase(),
                                            );
                                        }}
                                    />
                                    <span className="shrink-0 text-sm text-muted-foreground">
                                        .{rootDomain}
                                    </span>
                                </div>
                                <SlugStatus
                                    availability={availability}
                                    host={`${slug}.${rootDomain}`}
                                />
                                <InputError message={errors.slug} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="country_code">
                                        Country
                                    </Label>
                                    <Input
                                        id="country_code"
                                        name="country_code"
                                        defaultValue={defaults.country_code}
                                        maxLength={2}
                                        required
                                    />
                                    <InputError message={errors.country_code} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="default_currency">
                                        Currency
                                    </Label>
                                    <Input
                                        id="default_currency"
                                        name="default_currency"
                                        defaultValue={defaults.default_currency}
                                        maxLength={3}
                                        required
                                    />
                                    <InputError
                                        message={errors.default_currency}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="timezone">Time zone</Label>
                                    <Input
                                        id="timezone"
                                        name="timezone"
                                        defaultValue={defaults.timezone}
                                        required
                                    />
                                    <InputError message={errors.timezone} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contact_email">
                                    Contact email (optional)
                                </Label>
                                <Input
                                    id="contact_email"
                                    name="contact_email"
                                    type="email"
                                />
                                <InputError message={errors.contact_email} />
                            </div>

                            {trial.applications.length > 0 && (
                                <TrialChoice
                                    trial={trial}
                                    error={errors.trial_application}
                                />
                            )}

                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    availability.state === 'unavailable'
                                }
                            >
                                {processing && <Spinner />}
                                Create business
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function TrialChoice({
    trial,
    error,
}: {
    trial: Props['trial'];
    error?: string;
}) {
    const options = [
        ...trial.applications,
        { code: '', name: 'Not now', tagline: 'Add applications later.' },
    ];

    return (
        <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">
                Free trial ({trial.days} days)
            </legend>
            <p className="text-sm text-muted-foreground">
                Choose an application to try free. No payment details are
                needed.
            </p>
            <div className="mt-1 grid gap-2 sm:grid-cols-2">
                {options.map((option) => (
                    <label
                        key={option.code || 'none'}
                        className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                    >
                        <input
                            type="radio"
                            name="trial_application"
                            value={option.code}
                            defaultChecked={
                                option.code === (trial.selected ?? '')
                            }
                            className="mt-0.5 accent-primary"
                        />
                        <span>
                            <span className="block font-medium">
                                {option.name}
                            </span>
                            {option.tagline && (
                                <span className="block text-muted-foreground">
                                    {option.tagline}
                                </span>
                            )}
                        </span>
                    </label>
                ))}
            </div>
            <InputError message={error} />
        </fieldset>
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
        <p id="slug-status" className="text-sm" aria-live="polite">
            {availability.state === 'checking' && (
                <span className="text-muted-foreground">Checking {host}…</span>
            )}
            {availability.state === 'available' && (
                <span className="text-green-700 dark:text-green-400">
                    {host} is available
                </span>
            )}
            {availability.state === 'unavailable' && (
                <span className="text-destructive">
                    {availability.errors[0]}
                </span>
            )}
        </p>
    );
}

CreateOrganization.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'New business', href: createOrganization() },
    ],
};
