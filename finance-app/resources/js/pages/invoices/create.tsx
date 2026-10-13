import { Form, Head } from '@inertiajs/react';
import { Field } from '@/components/field';
import { LinesEditor } from '@/components/lines-editor';
import type { AccountOption } from '@/components/lines-editor';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/invoices';

type Props = { accounts: AccountOption[]; today: string };

/**
 * An invoice raised in Finance: for work billed outside the products.
 */
export default function CreateInvoice({ accounts, today }: Props) {
    return (
        <>
            <Head title="New invoice" />
            <PageHeader
                title="New invoice"
                description="For income your products do not record. Each line goes to the revenue account you choose."
            />
            <Panel>
                <PanelBody>
                    <Form {...store.form()} className="grid gap-5">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 sm:grid-cols-4">
                                    <Field
                                        id="customer_name"
                                        label="Customer"
                                        error={errors.customer_name}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="customer_name"
                                            name="customer_name"
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="issued_on"
                                        label="Date"
                                        error={errors.issued_on}
                                    >
                                        <Input
                                            id="issued_on"
                                            name="issued_on"
                                            type="date"
                                            defaultValue={today}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="due_on"
                                        label="Due"
                                        error={errors.due_on}
                                    >
                                        <Input
                                            id="due_on"
                                            name="due_on"
                                            type="date"
                                        />
                                    </Field>
                                </div>
                                <LinesEditor
                                    accounts={accounts}
                                    mode="amount"
                                    errors={errors}
                                />
                                <div className="grid gap-3 sm:grid-cols-4">
                                    <Field
                                        id="tax"
                                        label="Tax"
                                        error={errors.tax}
                                        hint="Leave blank when there is none."
                                    >
                                        <Input
                                            id="tax"
                                            name="tax"
                                            inputMode="decimal"
                                        />
                                    </Field>
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        Raise invoice
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
