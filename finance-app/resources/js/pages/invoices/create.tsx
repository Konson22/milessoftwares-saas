import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { CurrencyFields } from '@/components/currency-fields';
import type { CurrencyOption } from '@/components/currency-fields';
import { Field } from '@/components/field';
import { LinesEditor } from '@/components/lines-editor';
import type {
    AccountOption,
    DimensionOptions,
    TaxCodeOption,
} from '@/components/lines-editor';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { store } from '@/routes/invoices';

type Props = {
    accounts: AccountOption[];
    taxCodes: TaxCodeOption[];
    dimensions: DimensionOptions;
    currencies: CurrencyOption[];
    today: string;
};

/**
 * An invoice raised in Finance: for work billed outside the products.
 */
export default function CreateInvoice({
    accounts,
    taxCodes,
    dimensions,
    currencies,
    today,
}: Props) {
    const [currency, setCurrency] = useState('');

    return (
        <>
            <Head title="New invoice" />
            <PageHeader
                title="New invoice"
                description="For income your products do not record. Each line goes to the revenue account you choose."
            />
            <Panel>
                <PanelBody>
                    <Form {...store.form()} className="grid gap-3">
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
                                    <CurrencyFields
                                        currencies={currencies}
                                        value={currency}
                                        onChange={setCurrency}
                                        errors={errors}
                                    />
                                </div>
                                <LinesEditor
                                    accounts={accounts}
                                    mode="amount"
                                    errors={errors}
                                    taxCodes={taxCodes}
                                    dimensions={dimensions}
                                    currency={currency || null}
                                />
                                {taxCodes.length === 0 && (
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <Field
                                            id="tax"
                                            label="Tax"
                                            error={errors.tax}
                                            hint="Leave blank when there is none. Add tax codes to charge tax per line."
                                        >
                                            <Input
                                                id="tax"
                                                name="tax"
                                                inputMode="decimal"
                                            />
                                        </Field>
                                    </div>
                                )}
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
