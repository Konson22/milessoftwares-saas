import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { CurrencyFields } from '@/components/currency-fields';
import type { CurrencyOption } from '@/components/currency-fields';
import { Field, Select } from '@/components/field';
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
import { store } from '@/routes/bills';

type Props = {
    suppliers: { id: string; name: string }[];
    supplierId: string | null;
    accounts: AccountOption[];
    taxCodes: TaxCodeOption[];
    dimensions: DimensionOptions;
    currencies: CurrencyOption[];
    today: string;
};

/**
 * A bill entered by hand: rent, utilities, services, purchases outside the
 * products.
 */
export default function CreateBill({
    suppliers,
    supplierId,
    accounts,
    taxCodes,
    dimensions,
    currencies,
    today,
}: Props) {
    const [currency, setCurrency] = useState('');

    return (
        <>
            <Head title="New bill" />
            <PageHeader
                title="New bill"
                description="For costs your products do not send: rent, utilities, services. Each line goes to the account you choose."
            />
            <Panel>
                <PanelBody>
                    {suppliers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Add a supplier first (Payables → Suppliers).
                        </p>
                    ) : (
                        <Form {...store.form()} className="grid gap-3">
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <Field
                                            id="supplier_id"
                                            label="Supplier"
                                            error={errors.supplier_id}
                                            className="sm:col-span-2"
                                        >
                                            <Select
                                                id="supplier_id"
                                                name="supplier_id"
                                                defaultValue={supplierId ?? ''}
                                                required
                                            >
                                                <option value="">
                                                    Choose a supplier…
                                                </option>
                                                {suppliers.map((supplier) => (
                                                    <option
                                                        key={supplier.id}
                                                        value={supplier.id}
                                                    >
                                                        {supplier.name}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                        <Field
                                            id="supplier_reference"
                                            label="Their invoice number"
                                            error={errors.supplier_reference}
                                        >
                                            <Input
                                                id="supplier_reference"
                                                name="supplier_reference"
                                                maxLength={64}
                                            />
                                        </Field>
                                        <div />
                                        <Field
                                            id="bill_date"
                                            label="Bill date"
                                            error={errors.bill_date}
                                        >
                                            <Input
                                                id="bill_date"
                                                name="bill_date"
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
                                        <Field
                                            id="memo"
                                            label="Memo"
                                            error={errors.memo}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="memo"
                                                name="memo"
                                                maxLength={255}
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
                                                hint="Leave blank when there is none. Add tax codes to record tax per line."
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
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Record bill
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
