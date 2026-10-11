import { Form, Head, Link } from '@inertiajs/react';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';

type Organization = {
    id: string;
    displayName: string;
    slug: string | null;
    status: string;
    createdAt: string | null;
    members: number;
};

type Props = {
    search: string;
    organizations: {
        data: Organization[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function OrganizationsIndex({ search, organizations }: Props) {
    return (
        <>
            <Head title="Organizations" />

            <h1 className="text-2xl font-semibold">Organizations</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Directory information only. Members and other customer data need
                approved support access.
            </p>

            <Form
                action="/organizations"
                method="get"
                className="mt-6 flex max-w-md gap-2"
            >
                <Input
                    name="search"
                    defaultValue={search}
                    placeholder="Name or workspace address"
                    aria-label="Search organizations"
                />
                <Button type="submit" variant="outline">
                    Search
                </Button>
            </Form>

            <div className="mt-6 overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium">Organization</th>
                            <th className="p-3 font-medium">Address</th>
                            <th className="p-3 font-medium">Status</th>
                            <th className="p-3 text-right font-medium">
                                Members
                            </th>
                            <th className="p-3 font-medium">Created</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {organizations.data.map((organization) => (
                            <tr key={organization.id}>
                                <td className="p-3">
                                    <Link
                                        href={`/organizations/${organization.id}`}
                                        className="font-medium underline-offset-2 hover:underline"
                                    >
                                        {organization.displayName}
                                    </Link>
                                </td>
                                <td className="p-3 text-muted-foreground">
                                    {organization.slug}
                                </td>
                                <td className="p-3">
                                    <Badge
                                        variant={
                                            organization.status === 'active'
                                                ? 'secondary'
                                                : 'destructive'
                                        }
                                    >
                                        {organization.status}
                                    </Badge>
                                </td>
                                <td className="p-3 text-right tabular-nums">
                                    {organization.members}
                                </td>
                                <td className="p-3 text-muted-foreground">
                                    {organization.createdAt}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <nav className="mt-4 flex flex-wrap gap-1" aria-label="Pages">
                {organizations.links.map((link) =>
                    link.url ? (
                        <Link
                            key={link.label}
                            href={link.url}
                            className={`rounded px-2 py-1 text-sm ${link.active ? 'bg-muted font-medium' : ''}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : null,
                )}
            </nav>
        </>
    );
}
