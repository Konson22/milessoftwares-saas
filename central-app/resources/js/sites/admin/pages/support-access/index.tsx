import { Head, Link, router, usePage } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';

type Grant = {
    id: string;
    organization: { id: string; name: string };
    requestedBy: string;
    approvedBy: string | null;
    reason: string;
    ticket: string | null;
    scope: string;
    status: string;
    expiresAt: string;
    isMine: boolean;
};

type Props = {
    mine: Grant[];
    awaitingApproval: Grant[];
    canApprove: boolean;
};

export default function SupportAccessIndex({
    mine,
    awaitingApproval,
    canApprove,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <>
            <Head title="Support access" />

            <h1 className="text-2xl font-semibold">Support access</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Request access from an organization&apos;s page. Requests need
                another staff member&apos;s approval and end after at most 72
                hours.
            </p>
            <InputError message={errors.support} className="mt-2" />

            {canApprove && (
                <section className="mt-5">
                    <h2 className="text-lg font-medium">
                        Awaiting your approval
                    </h2>
                    <GrantList
                        grants={awaitingApproval}
                        empty="Nothing to approve."
                        approvable
                    />
                </section>
            )}

            <section className="mt-5">
                <h2 className="text-lg font-medium">Your requests</h2>
                <GrantList
                    grants={mine}
                    empty="You have not requested support access."
                />
            </section>
        </>
    );
}

function GrantList({
    grants,
    empty,
    approvable = false,
}: {
    grants: Grant[];
    empty: string;
    approvable?: boolean;
}) {
    if (grants.length === 0) {
        return <p className="mt-3 text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="mt-3 divide-y rounded-lg border text-sm">
            {grants.map((grant) => (
                <li
                    key={grant.id}
                    className="flex flex-wrap items-center gap-3 p-3"
                >
                    <Badge variant="outline">{grant.status}</Badge>
                    <div className="min-w-0 flex-1">
                        <Link
                            href={`/organizations/${grant.organization.id}`}
                            className="font-medium underline-offset-2 hover:underline"
                        >
                            {grant.organization.name}
                        </Link>
                        <p className="text-muted-foreground">
                            {grant.requestedBy} · {grant.reason}
                            {grant.ticket && ` · ${grant.ticket}`} ·{' '}
                            {grant.scope} · until{' '}
                            {new Date(grant.expiresAt).toLocaleString()}
                        </p>
                    </div>
                    {approvable && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/support-access/${grant.id}/approve`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Approve
                        </Button>
                    )}
                    {['pending', 'active', 'scheduled'].includes(
                        grant.status,
                    ) &&
                        (grant.isMine || approvable) && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.post(
                                        `/support-access/${grant.id}/revoke`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {grant.isMine ? 'Withdraw' : 'Reject'}
                            </Button>
                        )}
                </li>
            ))}
        </ul>
    );
}
