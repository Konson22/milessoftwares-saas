import { Form, Head } from '@inertiajs/react';
import OrganizationSettingsController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationSettingsController';
import Heading from '@/shared/components/heading';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { dashboard } from '@/routes';
import { settings as organizationSettings } from '@/routes/organization';

type Details = {
    display_name: string;
    legal_name: string;
    business_type: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    address: string | null;
    timezone: string;
    default_currency: string;
};

const detailFields: {
    name: keyof Details;
    label: string;
    type?: string;
    required?: boolean;
}[] = [
    { name: 'display_name', label: 'Business name', required: true },
    { name: 'legal_name', label: 'Registered legal name', required: true },
    { name: 'business_type', label: 'Business type' },
    { name: 'contact_email', label: 'Contact email', type: 'email' },
    { name: 'contact_phone', label: 'Contact phone', type: 'tel' },
    { name: 'address', label: 'Address' },
    { name: 'timezone', label: 'Time zone', required: true },
    { name: 'default_currency', label: 'Currency', required: true },
];

type Props = {
    details: Details;
    organization: {
        displayName: string;
        slug: string | null;
        workspaceUrl: string | null;
    };
    rootDomain: string;
    redirectGraceDays: number;
    previousSlugs: { slug: string; redirectsUntil: string | null }[];
};

export default function OrganizationSettings({
    details,
    organization,
    rootDomain,
    redirectGraceDays,
    previousSlugs,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} settings`} />

            <div className="max-w-xl space-y-10">
                <Heading
                    title={organization.displayName}
                    description="Organization settings"
                />

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Details"
                        description="How your organization appears to members and on documents."
                    />
                    <Form
                        action="/organization/details"
                        method="put"
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {detailFields.map((field) => (
                                    <div
                                        key={field.name}
                                        className="grid gap-2"
                                    >
                                        <Label htmlFor={field.name}>
                                            {field.label}
                                        </Label>
                                        <Input
                                            id={field.name}
                                            name={field.name}
                                            type={field.type ?? 'text'}
                                            required={field.required}
                                            defaultValue={
                                                details[field.name] ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors[field.name]}
                                        />
                                    </div>
                                ))}
                                <Button type="submit" disabled={processing}>
                                    Save details
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Workspace address"
                        description={`After a change, the old address keeps redirecting for ${redirectGraceDays} days and can never be taken by another organization.`}
                    />

                    {organization.workspaceUrl && (
                        <p className="text-sm">
                            Current:{' '}
                            <a
                                className="font-medium underline"
                                href={organization.workspaceUrl}
                            >
                                {organization.slug}.{rootDomain}
                            </a>
                        </p>
                    )}

                    <Form
                        {...OrganizationSettingsController.updateSlug.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="slug">New address</Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="slug"
                                            name="slug"
                                            required
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                        <span className="shrink-0 text-sm text-muted-foreground">
                                            .{rootDomain}
                                        </span>
                                    </div>
                                    <InputError message={errors.slug} />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Change address
                                </Button>
                            </>
                        )}
                    </Form>

                    {previousSlugs.length > 0 && (
                        <div className="space-y-1 text-sm text-muted-foreground">
                            <p className="font-medium text-foreground">
                                Previous addresses
                            </p>
                            <ul>
                                {previousSlugs.map((previous) => (
                                    <li key={previous.slug}>
                                        {previous.slug}.{rootDomain}
                                        {previous.redirectsUntil &&
                                            ` — redirects until ${previous.redirectsUntil}`}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

OrganizationSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Organization settings', href: organizationSettings() },
    ],
};
