import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button, type IconName } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Card, SectionTitle } from '@/components/ui/misc';
import { haptic } from '@/lib/feedback';
import { currencyCode, currencyDecimals, money, toMajorInput, toMinor } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { SaleMethod } from '@/lib/types';
import { useAuth } from '@/store/auth';

const ICONS: Record<SaleMethod, IconName> = {
  cash: 'cash-outline',
  mobile_money: 'phone-portrait-outline',
  card: 'card-outline',
  bank_transfer: 'business-outline',
  other: 'ellipsis-horizontal-circle-outline',
  account: 'book-outline',
};

export interface Payment {
  method: SaleMethod;
  /** Typed text; empty means the exact amount. */
  tendered: string;
  reference: string;
}

export function usePayment() {
  return useState<Payment>({ method: 'cash', tendered: '', reference: '' });
}

/** What goes to the shop, or why it cannot yet. */
export function paymentPayload(payment: Payment, totalMinor: number) {
  if (payment.method === 'account') {
    return { ok: true as const, payment_method: payment.method, payment_reference: null, amount_tendered_minor: null };
  }
  if (payment.method !== 'cash') {
    return { ok: true as const, payment_method: payment.method, payment_reference: payment.reference.trim() || null, amount_tendered_minor: null };
  }
  const tendered = payment.tendered.trim() ? toMinor(payment.tendered) : totalMinor;
  if (tendered === null) return { ok: false as const, error: 'Enter the cash given as an amount.' };
  if (tendered < totalMinor) return { ok: false as const, error: 'The cash given is less than the total.' };
  return { ok: true as const, payment_method: payment.method, payment_reference: null, amount_tendered_minor: tendered };
}

/** Payment method (as the branch takes them), cash given and change, or the payment's reference. */
export function PaymentForm({ payment, onChange, totalMinor }: { payment: Payment; onChange: (p: Payment) => void; totalMinor: number }) {
  const counter = useAuth((s) => s.counter);
  const methods: { value: SaleMethod; label: string }[] = [
    ...(counter?.payment_methods ?? []),
    ...(counter?.can.sell_on_account ? [{ value: 'account' as const, label: 'On account' }] : []),
  ];
  const tendered = payment.tendered.trim() ? toMinor(payment.tendered) : totalMinor;
  const change = tendered === null ? 0 : Math.max(0, tendered - totalMinor);

  return (
    <>
      <SectionTitle>Payment method</SectionTitle>
      <View className="flex-row flex-wrap gap-3" accessibilityRole="radiogroup">
        {methods.map((m) => {
          const active = payment.method === m.value;
          return (
            <Pressable
              key={m.value}
              accessibilityRole="radio"
              accessibilityState={{ selected: active }}
              onPress={() => {
                haptic.tap();
                onChange({ ...payment, method: m.value });
              }}
              className={`min-h-20 min-w-[46%] flex-1 items-center justify-center gap-1.5 border-2 p-3 ${
                active ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white'
              }`}>
              <Ionicons name={ICONS[m.value]} size={26} color={active ? colors.brand[700] : colors.muted} />
              <Text className={`text-base font-semibold ${active ? 'text-brand-800' : 'text-slate-700'}`}>{m.label}</Text>
            </Pressable>
          );
        })}
      </View>

      {payment.method === 'account' ? (
        <Text className="text-sm text-slate-500">Goes on the customer’s account as an invoice, to pay later.</Text>
      ) : payment.method === 'cash' ? (
        <Card className="gap-3">
          <Field
            label="Cash given"
            prefix={currencyCode()}
            value={payment.tendered}
            onChangeText={(t) => onChange({ ...payment, tendered: t })}
            keyboardType="decimal-pad"
            placeholder={toMajorInput(totalMinor)}
            error={tendered === null ? 'Enter an amount.' : null}
          />
          <View className="flex-row flex-wrap gap-2">
            {quickAmounts(totalMinor).map((amount) => (
              <Button
                key={amount}
                title={amount === totalMinor ? 'Exact' : money(amount, false)}
                variant="secondary"
                onPress={() => onChange({ ...payment, tendered: amount === totalMinor ? '' : toMajorInput(amount) })}
              />
            ))}
          </View>
          <View className="flex-row items-center justify-between bg-emerald-50 px-4 py-3">
            <Text className="text-base font-semibold text-emerald-900">Change</Text>
            <Text className="text-2xl font-bold text-emerald-900">{money(change)}</Text>
          </View>
        </Card>
      ) : (
        <Field
          label="Reference (optional)"
          value={payment.reference}
          onChangeText={(t) => onChange({ ...payment, reference: t })}
          autoCapitalize="characters"
          maxLength={64}
          placeholder={payment.method === 'mobile_money' ? 'e.g. the transaction code' : 'Terminal or transfer reference'}
        />
      )}
    </>
  );
}

/** The exact amount, then the next few round notes above it. */
function quickAmounts(totalMinor: number): number[] {
  const unit = 10 ** currencyDecimals();
  const out = new Set<number>([totalMinor]);
  for (const note of [50, 100, 500, 1000, 5000]) {
    const v = Math.ceil(totalMinor / (note * unit)) * note * unit;
    if (v > totalMinor) out.add(v);
    if (out.size >= 4) break;
  }
  return [...out];
}
