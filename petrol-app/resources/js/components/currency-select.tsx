import { usePage } from '@inertiajs/react';
import { Select } from '@/components/field';
import type { SharedProps } from '@/types';

export type CurrencyChoice = { code: string; name: string };

/** A currency the station is allowed to take. Falls back to the workspace currency. */
export function CurrencySelect({
    id,
    name = 'currency',
    currencies,
    defaultValue,
}: {
    id: string;
    name?: string;
    currencies?: CurrencyChoice[];
    defaultValue?: string | null;
}) {
    const page = usePage().props as SharedProps;
    const rows = currencies ?? page.acceptedCurrencies ?? [];
    const fallback = defaultValue ?? page.currency ?? 'SSP';
    const options = rows.length
        ? rows.map((row) => ({
              value: row.code,
              label:
                  row.name && row.name !== row.code
                      ? `${row.code} · ${row.name}`
                      : row.code,
          }))
        : [{ value: fallback, label: fallback }];
    const selected = options.some((option) => option.value === fallback)
        ? fallback
        : options[0].value;

    return (
        <Select id={id} name={name} defaultValue={selected} options={options} />
    );
}
