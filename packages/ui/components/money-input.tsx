import { usePage } from '@inertiajs/react';
import * as React from 'react';
import { cn } from '../lib/utils';
import { Input } from './ui/input';

/**
 * The currencies a workspace takes amounts in, shared by every product as
 * the `money` page prop (Miles\PlatformClient\Money\EntryCurrencies::forPage).
 * The first is the workspace currency; the others carry its value for one unit.
 */
export type EntryCurrency = {
    code: string;
    decimals: number;
    rate: string | null;
    updatedAt?: string | null;
};

export type MoneyProp = { base: string; currencies: EntryCurrency[] };

export function useMoney(): MoneyProp | null {
    const props = usePage().props as { money?: MoneyProp | null };

    return props.money ?? null;
}

/** "lines[0][unit_cost]" → "lines[0][unit_cost_currency]"; "amount" → "amount_currency". */
export function currencyFieldName(name: string): string {
    return name.endsWith(']')
        ? `${name.slice(0, -1)}_currency]`
        : `${name}_currency`;
}

/**
 * A typed amount in the workspace currency's minor units, rounded like the
 * server does; null when blank, unreadable or the currency has no rate.
 */
export function toBaseMinor(
    money: MoneyProp | null,
    amount: string,
    code: string | undefined,
): number | null {
    const value = Number(amount.replace(/,/g, '').trim());
    if (amount.trim() === '' || !Number.isFinite(value)) {
        return null;
    }
    const base = money?.currencies[0];
    const baseScale = 10 ** (base?.decimals ?? 2);
    if (!money || !code || code === money.base) {
        return Math.round(value * baseScale);
    }
    const currency = money.currencies.find((row) => row.code === code);
    if (!currency?.rate) {
        return null;
    }

    return Math.round(value * Number(currency.rate) * baseScale);
}

/** The amount in the workspace currency, formatted, for the hint under a field. */
export function toBase(
    money: MoneyProp,
    amount: string,
    code: string,
): string | null {
    const minor = toBaseMinor(money, amount, code);
    const decimals = money.currencies[0]?.decimals ?? 2;
    if (minor === null) {
        return null;
    }

    return (minor / 10 ** decimals).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

const selectClass =
    'border-input bg-card h-8 border px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-2 disabled:opacity-50';

/**
 * One currency for a whole document (a purchase order, a receipt): its
 * amounts are all typed in it. Nothing when the workspace has one currency.
 */
export function CurrencyPicker({
    id,
    name = 'currency',
    value,
    defaultValue,
    onChange,
    disabled,
    className,
    label = 'Currency',
}: {
    id?: string;
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (code: string) => void;
    disabled?: boolean;
    className?: string;
    label?: string;
}) {
    const money = useMoney();
    if (!money || money.currencies.length < 2) {
        return null;
    }

    return (
        <select
            id={id ?? name}
            name={name}
            aria-label={label}
            className={cn(selectClass, className)}
            disabled={disabled}
            {...(value !== undefined
                ? {
                      value,
                      onChange: (event: React.ChangeEvent<HTMLSelectElement>) =>
                          onChange?.(event.target.value),
                  }
                : {
                      defaultValue: defaultValue ?? money.base,
                      onChange: (event: React.ChangeEvent<HTMLSelectElement>) =>
                          onChange?.(event.target.value),
                  })}
        >
            {money.currencies.map((currency) => (
                <option key={currency.code} value={currency.code}>
                    {currency.code}
                </option>
            ))}
        </select>
    );
}

/**
 * A labelled CurrencyPicker for a form, with a hint under it. Nothing when
 * the workspace has one currency.
 */
export function CurrencyField({
    label = 'Currency',
    hint,
    ...picker
}: React.ComponentProps<typeof CurrencyPicker> & { hint?: string }) {
    const money = useMoney();
    if (!money || money.currencies.length < 2) {
        return null;
    }
    const id = picker.id ?? picker.name ?? 'currency';

    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <CurrencyPicker {...picker} id={id} label={label} />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

/**
 * An amount and the currency it is typed in. The currency goes in
 * `{name}_currency` (blank form field: the workspace currency). Works
 * uncontrolled inside a <Form>, or controlled with value/currency.
 */
export function MoneyInput({
    id,
    name,
    value,
    defaultValue,
    onChange,
    currency,
    defaultCurrency,
    onCurrencyChange,
    currencyName,
    showConversion = true,
    className,
    inputClassName,
    ...props
}: Omit<
    React.ComponentProps<'input'>,
    'value' | 'defaultValue' | 'onChange'
> & {
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    currency?: string;
    defaultCurrency?: string;
    onCurrencyChange?: (code: string) => void;
    currencyName?: string;
    showConversion?: boolean;
    inputClassName?: string;
}) {
    const money = useMoney();
    const [typed, setTyped] = React.useState(value ?? defaultValue ?? '');
    const [chosen, setChosen] = React.useState(
        currency ?? defaultCurrency ?? money?.base ?? '',
    );
    const amount = value ?? typed;
    const code = currency ?? chosen;
    const several = !!money && money.currencies.length > 1;
    const converted =
        several && showConversion && code !== money.base
            ? toBase(money, amount, code)
            : null;

    return (
        <div className={cn('grid gap-1', className)}>
            <div className="flex">
                <Input
                    id={id}
                    name={name}
                    inputMode="decimal"
                    autoComplete="off"
                    className={cn(several && 'border-r-0', inputClassName)}
                    {...(value !== undefined ? { value } : { defaultValue })}
                    onChange={(event) => {
                        setTyped(event.target.value);
                        onChange?.(event.target.value);
                    }}
                    {...props}
                />
                {several && (
                    <select
                        aria-label="Currency"
                        name={
                            currencyName ??
                            (name ? currencyFieldName(name) : undefined)
                        }
                        className={cn(selectClass, 'shrink-0')}
                        value={code}
                        disabled={props.disabled}
                        onChange={(event) => {
                            setChosen(event.target.value);
                            onCurrencyChange?.(event.target.value);
                        }}
                    >
                        {money.currencies.map((row) => (
                            <option key={row.code} value={row.code}>
                                {row.code}
                            </option>
                        ))}
                    </select>
                )}
            </div>
            {converted && (
                <p className="text-xs text-muted-foreground tabular-nums">
                    ≈ {money!.base} {converted}
                </p>
            )}
        </div>
    );
}
