import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/shared/components/ui/badge';

export type Member = {
    id: string;
    name: string;
    email: string;
    status: 'active' | 'suspended' | 'removed';
    joinedAt: string | null;
};

type Props = {
    members: Member[];
};

export default function Members({ members }: Props) {
    return (
        <>
            <Head title="Members" />

            <h1 className="text-2xl font-semibold">Members</h1>

            <ul className="mt-6 divide-y rounded-lg border">
                {members.map((member) => (
                    <li key={member.id}>
                        <Link
                            href={`/members/${member.id}`}
                            className="flex items-center gap-4 p-4 hover:bg-muted/50"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">
                                    {member.name}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {member.email}
                                </p>
                            </div>
                            {member.status !== 'active' && (
                                <Badge variant="secondary">
                                    {member.status}
                                </Badge>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </>
    );
}
