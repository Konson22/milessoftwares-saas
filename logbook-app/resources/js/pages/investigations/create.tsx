import { Head, Link, useForm } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { Button } from '@miles/ui/components/ui/button';
import { SelectField, TextArea, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { formatDateTime } from '@/lib/logbook';
import { index, store } from '@/routes/investigations';

type Option = { value: string; label: string };

/**
 * Opens an investigation. Nothing is concluded here: the incident is
 * described, and the records are linked as references.
 */
export default function CreateInvestigation({
    logbooks,
    resources,
    members,
    initial,
    me,
}: {
    logbooks: Option[];
    resources: Option[];
    members: Option[];
    initial: {
        logbook_id: string;
        resource_id: string;
        entry: { id: string; label: string; occurredAt: string | null } | null;
    };
    me: string;
}) {
    const form = useForm({
        title: '',
        description: '',
        logbook_id: initial.logbook_id,
        resource_id: initial.resource_id,
        occurred_at: initial.entry?.occurredAt?.slice(0, 16) ?? '',
        period_from: '',
        period_to: '',
        investigator_id: me,
        entry_id: initial.entry?.id ?? '',
    });
    const set = (key: keyof typeof form.data) => (value: string) =>
        form.setData(key, value);

    return (
        <>
            <Head title="New investigation" />
            <PageHeader title="New investigation" />

            <form
                className="grid max-w-3xl gap-5 rounded-lg border bg-card p-5 shadow-[0_1px_2px_#e3e3e3]"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        resource_id: d.resource_id || null,
                        occurred_at: d.occurred_at || null,
                        period_from: d.period_from || null,
                        period_to: d.period_to || null,
                        entry_id: d.entry_id || null,
                    }));
                    form.post(store().url);
                }}
            >
                {initial.entry && (
                    <p className="flex items-center gap-2 rounded-md bg-[#e3f3ee] px-3 py-2 text-sm text-[#0a5c48]">
                        <Link2 className="size-4 shrink-0" />
                        Starts from {initial.entry.label}, written{' '}
                        {formatDateTime(initial.entry.occurredAt)}. It is linked
                        as a reference; the entry is not changed.
                    </p>
                )}
                <TextField
                    label="Title"
                    value={form.data.title}
                    onChange={set('title')}
                    error={form.errors.title}
                    placeholder="For example: generator ran far longer than usual"
                    required
                />
                <TextArea
                    label="What happened (as reported)"
                    value={form.data.description}
                    onChange={set('description')}
                    error={form.errors.description}
                    rows={4}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="Logbook"
                        value={form.data.logbook_id}
                        onChange={set('logbook_id')}
                        options={logbooks}
                        empty="Choose a logbook"
                        error={form.errors.logbook_id}
                        hint="Who can see the investigation follows this logbook."
                    />
                    <SelectField
                        label="Resource (optional)"
                        value={form.data.resource_id}
                        onChange={set('resource_id')}
                        options={resources}
                        empty="None"
                        error={form.errors.resource_id}
                    />
                    <TextField
                        label="Incident time (optional)"
                        type="datetime-local"
                        value={form.data.occurred_at}
                        onChange={set('occurred_at')}
                        error={form.errors.occurred_at}
                    />
                    <SelectField
                        label="Investigator"
                        value={form.data.investigator_id}
                        onChange={set('investigator_id')}
                        options={members}
                        error={form.errors.investigator_id}
                    />
                    <TextField
                        label="Period looked at, from (optional)"
                        type="date"
                        value={form.data.period_from}
                        onChange={set('period_from')}
                        error={form.errors.period_from}
                    />
                    <TextField
                        label="To (optional)"
                        type="date"
                        value={form.data.period_to}
                        onChange={set('period_to')}
                        error={form.errors.period_to}
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <Button asChild variant="outline">
                        <Link href={index()}>Cancel</Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            form.data.title.trim() === '' ||
                            form.data.logbook_id === ''
                        }
                    >
                        Open investigation
                    </Button>
                </div>
            </form>
        </>
    );
}
