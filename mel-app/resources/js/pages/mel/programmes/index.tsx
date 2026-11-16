import { Head, Link } from '@inertiajs/react';
import { FolderKanban } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { PROGRAMME_STATUS, formatDate } from '@/mel/lib/mel';
import { create, index, show } from '@/routes/mel/programmes';

type Props = {
    archived: boolean;
    programmes: {
        id: string;
        code: string;
        name: string;
        status: 'draft' | 'active' | 'closed';
        startsOn: string;
        endsOn: string;
        manager: string | null;
        indicators: number;
        units: number;
    }[];
    canCreate: boolean;
};

export default function Programmes({ archived, programmes, canCreate }: Props) {
    const add = canCreate ? (
        <Button asChild>
            <Link href={create()}>New programme</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Programmes" />
            <PageHeader title="Programmes" actions={add} />

            <Panel>
                <PanelToolbar>
                    <nav className="flex gap-1 text-sm" aria-label="Show">
                        <Link
                            href={index()}
                            className={`px-2 py-1 ${archived ? 'text-muted-foreground hover:text-foreground' : 'bg-muted font-medium'}`}
                        >
                            Current
                        </Link>
                        <Link
                            href={index({ query: { archived: 1 } })}
                            className={`px-2 py-1 ${archived ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            Archived
                        </Link>
                    </nav>
                </PanelToolbar>

                {programmes.length === 0 ? (
                    <EmptyState
                        icon={FolderKanban}
                        title={
                            archived
                                ? 'No archived programmes'
                                : 'No programmes yet'
                        }
                        action={archived ? undefined : add}
                    >
                        {archived
                            ? 'Archived programmes keep their results and reports.'
                            : canCreate
                              ? 'A programme holds its results framework, indicators, reporting units and periods.'
                              : 'Programmes you are given access to show here.'}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Programme</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Runs</TableHead>
                                <TableHead>Manager</TableHead>
                                <TableHead className="text-right">
                                    Units
                                </TableHead>
                                <TableHead className="text-right">
                                    Indicators
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {programmes.map((programme) => (
                                <TableRow key={programme.id}>
                                    <TableCell>
                                        <Link
                                            href={show(programme.id)}
                                            className="font-medium hover:underline"
                                        >
                                            <span className="text-muted-foreground">
                                                {programme.code}
                                            </span>{' '}
                                            {programme.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                PROGRAMME_STATUS[
                                                    programme.status
                                                ].tone
                                            }
                                        >
                                            {
                                                PROGRAMME_STATUS[
                                                    programme.status
                                                ].label
                                            }
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(programme.startsOn)} to{' '}
                                        {formatDate(programme.endsOn)}
                                    </TableCell>
                                    <TableCell>
                                        {programme.manager ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.units}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.indicators}
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
