import { Form, Head, Link } from '@inertiajs/react';
import { Layers } from 'lucide-react';
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
import { Field, invalid, Select } from '@/components/field';
import { show, store } from '@/routes/programmes';
import type { Option } from '@/types';

type Props = {
    programmes: {
        id: string;
        code: string;
        name: string;
        manager: string | null;
        projects: number;
        open: number;
        archived: boolean;
    }[];
    people: Option[];
    canManage: boolean;
};

/**
 * Programmes: groups of projects under one aim. A project needn't be in one.
 */
export default function Programmes({ programmes, people, canManage }: Props) {
    return (
        <>
            <Head title="Programmes" />
            <PageHeader
                title="Programmes"
                description="Group projects under one aim, and see how they stand together."
            />

            <div className="grid gap-4">
                <Panel>
                    {programmes.length === 0 ? (
                        <EmptyState icon={Layers} title="No programmes yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Programme</TableHead>
                                    <TableHead>Manager</TableHead>
                                    <TableHead className="text-right">
                                        Projects
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
                                            {programme.archived && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    Archived
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {programme.manager ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {programme.open} open of{' '}
                                            {programme.projects}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {canManage && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Add a programme" />
                        <PanelBody>
                            <Form
                                {...store.form()}
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="code"
                                            label="Code"
                                            error={errors.code}
                                        >
                                            <Input
                                                id="code"
                                                name="code"
                                                required
                                                maxLength={10}
                                                {...invalid(
                                                    'code',
                                                    errors.code,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="name"
                                            label="Name"
                                            error={errors.name}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                maxLength={255}
                                                {...invalid(
                                                    'name',
                                                    errors.name,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="manager_id"
                                            label="Manager"
                                            error={errors.manager_id}
                                            className="sm:col-span-3"
                                        >
                                            <Select
                                                id="manager_id"
                                                name="manager_id"
                                                placeholder="Not yet chosen"
                                                options={people}
                                            />
                                        </Field>
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Add programme
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
