import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { useState } from 'react';
import { selectClass } from '@/mel/components/fields';
import { ASSIGNMENT } from '@/mel/lib/collect';
import type { AssignmentStatus } from '@/mel/lib/collect';
import type { Option } from '@/mel/lib/mel';
import { formatDateTime } from '@/mel/lib/mel';
import { close, index, revoke, store } from '@/routes/mel/collect/assignments';
import { show as formPage } from '@/routes/mel/collect/forms';

type Props = {
    filters: { form: string | null; show: 'live' | 'ended' };
    assignments: {
        id: string;
        person: string;
        email: string;
        form: { id: string; name: string; status: string };
        unit: string | null;
        startsOn: string | null;
        endsOn: string | null;
        status: AssignmentStatus;
        closesAt: string | null;
        assignedBy: string;
        submissions: number;
        lastSubmissionAt: string | null;
    }[];
    forms: {
        value: string;
        label: string;
        published: boolean;
        unitFixed: string | null;
        units: Option[];
    }[];
    people: Option[];
    canAssign: boolean;
};

export default function Assignments({
    filters,
    assignments,
    forms,
    people,
    canAssign,
}: Props) {
    const [search, setSearch] = useState('');
    const assign = useForm<{
        form: string;
        user_ids: string[];
        programme_unit_id: string;
        starts_on: string;
        ends_on: string;
    }>({
        form: filters.form ?? forms[0]?.value ?? '',
        user_ids: [],
        programme_unit_id: '',
        starts_on: '',
        ends_on: '',
    });
    const chosen = forms.find((form) => form.value === assign.data.form);
    const go = (next: Partial<Props['filters']>) =>
        router.get(
            index.url({ query: { ...filters, ...next } }),
            {},
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Field assignments" />
            <PageHeader
                title="Field assignments"
                description="Who collects with which form. Nobody's phone gets a form without an assignment. Closing stops new forms but still accepts finished ones for a while; revoking refuses everything at once."
            />
            {canAssign && forms.length > 0 && (
                <Panel className="mb-3">
                    <PanelHeader title="Assign people" />
                    <PanelBody>
                        <form
                            className="grid gap-3 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                assign.transform(
                                    ({ form: _form, ...data }) => ({
                                        ...data,
                                        programme_unit_id:
                                            data.programme_unit_id || null,
                                        starts_on: data.starts_on || null,
                                        ends_on: data.ends_on || null,
                                    }),
                                );
                                assign.submit(store(assign.data.form), {
                                    preserveScroll: true,
                                    onSuccess: () => assign.reset('user_ids'),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                <span>Form</span>
                                <select
                                    className={selectClass}
                                    value={assign.data.form}
                                    onChange={(e) =>
                                        assign.setData('form', e.target.value)
                                    }
                                >
                                    {forms.map((form) => (
                                        <option
                                            key={form.value}
                                            value={form.value}
                                        >
                                            {form.label}
                                            {!form.published &&
                                                ' (not published yet)'}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                <span>Unit</span>
                                {chosen?.unitFixed ? (
                                    <span className="py-2 text-muted-foreground">
                                        {chosen.unitFixed} (the form&apos;s
                                        unit)
                                    </span>
                                ) : (
                                    <select
                                        className={selectClass}
                                        value={assign.data.programme_unit_id}
                                        onChange={(e) =>
                                            assign.setData(
                                                'programme_unit_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Chosen on the phone (or none)
                                        </option>
                                        {chosen?.units.map((unit) => (
                                            <option
                                                key={unit.value}
                                                value={unit.value}
                                            >
                                                {unit.label}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </label>
                            <fieldset className="grid gap-1 text-sm md:col-span-2">
                                <legend className="mb-1">
                                    People ({assign.data.user_ids.length}{' '}
                                    chosen)
                                </legend>
                                <input
                                    className={`${selectClass} mb-1`}
                                    placeholder="Find a person"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    aria-label="Find a person"
                                />
                                <div className="grid max-h-48 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                                    {people
                                        .filter((person) =>
                                            person.label
                                                .toLowerCase()
                                                .includes(search.toLowerCase()),
                                        )
                                        .map((person) => (
                                            <label
                                                key={person.value}
                                                className="flex items-center gap-1.5"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={assign.data.user_ids.includes(
                                                        person.value,
                                                    )}
                                                    onChange={(e) =>
                                                        assign.setData(
                                                            'user_ids',
                                                            e.target.checked
                                                                ? [
                                                                      ...assign
                                                                          .data
                                                                          .user_ids,
                                                                      person.value,
                                                                  ]
                                                                : assign.data.user_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          person.value,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {person.label}
                                            </label>
                                        ))}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    People appear here once they have opened
                                    M&amp;E, on the web or in the field app.
                                </p>
                            </fieldset>
                            <label className="grid gap-1 text-sm">
                                <span>From (optional)</span>
                                <input
                                    type="date"
                                    className={selectClass}
                                    value={assign.data.starts_on}
                                    onChange={(e) =>
                                        assign.setData(
                                            'starts_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                <span>To (optional)</span>
                                <input
                                    type="date"
                                    className={selectClass}
                                    value={assign.data.ends_on}
                                    onChange={(e) =>
                                        assign.setData(
                                            'ends_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            {Object.values(assign.errors).map((message) => (
                                <p
                                    key={message}
                                    className="text-sm text-destructive md:col-span-2"
                                >
                                    {message}
                                </p>
                            ))}
                            <div className="md:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        assign.processing ||
                                        assign.data.user_ids.length === 0
                                    }
                                >
                                    Assign
                                </Button>
                            </div>
                        </form>
                    </PanelBody>
                </Panel>
            )}
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant={filters.show === 'live' ? 'default' : 'outline'}
                    onClick={() => go({ show: 'live' })}
                >
                    Active and closing
                </Button>
                <Button
                    size="sm"
                    variant={filters.show === 'ended' ? 'default' : 'outline'}
                    onClick={() => go({ show: 'ended' })}
                >
                    Revoked
                </Button>
                <select
                    className={`${selectClass} ml-auto w-56`}
                    value={filters.form ?? ''}
                    onChange={(e) => go({ form: e.target.value || null })}
                    aria-label="Form"
                >
                    <option value="">All forms</option>
                    {forms.map((form) => (
                        <option key={form.value} value={form.value}>
                            {form.label}
                        </option>
                    ))}
                </select>
            </div>
            <Panel>
                {assignments.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        No assignment here.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Person</TableHead>
                                <TableHead>Form</TableHead>
                                <TableHead>Dates</TableHead>
                                <TableHead className="text-right">
                                    Sent
                                </TableHead>
                                <TableHead>Status</TableHead>
                                {canAssign && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {assignments.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        {row.person}
                                        <div className="text-xs text-muted-foreground">
                                            {row.email}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={formPage(row.form.id)}
                                            className="hover:underline"
                                        >
                                            {row.form.name}
                                        </Link>
                                        {row.unit && (
                                            <div className="text-xs text-muted-foreground">
                                                {row.unit}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {row.startsOn || row.endsOn
                                            ? `${row.startsOn ?? '…'} – ${row.endsOn ?? '…'}`
                                            : 'Open-ended'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.submissions}
                                        {row.lastSubmissionAt && (
                                            <div className="text-xs text-muted-foreground">
                                                last{' '}
                                                {formatDateTime(
                                                    row.lastSubmissionAt,
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={ASSIGNMENT[row.status].tone}
                                        >
                                            {ASSIGNMENT[row.status].label}
                                        </StatusBadge>
                                        {row.status === 'closing' &&
                                            row.closesAt && (
                                                <div className="text-xs text-muted-foreground">
                                                    accepts until{' '}
                                                    {formatDateTime(
                                                        row.closesAt,
                                                    )}
                                                </div>
                                            )}
                                    </TableCell>
                                    {canAssign && (
                                        <TableCell className="text-right">
                                            {row.status !== 'revoked' && (
                                                <div className="flex justify-end gap-1">
                                                    {row.status ===
                                                        'active' && (
                                                        <Form
                                                            {...close.form(
                                                                row.id,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                            >
                                                                Close
                                                            </Button>
                                                        </Form>
                                                    )}
                                                    <Form
                                                        {...revoke.form(row.id)}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            Revoke
                                                        </Button>
                                                    </Form>
                                                </div>
                                            )}
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
