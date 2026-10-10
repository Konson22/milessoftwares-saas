import { Head, Link, router, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { Member } from './index';

type Props = {
    member: Member;
};

export default function MemberDetail({ member }: Props) {
    const { abilities, errors } = usePage().props as {
        abilities: { manageAccess: boolean } | null;
        errors: Record<string, string>;
    };

    const setStatus = (status: Member['status']) =>
        router.put(
            `/manage/members/${member.id}/status`,
            { status },
            { preserveScroll: true },
        );

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

            {abilities?.manageAccess && member.status !== 'removed' && (
                <div className="mt-8 flex flex-wrap gap-2">
                    {member.status === 'active' ? (
                        <Button
                            variant="outline"
                            onClick={() => setStatus('suspended')}
                        >
                            Suspend
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() => setStatus('active')}
                        >
                            Reactivate
                        </Button>
                    )}
                    <Button
                        variant="destructive"
                        onClick={() => setStatus('removed')}
                    >
                        Remove from organization
                    </Button>
                </div>
            )}
            <InputError message={errors.status} className="mt-2" />
        </>
    );
}
