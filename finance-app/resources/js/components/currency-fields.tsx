import { usePage } from '@inertiajs/react';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';

/** A currency with a rate in the table: its latest rate, in the books' currency. */
export type CurrencyOption = { code: string; rate: string; date: string };

/**
 * The currency of an invoice or bill, and its rate: blank for the rate in
 * the table on the document's date. Nothing without other currencies.
 */
export function CurrencyFields({
    currencies,
    value,
    onChange,
    errors,
}: {
    currencies: CurrencyOption[];
    value: string;
    onChange: (currency: string) => void;
    errors: Record<string, string>;
}) {
    const { currency: books } = usePage().props;
    const chosen = currencies.find((option) => option.code === value);

    if (currencies.length === 0) {
        return null;
    }

    return (
        <>
            <Field id="currency" label="Currency" error={errors.currency}>
                <Select
                    id="currency"
                    name="currency"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                >
                    <option value="">{books}</option>
                    {currencies.map((option) => (
                        <option key={option.code} value={option.code}>
                            {option.code}
                        </option>
                    ))}
                </Select>
            </Field>
            {chosen && (
                <Field
                    id="rate"
                    label={`${books} per ${chosen.code}`}
                    error={errors.rate}
                    hint="Blank for the rate on the date."
                >
                    <Input
                        id="rate"
                        name="rate"
                        inputMode="decimal"
                        placeholder={chosen.rate}
                    />
                </Field>
            )}
        </>
    );
}
