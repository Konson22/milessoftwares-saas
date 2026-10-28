import { Form, Head } from '@inertiajs/react';
import { Field } from '@/components/field';
import { LinesEditor } from '@/components/lines-editor';
import type {
    AccountOption,
    DimensionOptions,
} from '@/components/lines-editor';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useMoney } from '@/lib/format';
import { store } from '@/routes/journals';

type Props = {
    accounts: AccountOption[];
    dimensions: DimensionOptions;
    today: string;
    /** Set when manual journals need a second person's approval. */
    approval: { thresholdMinor: number | null } | null;
};

/**
 * A manual journal: debits must equal credits.
 */
export default function CreateJournal({
    accounts,
    dimensions,
    today,
    approval,
}: Props) {
    const money = useMoney();

    return (
        <>
            <Head title="New journal" />
            <PageHeader
                title="New journal"
                description="For entries no other screen makes: opening balances, corrections, depreciation. Debits must equal credits."
            />
            {approval && (
                <p className="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {approval.thresholdMinor === null
                        ? 'Manual journals need approval: this one waits until someone else approves it.'
                        : `Manual journals of ${money(approval.thresholdMinor)} or more wait until someone else approves them.`}
                </p>
            )}
            <Panel>
                <PanelBody>
                    <Form {...store.form()} className="grid gap-3">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 sm:grid-cols-4">
                                    <Field
                                        id="entry_date"
                                        label="Date"
                                        error={errors.entry_date}
                                    >
                                        <Input
                                            id="entry_date"
                                            name="entry_date"
                                            type="date"
                                            defaultValue={today}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="memo"
                                        label="Memo"
                                        error={errors.memo}
                                        className="sm:col-span-3"
                                    >
                                        <Input
                                            id="memo"
                                            name="memo"
                                            required
                                            maxLength={255}
                                            placeholder="What this entry is for"
                                        />
                                    </Field>
                                </div>
                                <LinesEditor
                                    accounts={accounts}
                                    dimensions={dimensions}
                                    mode="journal"
                                    errors={errors}
                                    minRows={2}
                                />
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {approval?.thresholdMinor === null
                                            ? 'Submit for approval'
                                            : 'Post journal'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
