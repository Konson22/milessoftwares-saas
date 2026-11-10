import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field } from '@/components/field';
import { store } from '@/routes/rates';

export default function Rates({
    books,
    rates,
    canManage,
    currencies = [],
}: {
    books: string;
    currencies?: { code: string; name: string }[];
    rates: {
        id: string;
        base: string;
        quote: string;
        rate: string;
        from: string;
        to: string | null;
    }[];
    canManage: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Exchange rates" />
            <PageHeader
                title="Exchange rates"
                description={`This station’s rates into ${books}. A new rate does not change sales already recorded.`}
            />
            <Panel className="mb-4">
                <PanelBody>
                    {rates.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No rate yet.
                        </p>
                    ) : (
                        <ul className="text-sm">
                            {rates.map((rate) => (
                                <li key={rate.id}>
                                    1 {rate.base} = {rate.rate} {rate.quote}{' '}
                                    from {rate.from}
                                    {rate.to
                                        ? ` until ${rate.to}`
                                        : ' (current)'}
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
            {canManage && (
                <Panel>
                    <PanelHeader title="New rate" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field
                                id="base_currency"
                                label="Base"
                                error={errors.base_currency}
                            >
                                <CurrencySelect
                                    id="base_currency"
                                    name="base_currency"
                                    currencies={currencies}
                                    defaultValue={
                                        currencies.find(
                                            (row) => row.code !== books,
                                        )?.code ?? 'USD'
                                    }
                                />
                            </Field>
                            <Field
                                id="quote_currency"
                                label="Quote"
                                error={errors.quote_currency}
                            >
                                <CurrencySelect
                                    id="quote_currency"
                                    name="quote_currency"
                                    currencies={currencies}
                                    defaultValue={books}
                                />
                            </Field>
                            <Field
                                id="rate"
                                label="Quote per 1 base"
                                error={errors.rate}
                            >
                                <Input
                                    id="rate"
                                    name="rate"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Button type="submit">Save rate</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
