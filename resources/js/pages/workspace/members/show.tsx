import { Head, Link } from '@inertiajs/react';
import type { Member } from './index';

type Props = {
    member: Member;
};

export default function MemberDetail({ member }: Props) {
    return (
        <>
            <Head title={member.name} />

            <Link
                href="/members"
                className="text-sm text-muted-foreground underline"
            >
                All members
            </Link>

            <h1 className="mt-4 text-2xl font-semibold">{member.name}</h1>

            <dl className="mt-6 grid max-w-md grid-cols-[8rem_1fr] gap-y-2 text-sm">
                <dt className="text-muted-foreground">Email</dt>
                <dd>{member.email}</dd>
                <dt className="text-muted-foreground">Status</dt>
                <dd className="capitalize">{member.status}</dd>
                <dt className="text-muted-foreground">Joined</dt>
                <dd>{member.joinedAt ?? '—'}</dd>
            </dl>
        </>
    );
}
