import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import {
    Table,
    TableBody,
    TableCell,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate } from '@/components/fields';
import { show } from '@/routes/employees';

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
                </>
            )}
        </>
    );
}
