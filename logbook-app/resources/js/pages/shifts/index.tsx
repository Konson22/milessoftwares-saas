import { Head, router, usePage } from '@inertiajs/react';
import { Clock3 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Check, SelectField, Status, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { Toggle } from '@/components/work';
import { store, update } from '@/routes/shifts';

type Option = { value: string; label: string };

type ShiftRow = {
    id: string;
    name: string;
    starts: string;
    ends: string;
    overnight: boolean;
    active: boolean;
    logbook: { id: string; name: string } | null;
    members: { id: string; name: string }[];
    entries: number;
};

type Props = {
    shifts: ShiftRow[];
    logbooks: Option[];
    members: Option[];
    canManage: boolean;
};

/**
 * Operational shifts (ADR-0030 §4.5). Not a roster: people linked to a
 * shift only have it suggested on their entries.
 */
export default function Shifts({
    shifts,
    logbooks,
    members,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Shifts" />
            <PageHeader
                title="Shifts"
                description="The working periods entries can be recorded in, such as Morning 06:00–14:00 or Night 22:00–06:00. A sheet's template says whether its entries name a shift."
            />
            {canManage && (
                <div className="mb-4">
                    <Toggle label="Add a shift">
                        {(close) => (
                            <ShiftForm
                                logbooks={logbooks}
                                members={members}
                                onDone={close}
                            />
                        )}
                    </Toggle>
                </div>
            )}
            <Panel>
                <PanelHeader title={`Shifts (${shifts.length})`} />
                {shifts.length === 0 ? (
                    <EmptyState icon={Clock3} title="No shifts yet">
                        Shifts are optional. Add them when entries should say
                        which working period they were recorded in.
                    </EmptyState>
                ) : (
                    <ul className="divide-y">
                        {shifts.map((s) => (
                            <li key={s.id} className="px-4 py-3 text-sm">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">
                                            {s.name} · {s.starts}–{s.ends}
                                            {s.overnight && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    (ends the next day)
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {s.logbook?.name ?? 'Every logbook'}{' '}
                                            ·{' '}
                                            {s.members.length === 0
                                                ? 'no people linked'
                                                : s.members
                                                      .map((m) => m.name)
                                                      .join(', ')}{' '}
                                            · {s.entries}{' '}
                                            {s.entries === 1
                                                ? 'entry'
                                                : 'entries'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Status
                                            value={
                                                s.active ? 'active' : 'inactive'
                                            }
                                        />
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditing(
                                                        editing === s.id
                                                            ? null
                                                            : s.id,
                                                    )
                                                }
                                            >
                                                {editing === s.id
                                                    ? 'Close'
                                                    : 'Change'}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {editing === s.id && (
                                    <div className="mt-3 rounded-md border p-4">
                                        <ShiftForm
                                            initial={s}
                                            logbooks={logbooks}
                                            members={members}
                                            onDone={() => setEditing(null)}
                                        />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}

function ShiftForm({
    initial,
    logbooks,
    members,
    onDone,
}: {
    initial?: ShiftRow;
    logbooks: Option[];
    members: Option[];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: initial?.name ?? '',
        starts_time: initial?.starts ?? '06:00',
        ends_time: initial?.ends ?? '14:00',
        logbook_id: initial?.logbook?.id ?? '',
        active: initial?.active ?? true,
        member_ids: initial?.members.map((m) => m.id) ?? ([] as string[]),
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });
    const [find, setFind] = useState('');
    const shown = members.filter(
        (m) =>
            data.member_ids.includes(m.value) ||
            m.label.toLowerCase().includes(find.toLowerCase()),
    );

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.visit(initial ? update(initial.id).url : store().url, {
                    method: initial ? 'put' : 'post',
                    data: { ...data, logbook_id: data.logbook_id || null },
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <TextField
                label="Name"
                value={data.name}
                onChange={(name) => set({ name })}
                error={errors.name}
            />
            <SelectField
                label="Applies to"
                value={data.logbook_id}
                onChange={(logbook_id) => set({ logbook_id })}
                options={logbooks}
                empty="Every logbook"
                error={errors.logbook_id}
            />
            <TextField
                label="Starts"
                type="time"
                value={data.starts_time}
                onChange={(starts_time) => set({ starts_time })}
                error={errors.starts_time}
            />
            <TextField
                label="Ends"
                type="time"
                value={data.ends_time}
                onChange={(ends_time) => set({ ends_time })}
                error={errors.ends_time}
                hint={
                    data.ends_time !== '' && data.ends_time < data.starts_time
                        ? 'Ends the next day.'
                        : undefined
                }
            />
            <div className="grid gap-2 sm:col-span-2">
                <span className="text-sm font-medium">
                    People usually on this shift (optional)
                </span>
                <TextField
                    label="Find a person"
                    value={find}
                    onChange={setFind}
                    className="max-w-sm"
                />
                <div className="grid max-h-48 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                    {shown.map((m) => (
                        <Check
                            key={m.value}
                            label={m.label}
                            checked={data.member_ids.includes(m.value)}
                            onChange={(on) =>
                                set({
                                    member_ids: on
                                        ? [...data.member_ids, m.value]
                                        : data.member_ids.filter(
                                              (id) => id !== m.value,
                                          ),
                                })
                            }
                        />
                    ))}
                </div>
                {errors.member_ids && (
                    <p className="text-sm text-destructive">
                        {errors.member_ids}
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    Only a suggestion on their entries. Anyone allowed on a
                    sheet can still record in any shift.
                </p>
            </div>
            {initial && (
                <Check
                    label="In use (switch off to stop offering it; past entries keep it)"
                    checked={data.active}
                    onChange={(active) => set({ active })}
                />
            )}
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">
                    {initial ? 'Save shift' : 'Add shift'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
