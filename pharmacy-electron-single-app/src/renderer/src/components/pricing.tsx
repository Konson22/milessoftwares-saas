import { money, moneyIn, toMinorIn } from '@/lib/format';

import { decimalsFor } from '../../../shared/currencies';
import { fromBase, toBase, type Money } from '../../../shared/pricing';
import { Select } from './ui';

/** What the pricing fields offer (pricingOptions in src/main/db/pages.ts). */
export interface PricingOptions {
  currency: string;
  decimals: number;
  secondCurrency: string | null;
  secondDecimals: number;
  /** 1 of the second currency in the pharmacy's own; null until set under Settings. */
  rate: number | null;
}

/** The two currencies at a rate (a delivery's, or today's). */
export function moneyAt(options: PricingOptions, rate: number | null): Money {
  return {
    base: { code: options.currency, decimals: options.decimals },
    foreign: options.secondCurrency && rate && rate > 0 ? { code: options.secondCurrency, decimals: options.secondDecimals, rate } : null,
  };
}

/** "1,250.50" typed in `currency` → the pharmacy's minor units (fractions kept); null when not an amount. */
export function enteredInBase(m: Money, amount: string, currency: string): number | null {
  if (!amount.trim()) return null;
  const minor = toMinorIn(amount, decimalsFor(currency));
  return minor === null ? null : toBase(m, minor, currency);
}

/** An amount in the pharmacy's currency, with the second currency beside it when there is a rate. */
export function bothCurrencies(m: Money, baseMinor: number): string {
  const own = money(Math.round(baseMinor));
  return m.foreign ? `${own} · ${moneyIn(fromBase(m, baseMinor, m.foreign.code), m.foreign.code)}` : own;
}

export function CurrencySelect({ options, value, onChange, className }: { options: PricingOptions; value: string; onChange: (code: string) => void; className?: string }) {
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)} className={className ?? 'w-20 shrink-0'}>
      <option value={options.currency}>{options.currency}</option>
      {options.secondCurrency ? <option value={options.secondCurrency}>{options.secondCurrency}</option> : null}
    </Select>
  );
}
