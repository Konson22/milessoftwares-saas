import { Head, Link } from '@inertiajs/react';
import { Plus, UserRound } from 'lucide-react';
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
import { create, edit } from '@/routes/staff';

type Member = {
    id: string;
    number: string;
    name: string;
    position: string;
    department: string | null;
    phone: string | null;
    email: string | null;
    teaches: number;
    classTeacherOf: string | null;
    active: boolean;
};

/**
 * Teachers, lecturers and other staff, and what they teach.
 */
export default function Staff({
    staff,
    canManage,
}: {
    staff: Member[];
    canManage: boolean;
}) {
    const school = useSchool();
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add staff
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Staff" />
            <PageHeader
                title="Staff"
                description={`${school.staff}s and everyone else who works at the school. HR keeps their pay and personal records.`}
                actions={add}
            />

            <Panel>
                {staff.length === 0 ? (
                    <EmptyState
                        icon={UserRound}
                        title="No staff yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Position</TableHead>
                                {school.usesDepartments && (
                                    <TableHead>Department</TableHead>
                                )}
                                <TableHead>Contact</TableHead>
                                <TableHead className="text-right">
                                    Teaches ({plural(school.subject)})
                                </TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {staff.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {member.name}
                                        </span>
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            {member.number}
                                        </span>
                                        {!member.active && (
                                            <StatusBadge className="ml-2">
                                                Left
                                            </StatusBadge>
                                        )}
                                        {member.classTeacherOf && (
                                            <p className="text-xs text-muted-foreground">
                                                Class teacher of{' '}
                                                {member.classTeacherOf}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell>{member.position}</TableCell>
                                    {school.usesDepartments && (
                                        <TableCell className="text-muted-foreground">
                                            {member.department}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-muted-foreground">
                                        {[member.phone, member.email]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {member.teaches}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(member.id)}>
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
