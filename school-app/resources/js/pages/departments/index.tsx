import { Form, Head } from '@inertiajs/react';
import { Building2, Trash2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import {
    destroy as removeDepartment,
    store as addDepartment,
} from '@/routes/departments';
import {
    destroy as removeFaculty,
    store as addFaculty,
} from '@/routes/faculties';

type Faculty = {
    id: string;
    name: string;
    code: string | null;
    departments: number;
};

type Department = {
    id: string;
    name: string;
    code: string | null;
    faculty: string | null;
    programmes: number;
    staff: number;
    removable: boolean;
};

type Props = {
    faculties: Faculty[];
    departments: Department[];
    canManage: boolean;
};

/**
 * Faculties (universities) and departments: how programmes and staff are
 * organized.
 */
export default function Departments({
    faculties,
    departments,
    canManage,
}: Props) {
    const school = useSchool();
    const title = school.usesFaculties
        ? 'Faculties & departments'
        : 'Departments';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description="Departments offer programmes and are where staff work."
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <div className="grid content-start gap-4">
                    {school.usesFaculties && (
                        <Panel>
                            <PanelHeader title="Faculties" />
                            {faculties.length === 0 ? (
                                <EmptyState
                                    icon={Building2}
                                    title="No faculties yet"
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Faculty</TableHead>
                                            <TableHead className="text-right">
                                                Departments
                                            </TableHead>
                                            {canManage && <TableHead />}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {faculties.map((faculty) => (
                                            <TableRow key={faculty.id}>
                                                <TableCell className="font-medium">
                                                    {faculty.name}
                                                    {faculty.code && (
                                                        <span className="ml-2 text-muted-foreground">
                                                            {faculty.code}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {faculty.departments}
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-right">
                                                        {faculty.departments ===
                                                            0 && (
                                                            <Form
                                                                {...removeFaculty.form(
                                                                    faculty.id,
                                                                )}
                                                            >
                                                                <Button
                                                                    type="submit"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    aria-label={`Remove ${faculty.name}`}
                                                                >
                                                                    <Trash2
                                                                        className="size-4"
                                                                        aria-hidden
                                                                    />
                                                                </Button>
                                                            </Form>
                                                        )}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Departments" />
                        {departments.length === 0 ? (
                            <EmptyState
                                icon={Building2}
                                title="No departments yet"
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Department</TableHead>
                                        {school.usesFaculties && (
                                            <TableHead>Faculty</TableHead>
                                        )}
                                        <TableHead className="text-right">
                                            Programmes
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Staff
                                        </TableHead>
                                        {canManage && <TableHead />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {departments.map((department) => (
                                        <TableRow key={department.id}>
                                            <TableCell className="font-medium">
                                                {department.name}
                                                {department.code && (
                                                    <span className="ml-2 text-muted-foreground">
                                                        {department.code}
                                                    </span>
                                                )}
                                            </TableCell>
                                            {school.usesFaculties && (
                                                <TableCell className="text-muted-foreground">
                                                    {department.faculty}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-right tabular-nums">
                                                {department.programmes}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {department.staff}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell className="text-right">
                                                    {department.removable && (
                                                        <Form
                                                            {...removeDepartment.form(
                                                                department.id,
                                                            )}
                                                        >
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label={`Remove ${department.name}`}
                                                            >
                                                                <Trash2
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                            </Button>
                                                        </Form>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                </div>

                {canManage && (
                    <div className="grid content-start gap-4">
                        {school.usesFaculties && (
                            <Panel>
                                <PanelHeader title="Add a faculty" />
                                <PanelBody>
                                    <Form
                                        {...addFaculty.form()}
                                        className="grid gap-3"
                                        resetOnSuccess
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="faculty-name"
                                                    label="Name"
                                                    error={errors.name}
                                                >
                                                    <Input
                                                        id="faculty-name"
                                                        name="name"
                                                        placeholder="Faculty of Medicine"
                                                        required
                                                    />
                                                </Field>
                                                <Field
                                                    id="faculty-code"
                                                    label="Code"
                                                    error={errors.code}
                                                >
                                                    <Input
                                                        id="faculty-code"
                                                        name="code"
                                                        placeholder="FOM"
                                                    />
                                                </Field>
                                                <div>
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Add faculty
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>
                        )}
                        <Panel>
                            <PanelHeader title="Add a department" />
                            <PanelBody>
                                <Form
                                    {...addDepartment.form()}
                                    className="grid gap-3"
                                    resetOnSuccess
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            {school.usesFaculties && (
                                                <Field
                                                    id="faculty_id"
                                                    label="Faculty"
                                                    error={errors.faculty_id}
                                                >
                                                    <Select
                                                        id="faculty_id"
                                                        name="faculty_id"
                                                        required
                                                        placeholder="Choose a faculty"
                                                        options={faculties.map(
                                                            (faculty) => ({
                                                                value: faculty.id,
                                                                label: faculty.name,
                                                            }),
                                                        )}
                                                    />
                                                </Field>
                                            )}
                                            <Field
                                                id="department-name"
                                                label="Name"
                                                error={errors.name}
                                            >
                                                <Input
                                                    id="department-name"
                                                    name="name"
                                                    placeholder="Department of Nursing"
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="department-code"
                                                label="Code"
                                                error={errors.code}
                                            >
                                                <Input
                                                    id="department-code"
                                                    name="code"
                                                    placeholder="NUR"
                                                />
                                            </Field>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Add department
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    </div>
                )}
            </div>
        </>
    );
}
