import { Head, Link } from '@inertiajs/react';
import { GraduationCap, Plus } from 'lucide-react';
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
import { create, edit } from '@/routes/programmes';

type Programme = {
    id: string;
    code: string;
    name: string;
    award: string;
    durationYears: number;
    department: string | null;
    classes: number;
    students: number;
    active: boolean;
};

export default function Programmes({
    programmes,
    canManage,
}: {
    programmes: Programme[];
    canManage: boolean;
}) {
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a programme
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Programmes" />
            <PageHeader
                title="Programmes"
                description="Certificates, diplomas and degrees: each year of study is a class."
                actions={add}
            />

            <Panel>
                {programmes.length === 0 ? (
                    <EmptyState
                        icon={GraduationCap}
                        title="No programmes yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Programme</TableHead>
                                <TableHead>Award</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead className="text-right">
                                    Years
                                </TableHead>
                                <TableHead className="text-right">
                                    Classes
                                </TableHead>
                                <TableHead className="text-right">
                                    Students
                                </TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {programmes.map((programme) => (
                                <TableRow key={programme.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {programme.name}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {programme.code}
                                        </span>
                                        {!programme.active && (
                                            <StatusBadge className="ml-2">
                                                Closed
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>{programme.award}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {programme.department}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.durationYears}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.classes}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.students}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(programme.id)}>
                                                    Edit
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
