import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate, TextField } from '@/components/fields';
import { index, settings } from '@/routes/leave';
import { destroy, store as storeHoliday } from '@/routes/leave/holidays';
import { store as storeType, update as updateType } from '@/routes/leave/types';

type LeaveType = {
    id: string;
    name: string;
    daysPerYear: number;
    paid: boolean;
    allowNegative: boolean;
    maxCarryOver: number;
    archived: boolean;
};

type Props = {
    types: LeaveType[];
    year: number;
    years: number[];
    holidays: { id: string; date: string; name: string }[];
};

function Check({
    name,
    label,
    defaultChecked,
    prefix,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
    prefix: string;
}) {
    return (
        <div className="flex items-center gap-2 self-end pb-2">
            <input
                id={`${prefix}${name}`}
                name={name}
                type="checkbox"
                value="1"
                defaultChecked={defaultChecked}
                className="size-4"
            />
            <Label htmlFor={`${prefix}${name}`}>{label}</Label>
        </div>
    );
}

/**
 * The fields of a kind of leave, new or existing.
 */
function TypeForm({
    type,
    onDone,
}: {
    type: LeaveType | null;
    onDone: () => void;
}) {
    const prefix = type === null ? 'new-' : `${type.id}-`;

    return (
        <Form
            {...(type === null ? storeType.form() : updateType.form(type.id))}
            onSuccess={onDone}
            className="grid gap-3 sm:grid-cols-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="sm:col-span-2">
                        <TextField
                            name="name"
                            label="Name"
                            defaultValue={type?.name}
                            error={errors.name}
                            prefix={prefix}
                            required
                        />
                    </div>
                    <TextField
                        name="days_per_year"
                        label="Days per year"
                        type="number"
                        defaultValue={type?.daysPerYear ?? 0}
                        error={errors.days_per_year}
                        prefix={prefix}
                        required
                    />
                    <TextField
                        name="max_carry_over"
                        label="Most days carried into next year"
                        type="number"
                        defaultValue={type?.maxCarryOver ?? 0}
                        error={errors.max_carry_over}
                        prefix={prefix}
                        required
                    />
                    <Check
                        name="paid"
                        label="Paid"
                        defaultChecked={type?.paid ?? true}
                        prefix={prefix}
                    />
                    <Check
                        name="allow_negative"
                        label="No limit (may go below zero)"
                        defaultChecked={type?.allowNegative ?? false}
                        prefix={prefix}
                    />
                    {type !== null && (
                        <Check
                            name="archived"
                            label="No longer in use"
                            defaultChecked={type.archived}
                            prefix={prefix}
                        />
                    )}
                    <div className="flex gap-2 sm:col-span-4">
                        <Button type="submit" disabled={processing}>
                            {type === null ? 'Add' : 'Save'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function LeaveSettings({ types, year, years, holidays }: Props) {
    // A kind's id, or 'new'.
    const [editing, setEditing] = useState<string | null>(null);
    const [removing, setRemoving] = useState<string | null>(null);

    return (
        <>
            <Head title="Leave settings" />
            <PageHeader
                title="Leave settings"
                description={
                    <>
                        Kinds of leave and public holidays for the whole
                        organization.{' '}
                        <Link href={index()} className="hover:underline">
                            Back to leave
                        </Link>
                    </>
                }
            />

            <Panel className="mb-3">
                <PanelHeader
                    title="Kinds of leave"
                    description="Days per year start each person's days the first time a year is used; changing them does not alter years already started. Change one person's days on their leave page."
                    actions={
                        editing === null ? (
                            <Button size="sm" onClick={() => setEditing('new')}>
                                Add a kind
                            </Button>
                        ) : undefined
                    }
                />
                {editing === 'new' && (
                    <PanelBody>
                        <TypeForm type={null} onDone={() => setEditing(null)} />
                    </PanelBody>
                )}
                {types.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        No kinds of leave yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Kind</TableHead>
                                <TableHead className="text-right">
                                    Days per year
                                </TableHead>
                                <TableHead className="text-right">
                                    Carry over
                                </TableHead>
                                <TableHead className="text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((type) =>
                                editing === type.id ? (
                                    <TableRow key={type.id}>
                                        <TableCell colSpan={4}>
                                            <TypeForm
                                                type={type}
                                                onDone={() => setEditing(null)}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={type.id}>
                                        <TableCell>
                                            {type.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {[
                                                    type.paid
                                                        ? 'Paid'
                                                        : 'Unpaid',
                                                    type.allowNegative
                                                        ? 'no limit'
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                            {type.archived && (
                                                <StatusBadge className="mt-1">
                                                    No longer in use
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {type.daysPerYear}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {type.maxCarryOver === 0
                                                ? '—'
                                                : `up to ${type.maxCarryOver}`}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing(type.id)
                                                }
                                            >
                                                Edit
                                                <span className="sr-only">
                                                    {' '}
                                                    {type.name}
                                                </span>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <Panel>
                <PanelHeader
                    title={`Public holidays in ${year}`}
                    description="Leave over a public holiday does not use a day. Requests already made keep their day count."
                    actions={
                        <nav aria-label="Year" className="flex gap-1">
                            {years.map((option) => (
                                <Button
                                    key={option}
                                    asChild
                                    size="sm"
                                    variant={
                                        option === year ? 'secondary' : 'ghost'
                                    }
                                >
                                    <Link
                                        href={settings({
                                            query: { year: option },
                                        })}
                                        preserveScroll
                                        aria-current={
                                            option === year ? 'page' : undefined
                                        }
                                    >
                                        {option}
                                    </Link>
                                </Button>
                            ))}
                        </nav>
                    }
                />
                <PanelBody>
                    <Form
                        {...storeHoliday.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid gap-3 sm:grid-cols-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <TextField
                                    name="date"
                                    label="Date"
                                    type="date"
                                    error={errors.date}
                                    prefix="holiday-"
                                    required
                                />
                                <div className="sm:col-span-2">
                                    <TextField
                                        name="name"
                                        label="Name"
                                        error={errors.name}
                                        prefix="holiday-"
                                        required
                                    />
                                </div>
                                <div className="self-end">
                                    <Button type="submit" disabled={processing}>
                                        Add holiday
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
                {holidays.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        No public holidays for {year} yet.
                    </p>
                ) : (
                    <Table>
                        <TableBody>
                            {holidays.map((holiday) => (
                                <TableRow key={holiday.id}>
                                    <TableCell className="tabular-nums">
                                        {formatDate(holiday.date)}
                                    </TableCell>
                                    <TableCell>{holiday.name}</TableCell>
                                    <TableCell className="text-right">
                                        {removing === holiday.id ? (
                                            <span className="inline-flex gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() =>
                                                        router.delete(
                                                            destroy.url(
                                                                holiday.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                                onFinish: () =>
                                                                    setRemoving(
                                                                        null,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setRemoving(null)
                                                    }
                                                >
                                                    Keep
                                                </Button>
                                            </span>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setRemoving(holiday.id)
                                                }
                                            >
                                                Remove
                                                <span className="sr-only">
                                                    {' '}
                                                    {holiday.name}
                                                </span>
                                            </Button>
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
