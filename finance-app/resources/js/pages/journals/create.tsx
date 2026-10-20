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
import { store } from '@/routes/journals';

type Props = {
    accounts: AccountOption[];
    dimensions: DimensionOptions;
    today: string;
};

/**
 * A manual journal: debits must equal credits.
 */
export default function CreateJournal({ accounts, dimensions, today }: Props) {
    return (
        <>
            <Head title="New journal" />
            <PageHeader
                title="New journal"
                description="For entries no other screen makes: opening balances, corrections, depreciation. Debits must equal credits."
            />
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
                                        Post journal
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
