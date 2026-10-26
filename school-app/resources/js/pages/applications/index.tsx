import { Head, Link } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { cn } from '@miles/ui/lib/utils';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import { create, index, show } from '@/routes/applications';

type Application = {
    id: string;
    number: string;
    name: string;
    applyingFor: string;
    contact: string | null;
    receivedOn: string | null;
    status: string;
};

type Props = {
    applications: Application[];
    filters: { status: string };
    counts: Record<string, number>;
    levelLabel: string;
    canManage: boolean;
};

const TABS = [
    { value: 'pending', label: 'Waiting' },
    { value: 'admitted', label: 'Admitted' },
    { value: 'declined', label: 'Declined' },
];

/**
 * Admissions: applications waiting for a decision, admitted or declined.
 */
export default function Applications({
    applications,
    filters,
    counts,
    canManage,
}: Props) {
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Receive an application
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Admissions" />
            <PageHeader
                title="Admissions"
                description="Applications to join the school, admitted into a class or declined."
                actions={add}
            />

            <Panel>
                <PanelToolbar>
                    <nav className="flex gap-1" aria-label="Applications">
                        {TABS.map((tab) => (
                            <Link
                                key={tab.value}
                                href={index({ query: { status: tab.value } })}
                                className={cn(
                                    'px-3 py-1.5 text-sm',
                                    filters.status === tab.value
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}{' '}
                                <span className="tabular-nums">
                                    ({counts[tab.value] ?? 0})
                                </span>
                            </Link>
                        ))}
                    </nav>
                </PanelToolbar>

                {applications.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title="No applications here"
                        action={filters.status === 'pending' ? add : undefined}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead>Applicant</TableHead>
                                <TableHead>Applying for</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {applications.map((application) => (
                                <TableRow key={application.id}>
                                    <TableCell>
                                        <Link
                                            href={show(application.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {application.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{application.name}</TableCell>
                                    <TableCell>
                                        {application.applyingFor}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {application.contact}
                                    </TableCell>
                                    <TableCell>
                                        {application.receivedOn &&
                                            formatDate(application.receivedOn)}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={application.status} />
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
