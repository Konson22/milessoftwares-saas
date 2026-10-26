import { Head, Link } from '@inertiajs/react';
import { ClipboardList, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { plural, useSchool } from '@/lib/school';
import { create, show } from '@/routes/classes';

type SchoolClass = {
    id: string;
    name: string;
    level: string;
    programme: string | null;
    classTeacher: string | null;
    enrolled: number;
    capacity: number | null;
    subjects: number;
    active: boolean;
};

/**
 * The school's classes: primary classes and streams, or each programme's
 * years of study.
 */
export default function Classes({
    classes,
    canManage,
}: {
    classes: SchoolClass[];
    canManage: boolean;
}) {
    const school = useSchool();
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a class
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Classes" />
            <PageHeader
                title="Classes"
                description={
                    school.usesProgrammes
                        ? 'Each programme’s years of study: where students sit, take registers and are marked.'
                        : 'Primary classes and their streams.'
                }
                actions={add}
            />

            <Panel>
                {classes.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No classes yet"
                        action={add}
                    >
                        {school.usesProgrammes
                            ? 'Adding a programme can add its classes for you.'
                            : undefined}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Class</TableHead>
                                <TableHead>{school.level}</TableHead>
                                {school.usesProgrammes && (
                                    <TableHead>Programme</TableHead>
                                )}
                                <TableHead>Class teacher</TableHead>
                                <TableHead className="text-right">
                                    {plural(school.subject).replace(/^./, (c) =>
                                        c.toUpperCase(),
                                    )}
                                </TableHead>
                                <TableHead className="text-right">
                                    Students
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {classes.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={show(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        {!row.active && (
                                            <StatusBadge className="ml-2">
                                                Closed
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>{row.level}</TableCell>
                                    {school.usesProgrammes && (
                                        <TableCell className="text-muted-foreground">
                                            {row.programme}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-muted-foreground">
                                        {row.classTeacher ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.subjects}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.enrolled}
                                        {row.capacity !== null && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                / {row.capacity}
                                            </span>
                                        )}
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
