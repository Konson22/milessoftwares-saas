import { useState, type ReactNode } from 'react';
import { Pressable, ScrollView, Switch, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Sheet } from '@/components/ui/sheet';
import type { useAction } from '@/hooks/use-page';
import { decimalsFor } from '@/lib/currencies';
import { money, moneyIn, toMinorIn } from '@/lib/format';
import { fromBase, toBase, type Money } from '@/lib/pricing';
import { colors } from '@/lib/theme';

export type Action = ReturnType<typeof useAction>;
type Values = Record<string, string>;

/** A small form in a sheet, sent to `to`; closes when it is taken. */
export function FormSheet<V extends Values>({
  title,
  submit,
  method = 'POST',
  to,
  initial,
  action,
  onClose,
  clean,
  danger,
  children,
}: {
  title: string;
  submit: string;
  method?: 'POST' | 'PUT';
  to: string;
  initial: V;
  action: Action;
  onClose: () => void;
  clean?: (data: V) => unknown;
  danger?: boolean;
  children: (data: V, set: (key: keyof V, value: string) => void, errors: Record<string, string>) => ReactNode;
}) {
  const [data, setData] = useState<V>(initial);
  const set = (key: keyof V, value: string) => setData((d) => ({ ...d, [key]: value }));

  const send = async () => {
    if (await action.run(method, to, clean ? clean(data) : data)) onClose();
  };

  return (
    <Sheet visible title={title} onClose={onClose}>
      {children(data, set, action.errors)}
      <Button title={submit} variant={danger ? 'danger' : 'primary'} square loading={action.busy} onPress={send} />
    </Sheet>
  );
}

/** A page's filters in a sheet (dates, a category): shown when applied. */
export function FilterSheet<V extends Values>({
  title,
  initial,
  onApply,
  onClose,
  children,
}: {
  title: string;
  initial: V;
  onApply: (data: V) => void;
  onClose: () => void;
  children: (data: V, set: (key: keyof V, value: string) => void) => ReactNode;
}) {
  const [data, setData] = useState<V>(initial);
  const set = (key: keyof V, value: string) => setData((d) => ({ ...d, [key]: value }));
  return (
    <Sheet visible title={title} onClose={onClose}>
      {children(data, set)}
      <Button
        title="Show"
        square
        onPress={() => {
          onApply(data);
          onClose();
        }}
      />
    </Sheet>
  );
}

/** One of a few choices, as chips (the web's select). */
export function Choice<T extends string>({
  label,
  options,
  value,
  onChange,
  error,
  hint,
}: {
  label?: string;
  options: { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
  error?: string | null;
  hint?: string | null;
}) {
  return (
    <View className="gap-1.5">
      {label && <Text className="text-sm font-medium text-slate-700">{label}</Text>}
      <View accessibilityRole="radiogroup" className="flex-row flex-wrap gap-2">
        {options.map((o) => {
          const on = o.value === value;
          return (
            <Pressable
              key={o.value}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              onPress={() => onChange(o.value)}
              className={`min-h-10 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white active:bg-slate-50'}`}>
              <Text className={`text-sm ${on ? 'font-bold text-brand-800' : 'font-medium text-slate-700'}`}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
      {error ? <Text className="text-sm text-red-600">{error}</Text> : hint ? <Text className="text-sm text-slate-500">{hint}</Text> : null}
    </View>
  );
}

/** Many choices, scrolled sideways (a supplier, a category). */
export function ChoiceRow<T extends string>({
  label,
  options,
  value,
  onChange,
  error,
}: {
  label?: string;
  options: { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
  error?: string | null;
}) {
  return (
    <View className="gap-1.5">
      {label && <Text className="text-sm font-medium text-slate-700">{label}</Text>}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2" keyboardShouldPersistTaps="handled">
        {options.map((o) => {
          const on = o.value === value;
          return (
            <Pressable
              key={o.value}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              onPress={() => onChange(o.value)}
              className={`min-h-10 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white active:bg-slate-50'}`}>
              <Text className={`text-sm ${on ? 'font-bold text-brand-800' : 'font-medium text-slate-700'}`}>{o.label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
      {error ? <Text className="text-sm text-red-600">{error}</Text> : null}
    </View>
  );
}

/** A yes/no setting with a switch (the web's checkbox). */
export function Toggle({
  label,
  hint,
  value,
  onChange,
  error,
  disabled,
}: {
  label: string;
  hint?: string;
  value: boolean;
  onChange: (on: boolean) => void;
  error?: string | null;
  disabled?: boolean;
}) {
  return (
    <View className={`flex-row items-center gap-3 py-1 ${disabled ? 'opacity-60' : ''}`}>
      <View className="flex-1">
        <Text className="text-base font-medium text-slate-900">{label}</Text>
        {hint && <Text className="text-sm text-slate-500">{hint}</Text>}
        {error && <Text className="text-sm text-red-600">{error}</Text>}
      </View>
      <Switch accessibilityLabel={label} value={value} disabled={disabled} onValueChange={onChange} trackColor={{ true: colors.brand[600] }} />
    </View>
  );
}

/** A date as YYYY-MM-DD, typed. */
export function DateField({ label, value, onChange, error, hint }: { label: string; value: string; onChange: (v: string) => void; error?: string; hint?: string }) {
  return (
    <Field
      label={label}
      value={value}
      onChangeText={onChange}
      error={error}
      hint={hint ?? 'Year-month-day, as 2027-06-30'}
      placeholder="YYYY-MM-DD"
      keyboardType="numbers-and-punctuation"
      maxLength={10}
      autoCorrect={false}
      icon="calendar-outline"
    />
  );
}

/** What the pricing fields offer (pricingOptions in src/local/db/pages.ts). */
export interface PricingOptions {
  currency: string;
  decimals: number;
  secondCurrency: string | null;
  secondDecimals: number;
  /** 1 of the second currency in the shop's own; null until set under Settings. */
  rate: number | null;
}

/** The two currencies at a rate (a delivery's, or today's). */
export function moneyAt(options: PricingOptions, rate: number | null): Money {
  return {
    base: { code: options.currency, decimals: options.decimals },
    foreign: options.secondCurrency && rate && rate > 0 ? { code: options.secondCurrency, decimals: options.secondDecimals, rate } : null,
  };
}

/** "1,250.50" typed in `currency` → the shop's minor units (fractions kept); null when not an amount. */
export function enteredInBase(m: Money, amount: string, currency: string): number | null {
  if (!amount.trim()) return null;
  const minor = toMinorIn(amount, decimalsFor(currency));
  return minor === null ? null : toBase(m, minor, currency);
}

/** An amount in the shop's currency, with the second currency beside it when there is a rate. */
export function bothCurrencies(m: Money, baseMinor: number): string {
  const own = money(Math.round(baseMinor));
  return m.foreign ? `${own} · ${moneyIn(fromBase(m, baseMinor, m.foreign.code), m.foreign.code)}` : own;
}

/** "34%" of the selling price, or "—". */
export function marginOf(cost: number, price: number): { text: string; below: boolean } {
  if (price <= 0) return { text: '—', below: false };
  const percent = Math.round(((price - cost) / price) * 100);
  return { text: `${percent}%`, below: percent < 0 };
}

/** An amount typed in either currency: the field, and the currency beside it when there are two. */
export function PriceField({
  label,
  options,
  amount,
  currency,
  onAmount,
  onCurrency,
  error,
  hint,
  placeholder,
}: {
  label: string;
  options: PricingOptions;
  amount: string;
  currency: string;
  onAmount: (v: string) => void;
  onCurrency: (code: string) => void;
  error?: string;
  hint?: string;
  placeholder?: string;
}) {
  return (
    <View className="gap-1.5">
      <Field label={label} value={amount} onChangeText={onAmount} keyboardType="decimal-pad" error={error} hint={hint} placeholder={placeholder} prefix={options.secondCurrency ? undefined : options.currency} />
      {options.secondCurrency && (
        <View className="flex-row gap-2">
          {[options.currency, options.secondCurrency].map((code) => {
            const on = code === currency;
            return (
              <Pressable
                key={code}
                accessibilityRole="radio"
                accessibilityState={{ selected: on }}
                onPress={() => onCurrency(code)}
                className={`min-h-9 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white'}`}>
                <Text className={`text-sm ${on ? 'font-bold text-brand-800' : 'text-slate-700'}`}>In {code}</Text>
              </Pressable>
            );
          })}
        </View>
      )}
    </View>
  );
}

/** A heading and its rows, in a flat card. */
export function Panel({ title, right, children }: { title?: string; right?: ReactNode; children: ReactNode }) {
  return (
    <View className="border border-slate-200 bg-white">
      {(title || right) && (
        <View className="flex-row items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <Text className="flex-1 text-sm font-bold uppercase tracking-wider text-slate-600">{title}</Text>
          {right}
        </View>
      )}
      {children}
    </View>
  );
}

/** A line in a Panel: what it is on the left, figures on the right. */
export function PanelRow({
  title,
  subtitle,
  right,
  rightSub,
  tone,
  onPress,
  children,
  dim,
}: {
  title: string;
  subtitle?: string | null;
  right?: string | null;
  rightSub?: string | null;
  tone?: 'red' | 'amber' | 'green';
  onPress?: () => void;
  children?: ReactNode;
  dim?: boolean;
}) {
  const toneClass = tone === 'red' ? 'text-red-700' : tone === 'amber' ? 'text-amber-700' : tone === 'green' ? 'text-emerald-700' : 'text-slate-900';
  return (
    <Pressable
      disabled={!onPress}
      accessibilityRole={onPress ? 'button' : undefined}
      onPress={onPress}
      className={`gap-2 border-b border-slate-100 px-4 py-3 active:bg-brand-50 ${dim ? 'opacity-50' : ''}`}>
      <View className="flex-row items-start gap-3">
        <View className="flex-1 gap-0.5">
          <Text className="text-base font-semibold text-slate-900">{title}</Text>
          {!!subtitle && <Text className="text-sm text-slate-500">{subtitle}</Text>}
        </View>
        {(right || rightSub) && (
          <View className="items-end">
            {!!right && <Text className={`text-base font-semibold ${toneClass}`}>{right}</Text>}
            {!!rightSub && <Text className="text-xs text-slate-500">{rightSub}</Text>}
          </View>
        )}
      </View>
      {children}
    </Pressable>
  );
}

/** Small buttons under a row. */
export function RowActions({ children }: { children: ReactNode }) {
  return <View className="flex-row flex-wrap gap-2">{children}</View>;
}

export function SmallButton({ title, onPress, tone = 'brand', disabled }: { title: string; onPress: () => void; tone?: 'brand' | 'red'; disabled?: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      disabled={disabled}
      className={`min-h-9 justify-center border px-3 ${tone === 'red' ? 'border-red-200 active:bg-red-50' : 'border-brand-200 active:bg-brand-50'} ${disabled ? 'opacity-50' : ''}`}>
      <Text className={`text-sm font-semibold ${tone === 'red' ? 'text-red-700' : 'text-brand-700'}`}>{title}</Text>
    </Pressable>
  );
}

/** A figure with its label and a note, two to a row. */
export function Figure({ label, value, note, tone }: { label: string; value: string; note?: string; tone?: 'red' }) {
  return (
    <View className="min-w-[45%] flex-1 border border-slate-200 bg-white px-4 py-3">
      <Text className="text-xs text-slate-500">{label}</Text>
      <Text className={`text-lg font-bold ${tone === 'red' ? 'text-red-700' : 'text-slate-900'}`} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      {note && <Text className="text-xs text-slate-500">{note}</Text>}
    </View>
  );
}

export function Muted({ children }: { children: ReactNode }) {
  return <Text className="px-4 py-3 text-sm text-slate-500">{children}</Text>;
}
