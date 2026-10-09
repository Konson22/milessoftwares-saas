import { Form, Head } from '@inertiajs/react';
import OrganizationSettingsController from '@/actions/App/Http/Controllers/Dashboard/OrganizationSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { settings as organizationSettings } from '@/routes/organization';

type Props = {
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
    organization,
    rootDomain,
    redirectGraceDays,
    previousSlugs,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} settings`} />

            <div className="max-w-xl space-y-10 p-4">
                <Heading
                    title={organization.displayName}
                    description="Organization settings"
                />

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
