import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import { commitments as commitmentsRoute } from '@/routes';
import { index as projectExpenses } from '@/routes/projects/expenses';

type Props = {
    byCurrency: {
        currency: string;
        count: number;
        totalMinor: number;
        buckets: { label: string; amountMinor: number }[];
    }[];
    projects: {
        id: string;
        code: string;
        name: string;
        currency: string;
        budgetMinor: number | null;
        committedMinor: number;
        actualMinor: number;
        leftMinor: number | null;
    }[];
    commitments: {
        id: string;
        projectId: string;
        projectCode: string;
        category: string;
        description: string;
        supplier: string | null;
        order: string | null;
        grant: string | null;
        amountMinor: number;
        currency: string;
        approvedOn: string;
        approvedBy: string | null;
        ageDays: number;
    }[];
    projectFilter: string | null;
};

/**
 * Spending approved and not yet paid, across the organization. It holds budget
 * but is not in the books until it is paid.
 */
export default function CommitmentsPage({
    byCurrency,
    projects,
    commitments,
    projectFilter,
}: Props) {
    return (
        <>
            <Head title="Commitments" />
            <PageHeader
                title="Commitments"
                description="Spending approved and not yet paid. It holds budget, but reaches the books only when it is paid."
            />

            {byCurrency.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={Banknote}
                        title="Nothing is committed right now"
                    />
                </Panel>
            ) : (
                <div className="grid gap-4">
                    {byCurrency.map((group) => (
                        <Panel key={group.currency}>
                            <PanelHeader
                                title={`${formatMoney(group.totalMinor, group.currency)} committed`}
                                description={`${group.count} unpaid, by time since approval`}
                            />
                            <PanelBody>
                                <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                                    {group.buckets.map((bucket) => (
                                        <div key={bucket.label}>
                                            <dt className="text-muted-foreground">
                                                {bucket.label}
                                            </dt>
                                            <dd className="font-medium tabular-nums">
                                                {formatMoney(
                                                    bucket.amountMinor,
                                                    group.currency,
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </PanelBody>
                        </Panel>
                    ))}

                    <Panel>
                        <PanelHeader title="By project" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Project</TableHead>
                                    <TableHead className="text-right">
                                        Approved budget
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Committed
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Spent
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Left
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {projects.map((project) => (
                                    <TableRow key={project.id}>
                                        <TableCell>
                                            <Link
                                                href={
                                                    commitmentsRoute({
                                                        query: {
                                                            project: project.id,
                                                        },
                                                    }).url
                                                }
                                                className="font-medium hover:underline"
                                            >
                                                {project.code}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {project.name}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {project.budgetMinor === null
                                                ? 'None'
                                                : formatMoney(
                                                      project.budgetMinor,
                                                      project.currency,
                                                  )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                project.committedMinor,
                                                project.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                project.actualMinor,
                                                project.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {project.leftMinor === null ? (
                                                '—'
                                            ) : (
                                                <span
                                                    className={
                                                        project.leftMinor < 0
                                                            ? 'font-medium text-destructive'
                                                            : undefined
                                                    }
                                                >
                                                    {formatMoney(
                                                        project.leftMinor,
                                                        project.currency,
                                                    )}
                                                    {project.leftMinor < 0 &&
                                                        ' over'}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title={
                                projectFilter
                                    ? "One project's commitments"
                                    : 'All commitments, oldest first'
                            }
                            actions={
                                projectFilter && (
                                    <Link
                                        href={commitmentsRoute().url}
                                        className="text-sm underline"
                                    >
                                        Show all projects
                                    </Link>
                                )
                            }
                        />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>For</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Approved</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {commitments.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            {item.description}
                                            <p className="text-xs text-muted-foreground">
                                                {[
                                                    item.category,
                                                    item.supplier,
                                                    item.order,
                                                    item.grant,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={projectExpenses(
                                                    item.projectId,
                                                )}
                                                className="hover:underline"
                                            >
                                                {item.projectCode}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(item.approvedOn)}
                                            {item.approvedBy &&
                                                `, ${item.approvedBy}`}
                                            <p className="text-xs">
                                                {item.ageDays} days ago
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                item.amountMinor,
                                                item.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                </div>
            )}
        </>
    );
}
