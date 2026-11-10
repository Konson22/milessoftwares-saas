import { Form, Head, usePage } from '@inertiajs/react';
import { Fuel, Pencil, Plus, UserCheck, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { Field, Select } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { store, update } from '@/routes/staff';

type Attendant = {
    id: string;
    name: string;
    employeeCode: string;
    phone: string | null;
    userId: string | null;
    userName: string | null;
    active: boolean;
    stations: string[];
    onDuty: { station: string; shift: string; pump: string }[];
    salesToday: number;
    salesTodayMinor: number;
    litresToday: string;
};

type Station = { id: string; name: string; canManage: boolean };

type Show = 'all' | 'duty' | 'active' | 'inactive';

/**
 * The company's pump attendants: where each works, who is on a pump now,
 * and what they sold today. Sign-in accounts and roles are the workspace's.
 */
export default function Staff({
    attendants,
    stations,
    users,
    canManage,
}: {
    attendants: Attendant[];
    stations: Station[];
    users: { id: string; name: string; email: string }[];
    canManage: boolean;
}) {
    const { workspace, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'SSP');
    const [editing, setEditing] = useState<Attendant | 'new' | null>(null);
    const [search, setSearch] = useState('');
    const [station, setStation] = useState('');
    const [show, setShow] = useState<Show>('all');
    const members = workspace
        ? new URL('members', workspace.url).toString()
        : null;
    const stationName = (id: string) =>
        stations.find((row) => row.id === id)?.name ?? 'Station';

    const query = search.trim().toLowerCase();
    const shown = attendants.filter(
        (row) =>
            (query === '' ||
                row.name.toLowerCase().includes(query) ||
                row.employeeCode.toLowerCase().includes(query) ||
                (row.phone ?? '').includes(query)) &&
            (station === '' || row.stations.includes(station)) &&
            (show === 'all' ||
                (show === 'duty' && row.onDuty.length > 0) ||
                (show === 'active' && row.active) ||
                (show === 'inactive' && !row.active)),
    );
    const active = attendants.filter((row) => row.active).length;
    const onDuty = attendants.filter((row) => row.onDuty.length > 0).length;
    const unplaced = attendants.filter(
        (row) => row.active && row.stations.length === 0,
    ).length;
    const soldToday = attendants.reduce(
        (sum, row) => sum + row.salesTodayMinor,
        0,
    );

    return (
        <>
            <Head title="Staff" />
            <PageHeader
                title="Staff"
                actions={
                    <>
                        {members && (
                            <Button variant="outline" asChild>
                                <a href={members}>
                                    <UserPlus className="size-4" aria-hidden />
                                    Invite staff
                                </a>
                            </Button>
                        )}
                        {canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus className="size-4" aria-hidden />
                                Add attendant
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Pump attendants"
                    value={active}
                    hint={`${attendants.length - active} inactive`}
                    icon={Users}
                    accent="slate"
                />
                <StatCard
                    label="On a pump now"
                    value={onDuty}
                    hint="Assigned on an open shift"
                    icon={UserCheck}
                    accent="emerald"
                />
                <StatCard
                    label="Sold today"
                    value={money(soldToday)}
                    hint="Posted sales by these attendants"
                    icon={Fuel}
                    accent="blue"
                />
                <StatCard
                    label="Not placed"
                    value={unplaced}
                    hint="Active, but at no station in this view"
                    icon={Users}
                    accent={unplaced > 0 ? 'amber' : 'slate'}
                />
            </div>

            {editing !== null && (
                <AttendantForm
                    key={editing === 'new' ? 'new' : editing.id}
                    attendant={editing === 'new' ? null : editing}
                    stations={stations}
                    users={users}
                    onDone={() => setEditing(null)}
                />
            )}

            <Panel className="mt-4">
                <PanelHeader
                    title="Attendants"
                    description="Who sells at the pumps. Link one to a sign-in when they record sales themselves; accounts and roles are managed under the workspace's Members."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <SearchField
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Name, code or phone"
                                aria-label="Find an attendant"
                                className="w-52"
                            />
                            {stations.length > 1 && (
                                <Select
                                    aria-label="Station"
                                    value={station}
                                    onChange={(event) =>
                                        setStation(event.target.value)
                                    }
                                    placeholder="All stations"
                                    options={stations.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                    className="w-44"
                                />
                            )}
                            <Select
                                aria-label="Show"
                                value={show}
                                onChange={(event) =>
                                    setShow(event.target.value as Show)
                                }
                                options={[
                                    { value: 'all', label: 'Everyone' },
                                    { value: 'duty', label: 'On a pump now' },
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                                className="w-36"
                            />
                        </div>
                    }
                />
                {shown.length === 0 ? (
                    <PanelBody>
                        <EmptyState
                            icon={Users}
                            title={
                                attendants.length === 0
                                    ? 'No attendants yet'
                                    : 'Nobody matches'
                            }
                            action={
                                canManage &&
                                attendants.length === 0 && (
                                    <Button onClick={() => setEditing('new')}>
                                        Add the first attendant
                                    </Button>
                                )
                            }
                        />
                    </PanelBody>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Attendant
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Stations
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Now
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Today
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Sign-in
                                    </th>
                                    {canManage && <th className="px-3 py-2" />}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={cn(
                                            'border-b align-top last:border-0',
                                            !row.active && 'opacity-60',
                                        )}
                                    >
                                        <td className="px-3 py-2.5">
                                            <p className="font-medium">
                                                {row.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {row.employeeCode}
                                                {row.phone
                                                    ? ` · ${row.phone}`
                                                    : ''}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {row.stations.length === 0 ? (
                                                <span className="text-xs text-amber-700">
                                                    Not placed
                                                </span>
                                            ) : (
                                                <div className="flex flex-wrap gap-1">
                                                    {row.stations.map((id) => (
                                                        <StatusBadge key={id}>
                                                            {stationName(id)}
                                                        </StatusBadge>
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {!row.active ? (
                                                <StatusBadge>
                                                    Inactive
                                                </StatusBadge>
                                            ) : row.onDuty.length > 0 ? (
                                                row.onDuty.map((duty) => (
                                                    <p
                                                        key={`${duty.shift}-${duty.pump}`}
                                                        className="text-xs"
                                                    >
                                                        <StatusBadge tone="success">
                                                            On {duty.pump}
                                                        </StatusBadge>{' '}
                                                        <span className="text-muted-foreground">
                                                            {duty.station} ·
                                                            shift {duty.shift}
                                                        </span>
                                                    </p>
                                                ))
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    Off duty
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {row.salesToday > 0 ? (
                                                <>
                                                    <p className="font-medium">
                                                        {money(
                                                            row.salesTodayMinor,
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {row.litresToday} L ·{' '}
                                                        {row.salesToday} sales
                                                    </p>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-xs">
                                            {row.userName ?? (
                                                <span className="text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </td>
                                        {canManage && (
                                            <td className="px-3 py-2.5 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(row)
                                                    }
                                                    aria-label={`Edit ${row.name}`}
                                                >
                                                    <Pencil
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    Edit
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}

/** Add an attendant, or change one: details, sign-in, and the stations they work at. */
function AttendantForm({
    attendant,
    stations,
    users,
    onDone,
}: {
    attendant: Attendant | null;
    stations: Station[];
    users: { id: string; name: string; email: string }[];
    onDone: () => void;
}) {
    const action = attendant ? update.form(attendant.id) : store.form();
    const managed = stations.filter((row) => row.canManage);
    const elsewhere = attendant
        ? attendant.stations.filter(
              (id) => !managed.some((row) => row.id === id),
          )
        : [];

    return (
        <Panel className="mt-4 border-primary/40">
            <PanelHeader
                title={attendant ? `Edit ${attendant.name}` : 'New attendant'}
                actions={
                    <Button variant="ghost" size="sm" onClick={onDone}>
                        Close
                    </Button>
                }
            />
            <PanelBody>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={onDone}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={attendant?.name}
                                        required
                                        autoFocus
                                    />
                                </Field>
                                <Field
                                    id="employee_code"
                                    label="Employee code"
                                    error={errors.employee_code}
                                >
                                    <Input
                                        id="employee_code"
                                        name="employee_code"
                                        defaultValue={attendant?.employeeCode}
                                        required
                                    />
                                </Field>
                                <Field
                                    id="phone"
                                    label="Phone"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={attendant?.phone ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="user_id"
                                    label="Sign-in"
                                    hint="For an attendant who records their own sales."
                                    error={errors.user_id}
                                >
                                    <Select
                                        id="user_id"
                                        name="user_id"
                                        defaultValue={attendant?.userId ?? ''}
                                        placeholder="None"
                                        options={users.map((user) => ({
                                            value: user.id,
                                            label: `${user.name} (${user.email})`,
                                        }))}
                                    />
                                </Field>
                            </div>

                            <fieldset>
                                <legend className="mb-2 text-sm font-medium">
                                    Works at
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {managed.map((row) => (
                                        <label
                                            key={row.id}
                                            className="flex items-center gap-2 border px-2.5 py-1.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                        >
                                            <input
                                                type="checkbox"
                                                name="stations[]"
                                                value={row.id}
                                                defaultChecked={
                                                    attendant
                                                        ? attendant.stations.includes(
                                                              row.id,
                                                          )
                                                        : managed.length === 1
                                                }
                                            />
                                            {row.name}
                                        </label>
                                    ))}
                                </div>
                                {elsewhere.length > 0 && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Also at{' '}
                                        {elsewhere
                                            .map(
                                                (id) =>
                                                    stations.find(
                                                        (row) => row.id === id,
                                                    )?.name ??
                                                    'another station',
                                            )
                                            .join(', ')}
                                        , which you do not manage.
                                    </p>
                                )}
                                {errors.stations && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {errors.stations}
                                    </p>
                                )}
                            </fieldset>

                            <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="active"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="active"
                                        value="1"
                                        defaultChecked={
                                            attendant?.active ?? true
                                        }
                                    />
                                    Active: can be put on a pump
                                </label>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={onDone}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {attendant
                                            ? 'Save attendant'
                                            : 'Add attendant'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
