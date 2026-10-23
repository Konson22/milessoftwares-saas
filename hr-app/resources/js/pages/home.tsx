import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate } from '@/components/fields';
import { show } from '@/routes/employees';
import { index as documents } from '@/routes/employees/documents';
import { index as leave } from '@/routes/leave';

type Props = {
    summary: {
        headcount: number;
        joinedThisMonth: number;
        leftThisMonth: number;
        soonDays: number;
        endingSoon: {
            employeeId: string;
            name: string;
            what: string;
            on: string;
        }[];
        /** Null without the documents permission. */
        documents:
            | {
                  id: string;
                  employeeId: string;
                  name: string;
                  title: string;
                  category: string;
                  expiresOn: string;
                  expiry: 'expired' | 'expiring';
              }[]
            | null;
        /** Null without the leave permission. */
        leave: { awayToday: number; waiting: number } | null;
    } | null;
};

export default function Home({ summary }: Props) {
    const { workspace, user } = usePage().props;

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={`HR for ${workspace?.name ?? ''}`}
                description={`Welcome, ${user?.name ?? ''}.`}
            />

            {summary === null ? (
                <p className="text-sm text-muted-foreground">
                    You have no HR role here yet. An administrator can give you
                    one in the workspace.
                </p>
            ) : (
                <>
                    <div className="mb-3 grid gap-3 sm:grid-cols-3">
                        <StatCard
                            label="Current staff"
                            value={summary.headcount}
                        />
                        <StatCard
                            label="Joined this month"
                            value={summary.joinedThisMonth}
                        />
                        <StatCard
                            label="Left this month"
                            value={summary.leftThisMonth}
                        />
                    </div>

                    {summary.leave !== null && (
                        <Panel className="mb-3">
                            <PanelHeader
                                title="Leave"
                                description={`${summary.leave.awayToday === 1 ? '1 person is' : `${summary.leave.awayToday} people are`} on leave today; ${summary.leave.waiting === 1 ? '1 request waits' : `${summary.leave.waiting} requests wait`} for a decision.`}
                                actions={
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={leave()}>Open leave</Link>
                                    </Button>
                                }
                            />
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader
                            title={`Ending in the next ${summary.soonDays} days`}
                            description="Contracts and probations."
                        />
                        {summary.endingSoon.length === 0 ? (
                            <p className="px-3 py-4 text-sm text-muted-foreground">
                                Nothing ends soon.
                            </p>
                        ) : (
                            <Table>
                                <TableBody>
                                    {summary.endingSoon.map((row) => (
                                        <TableRow
                                            key={`${row.employeeId}-${row.what}`}
                                        >
                                            <TableCell>
                                                <Link
                                                    href={show(row.employeeId)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{row.what}</TableCell>
                                            <TableCell className="tabular-nums">
                                                {formatDate(row.on)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>

                    {summary.documents !== null && (
                        <Panel className="mt-3">
                            <PanelHeader
                                title="Documents to renew"
                                description={`Expired, or expiring in the next ${summary.soonDays} days: permits, licences and other dated documents.`}
                            />
                            {summary.documents.length === 0 ? (
                                <p className="px-3 py-4 text-sm text-muted-foreground">
                                    No document expires soon.
                                </p>
                            ) : (
                                <Table>
                                    <TableBody>
                                        {summary.documents.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell>
                                                    <Link
                                                        href={documents(
                                                            row.employeeId,
                                                        )}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {row.title}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {row.category}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatDate(row.expiresOn)}
                                                </TableCell>
                                                <TableCell>
                                                    {row.expiry ===
                                                    'expired' ? (
                                                        <StatusBadge tone="danger">
                                                            Expired
                                                        </StatusBadge>
                                                    ) : (
                                                        <StatusBadge tone="warning">
                                                            Expires soon
                                                        </StatusBadge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Panel>
                    )}
                </>
            )}
        </>
    );
}
