import { Head, Link } from '@inertiajs/react';
import SupportAccessBanner from '@/sites/admin/components/support-access-banner';

type Props = {
    organization: { id: string; displayName: string };
    grant: { expiresAt: string; scope: string };
    branches: { id: string; name: string; code: string; status: string }[];
    departments: {
        id: string;
        name: string;
        code: string;
        branch_id: string | null;
        status: string;
    }[];
};

export default function CustomerStructure({
    organization,
    grant,
    branches,
    departments,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} structure`} />
            <SupportAccessBanner organization={organization} grant={grant} />

            <div className="mt-6 grid gap-8 md:grid-cols-2">
                <section>
                    <h2 className="text-lg font-medium">Branches</h2>
                    <ul className="mt-3 divide-y rounded-lg border text-sm">
                        {branches.map((branch) => (
                            <li key={branch.id} className="p-3">
                                {branch.name}{' '}
                                <span className="text-muted-foreground">
                                    · {branch.code} · {branch.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
                <section>
                    <h2 className="text-lg font-medium">Departments</h2>
                    <ul className="mt-3 divide-y rounded-lg border text-sm">
                        {departments.map((department) => (
                            <li key={department.id} className="p-3">
                                {department.name}{' '}
                                <span className="text-muted-foreground">
                                    · {department.code} ·{' '}
                                    {branches.find(
                                        (branch) =>
                                            branch.id === department.branch_id,
                                    )?.name ?? 'all branches'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            <Link
                href={`/organizations/${organization.id}`}
                className="mt-4 inline-block text-sm underline"
            >
                Back to {organization.displayName}
            </Link>
        </>
    );
}
