import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { formatDate, statusLabel, statusTone } from '@/lib/status';

type Grant = {
    id: string;
    reference: string;
    title: string;
    status: string;
    currency: string;
    amountMinor: number;
    receivedMinor: number;
    startOn: string | null;
    endOn: string | null;
    reportsOwed: number;
    url: string;
};

/**
 * The grants shared with someone acting for a funder; or, for an organization that funds others, with
 * a donor organization by one of its beneficiaries.
 */
export default function DonorHome({
    funder,
    grants,
    beneficiary,
}: {
    funder: string;
    grants: Grant[];
    beneficiary?: { name: string; label: string; back: string };
}) {
    return (
        <>
            <Head title={beneficiary?.name ?? 'Your grants'} />
            <PageHeader
                title={beneficiary?.name ?? 'Your grants'}
                description={
                    beneficiary
                        ? `${beneficiary.label}. The grants it shares with ${funder}.`
                        : `Shared with you on behalf of ${funder}.`
                }
                actions={
                    beneficiary && (
                        <Link
                            href={beneficiary.back}
                            className="text-sm underline-offset-2 hover:underline"
                        >
                            All beneficiaries
                        </Link>
                    )
                }
            />
            <Panel>
                {grants.length === 0 ? (
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            No grants have been shared with you yet.
                        </p>
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Grant</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Period</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="text-right">
                                    Paid
                                </TableHead>
                                <TableHead className="text-right">
                                    Reports owed
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {grants.map((grant) => (
                                <TableRow key={grant.id}>
                                    <TableCell>
                                        <Link
                                            href={grant.url}
                                            className="font-medium underline-offset-2 hover:underline"
                                        >
                                            {grant.reference}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {grant.title}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(grant.status)}
                                        >
                                            {statusLabel(grant.status)}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell>
                                        {grant.startOn
                                            ? formatDate(grant.startOn)
                                            : 'Not set'}
                                        {grant.endOn &&
                                            ` to ${formatDate(grant.endOn)}`}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            grant.amountMinor,
                                            grant.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            grant.receivedMinor,
                                            grant.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {grant.reportsOwed}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
