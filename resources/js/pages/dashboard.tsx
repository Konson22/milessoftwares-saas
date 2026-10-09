import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import OrganizationController from '@/actions/App/Http/Controllers/Dashboard/OrganizationController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { settings as organizationSettings } from '@/routes/organization';
import { create as createOrganization } from '@/routes/organizations';

type Organization = {
    id: string;
    displayName: string;
    status: 'active' | 'suspended';
    workspaceUrl: string | null;
    isOwner: boolean;
    isCurrent: boolean;
};

type Props = {
    organizations: Organization[];
};

export default function Dashboard({ organizations }: Props) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Your organizations"
                        description="Workspaces you belong to. Open one to use its applications."
                    />
                    <Button asChild>
                        <Link href={createOrganization()}>
                            <Plus /> New organization
                        </Link>
                    </Button>
                </div>

                {organizations.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <Building2
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="mt-3 font-medium">
                            You are not part of an organization yet
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Create one for your business, or ask a colleague to
                            invite you.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {organizations.map((organization) => (
                            <OrganizationCard
                                key={organization.id}
                                organization={organization}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function OrganizationCard({ organization }: { organization: Organization }) {
    return (
        <li className="flex flex-col gap-4 rounded-xl border p-4">
            <div className="flex items-start justify-between gap-2">
                <p className="font-medium">{organization.displayName}</p>
                <div className="flex gap-1">
                    {organization.isCurrent && <Badge>Current</Badge>}
                    {organization.status === 'suspended' && (
                        <Badge variant="destructive">Suspended</Badge>
                    )}
                    {organization.isOwner && (
                        <Badge variant="secondary">Owner</Badge>
                    )}
                </div>
            </div>

            <div className="mt-auto flex flex-wrap gap-2">
                {organization.workspaceUrl && (
                    <Button asChild size="sm">
                        <a href={organization.workspaceUrl}>Open workspace</a>
                    </Button>
                )}

                {!organization.isCurrent && (
                    <Form
                        {...OrganizationController.switch.form(organization.id)}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                Make current
                            </Button>
                        )}
                    </Form>
                )}

                {organization.isCurrent && organization.isOwner && (
                    <Button asChild size="sm" variant="outline">
                        <Link href={organizationSettings()}>Settings</Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
