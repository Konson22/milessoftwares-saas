import { Head, Link } from '@inertiajs/react';
import SupportAccessBanner from '@/components/admin/support-access-banner';

type Props = {
    organization: { id: string; displayName: string };
    grant: { expiresAt: string; scope: string };
    members: {
        id: string;
        name: string;
        email: string;
        status: string;
        joinedAt: string | null;
    }[];
};

export default function CustomerMembers({
    organization,
    grant,
    members,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} members`} />
            <SupportAccessBanner organization={organization} grant={grant} />

            <h1 className="mt-6 text-2xl font-semibold">Members</h1>
            <ul className="mt-4 divide-y rounded-lg border text-sm">
                {members.map((member) => (
                    <li key={member.id} className="flex items-center gap-4 p-3">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{member.name}</p>
                            <p className="truncate text-muted-foreground">
                                {member.email}
                            </p>
                        </div>
                        <span className="text-muted-foreground">
                            {member.status}
                        </span>
                        <span className="text-muted-foreground">
                            {member.joinedAt}
                        </span>
                    </li>
                ))}
            </ul>

            <Link
                href={`/organizations/${organization.id}`}
                className="mt-4 inline-block text-sm underline"
            >
                Back to {organization.displayName}
            </Link>
        </>
    );
}
