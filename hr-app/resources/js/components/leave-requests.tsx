import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate } from '@/components/fields';
import { show as balances } from '@/routes/employees/balances';
import { approve, cancel, reject } from '@/routes/leave';

export type LeaveRequestRow = {
    id: string;
    employeeId: string;
    name: string;
    number: string;
    type: string;
    paid: boolean;
    startsOn: string;
    endsOn: string;
    days: number;
    reason: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    statusLabel: string;
    requestedBy: string;
    requestedAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    cancelledBy: string | null;
    canDecide: boolean;
    decideBlocked: string | null;
    canCancel: boolean;
};

const TONES: Record<LeaveRequestRow['status'], Tone> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

export function days(count: number): string {
    return count === 1 ? '1 day' : `${count} days`;
}

/**
 * Leave requests with what the member may do to each: decide (with an
 * optional note) or cancel. The server checks every action again.
 */
export function LeaveRequestsTable({
    requests,
    showEmployee = true,
}: {
    requests: LeaveRequestRow[];
    showEmployee?: boolean;
}) {
    const { errors } = usePage().props;
    const [deciding, setDeciding] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const post = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                setDeciding(null);
                setCancelling(null);
                setNote('');
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            {errors.decision && (
                <p className="px-3 pt-3 text-sm text-destructive">
                    {errors.decision}
                </p>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        {showEmployee && <TableHead>Who</TableHead>}
                        <TableHead>Leave</TableHead>
                        <TableHead>Dates</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => (
                        <TableRow key={request.id} className="align-top">
                            {showEmployee && (
                                <TableCell>
                                    <Link
                                        href={balances(request.employeeId)}
                                        className="font-medium hover:underline"
                                    >
                                        {request.name}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {request.number}
                                    </span>
                                </TableCell>
                            )}
                            <TableCell>
                                {request.type}
                                {!request.paid && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        (unpaid)
                                    </span>
                                )}
                                {request.reason && (
                                    <span className="block text-xs text-muted-foreground">
                                        {request.reason}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {request.startsOn === request.endsOn
                                    ? formatDate(request.startsOn)
                                    : `${formatDate(request.startsOn)} – ${formatDate(request.endsOn)}`}
                                <span className="block text-xs text-muted-foreground">
                                    {days(request.days)}
                                </span>
                            </TableCell>
                            <TableCell>
                                <StatusBadge tone={TONES[request.status]}>
                                    {request.statusLabel}
                                </StatusBadge>
                                <span className="block text-xs text-muted-foreground">
                                    Recorded by {request.requestedBy}
                                    {request.decidedBy &&
                                        ` · ${request.status === 'approved' || request.cancelledBy ? 'approved' : 'rejected'} by ${request.decidedBy}`}
                                    {request.cancelledBy &&
                                        ` · cancelled by ${request.cancelledBy}`}
                                </span>
                                {request.decisionNote && (
                                    <span className="block text-xs text-muted-foreground">
                                        “{request.decisionNote}”
                                    </span>
                                )}
                                {request.decideBlocked && (
                                    <span className="block text-xs text-muted-foreground">
                                        {request.decideBlocked}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-right">
                                {deciding === request.id ? (
                                    <div className="ml-auto grid max-w-xs gap-2">
                                        <Input
                                            value={note}
                                            onChange={(event) =>
                                                setNote(event.target.value)
                                            }
                                            maxLength={255}
                                            placeholder="Note (optional)"
                                            aria-label="Note"
                                        />
                                        <span className="inline-flex justify-end gap-1">
                                            <Button
                                                size="sm"
                                                disabled={busy}
                                                onClick={() =>
                                                    post(
                                                        approve.url(request.id),
                                                        { note },
                                                    )
                                                }
                                            >
                                                Approve
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                disabled={busy}
                                                onClick={() =>
                                                    post(
                                                        reject.url(request.id),
                                                        { note },
                                                    )
                                                }
                                            >
                                                Reject
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setDeciding(null)
                                                }
                                            >
                                                Not now
                                            </Button>
                                        </span>
                                    </div>
                                ) : cancelling === request.id ? (
                                    <span className="inline-flex gap-1">
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            disabled={busy}
                                            onClick={() =>
                                                post(cancel.url(request.id))
                                            }
                                        >
                                            Cancel this leave
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setCancelling(null)}
                                        >
                                            Keep
                                        </Button>
                                    </span>
                                ) : (
                                    <span className="inline-flex gap-1">
                                        {request.canDecide && (
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    setNote('');
                                                    setDeciding(request.id);
                                                }}
                                            >
                                                Decide
                                                <span className="sr-only">
                                                    {' '}
                                                    on {request.name}’s leave
                                                </span>
                                            </Button>
                                        )}
                                        {request.canCancel && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setCancelling(request.id)
                                                }
                                            >
                                                Cancel
                                                <span className="sr-only">
                                                    {' '}
                                                    {request.name}’s leave
                                                </span>
                                            </Button>
                                        )}
                                    </span>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </>
    );
}
