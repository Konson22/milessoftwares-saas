import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { Status } from '@/components/status';
import { useSchool } from '@/lib/school';
import { create, index, show } from '@/routes/students';
import type { Option } from '@/types';

type Student = {
    id: string;
    number: string;
    name: string;
    gender: string;
    class: string | null;
    contact: string | null;
    status: string;
};

type Props = {
    students: Student[];
    filters: { search: string; class: string; status: string };
    classes: Option[];
    canManage: boolean;
};

const STATUSES: Option[] = [
    { value: 'active', label: 'Enrolled' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'withdrawn', label: 'Withdrawn' },
    { value: 'graduated', label: 'Graduated' },
];

export default function Students({
    students,
    filters,
    classes,
    canManage,
}: Props) {
    const school = useSchool();
    const pupils = school.value === 'primary' ? 'Pupils' : 'Students';
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Enrol a {pupils.toLowerCase().slice(0, -1)}
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title={pupils} />
            <PageHeader
                title={pupils}
                description={`${students.length} shown`}
                actions={add}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex flex-wrap gap-2">
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, number or phone"
                            aria-label={`Search ${pupils.toLowerCase()}`}
                        />
                        <Select
                            name="class"
                            aria-label="Class"
                            defaultValue={filters.class}
                            placeholder="Every class"
                            options={classes}
                            className="w-auto"
                        />
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            options={STATUSES}
                            className="w-auto"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {students.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={`No ${pupils.toLowerCase()} found`}
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{school.studentNumber}</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Class</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {students.map((student) => (
                                <TableRow key={student.id}>
                                    <TableCell className="font-mono text-xs">
                                        {student.number}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(student.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {student.name}
                                        </Link>
                                        <span className="ml-2 text-xs text-muted-foreground capitalize">
                                            {student.gender}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {student.class ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {student.contact}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={student.status} />
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
