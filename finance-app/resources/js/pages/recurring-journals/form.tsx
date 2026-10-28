import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Field, Select } from '@/components/field';
import { LinesEditor } from '@/components/lines-editor';
import type {
    AccountOption,
    DimensionOptions,
} from '@/components/lines-editor';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { show as journal } from '@/routes/journals';
import { destroy, store, update } from '@/routes/recurring-journals';

type Template = {
    id: string;
    name: string;
    memo: string;
    frequency: string;
    startsOn: string;
    endsOn: string | null;
    reverses: boolean;
    postedCount: number;
    lines: {
        accountId: string;
        description: string;
        debit: string;
        credit: string;
        costCentreId: string;
        projectId: string;
    }[];
    journals: { id: string; number: string; date: string }[];
};

type Props = {
    template: Template | null;
    frequencies: { value: string; label: string }[];
    accounts: AccountOption[];
    dimensions: DimensionOptions;
    today: string;
};

/**
 * A recurring journal: balanced lines and when to post them.
 */
export default function RecurringJournalForm({
    template,
    frequencies,
    accounts,
    dimensions,
    today,
}: Props) {
    const posted = (template?.postedCount ?? 0) > 0;
    const title = template ? template.name : 'New recurring journal';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description="Posted on its first date and then on each date it falls due, like a manual journal: locked periods and required cost centres apply."
            />
            <Panel>
                <PanelBody>
                    <Form
                        {...(template
                            ? update.form(template.id)
                            : store.form())}
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 sm:grid-cols-4">
                                    <Field
                                        id="name"
                                        label="Name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={100}
                                            defaultValue={template?.name}
                                            placeholder="Office rent"
                                        />
                                    </Field>
                                    <Field
                                        id="memo"
                                        label="Memo on each journal"
                                        error={errors.memo}
                                        className="sm:col-span-3"
                                    >
                                        <Input
                                            id="memo"
                                            name="memo"
                                            required
                                            maxLength={200}
                                            defaultValue={template?.memo}
                                            placeholder="Monthly rent accrual"
                                        />
                                    </Field>
                                    <Field
                                        id="frequency"
                                        label="How often"
                                        error={errors.frequency}
                                    >
                                        <Select
                                            id="frequency"
                                            name="frequency"
                                            defaultValue={
                                                template?.frequency ?? 'monthly'
                                            }
                                            disabled={posted}
                                        >
                                            {frequencies.map((frequency) => (
                                                <option
                                                    key={frequency.value}
                                                    value={frequency.value}
                                                >
                                                    {frequency.label}
                                                </option>
                                            ))}
                                        </Select>
                                        {posted && (
                                            <input
                                                type="hidden"
                                                name="frequency"
                                                value={template?.frequency}
                                            />
                                        )}
                                    </Field>
                                    <Field
                                        id="starts_on"
                                        label="First date"
                                        error={errors.starts_on}
                                        hint="A month end stays a month end"
                                    >
                                        <Input
                                            id="starts_on"
                                            name="starts_on"
                                            type="date"
                                            required
                                            readOnly={posted}
                                            defaultValue={
                                                template?.startsOn ?? today
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="ends_on"
                                        label="Last date"
                                        error={errors.ends_on}
                                        hint="Blank: until stopped"
                                    >
                                        <Input
                                            id="ends_on"
                                            name="ends_on"
                                            type="date"
                                            defaultValue={
                                                template?.endsOn ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="reverses"
                                        label="Accrual"
                                        error={errors.reverses}
                                    >
                                        <label className="flex h-9 items-center gap-2 text-sm">
                                            <input
                                                type="hidden"
                                                name="reverses"
                                                value="0"
                                            />
                                            <input
                                                id="reverses"
                                                type="checkbox"
                                                name="reverses"
                                                value="1"
                                                defaultChecked={
                                                    template?.reverses ?? false
                                                }
                                            />
                                            Reverse each one the next day
                                        </label>
                                    </Field>
                                </div>
                                <LinesEditor
                                    accounts={accounts}
                                    dimensions={dimensions}
                                    mode="journal"
                                    errors={errors}
                                    minRows={2}
                                    initial={template?.lines}
                                />
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Save recurring journal
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>

            {template && (
                <Panel className="mt-3">
                    <PanelHeader
                        title="Posted"
                        description={`${template.postedCount} so far; the latest are listed.`}
                    />
                    <PanelBody className="grid gap-3">
                        {template.journals.length > 0 && (
                            <ul className="flex flex-wrap gap-2 text-sm">
                                {template.journals.map((posted) => (
                                    <li key={posted.id}>
                                        <Link
                                            href={journal(posted.id)}
                                            className="rounded border px-2 py-1 tabular-nums hover:bg-muted"
                                        >
                                            {posted.number} · {posted.date}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Form {...destroy.form(template.id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    <Trash2 className="size-4" aria-hidden />
                                    Remove recurring journal
                                </Button>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
