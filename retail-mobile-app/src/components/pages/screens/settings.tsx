import { router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { useAction } from '@/hooks/use-page';
import { CURRENCIES } from '@/lib/currencies';
import type { PaymentMethod } from '@/lib/types';
import { useAuth } from '@/store/auth';

import { Choice, ChoiceRow, Panel, Toggle } from '../forms';
import { PageScroll } from '../kit';
import type { ScreenProps } from '../types';

interface Settings {
  name: string;
  address: string | null;
  contact: string | null;
  currency: string;
  decimals: number;
  second_currency: string;
  exchange_rate: string;
  receipt_header: string | null;
  receipt_footer: string | null;
  paper_size: '80mm' | '58mm';
  show_customer: boolean;
  show_staff: boolean;
  payment_methods: PaymentMethod[];
  max_discount_percent: number;
  shifts_required: boolean;
  default_float: string;
  blind_close: boolean;
  require_customer_always: boolean;
  require_customer_above: string;
  expiring_days: number;
  credit_days: number;
  quote_days: number;
}

interface Props {
  settings: Settings;
  paymentMethods: { value: PaymentMethod; label: string }[];
}

/** The shop, its counter's rules, its receipt, and its backups. */
export function SettingsScreen({ page, path, reload, loading }: ScreenProps<Props>) {
  const { settings, paymentMethods } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    ...settings,
    address: settings.address ?? '',
    contact: settings.contact ?? '',
    receipt_header: settings.receipt_header ?? '',
    receipt_footer: settings.receipt_footer ?? '',
    max_discount_percent: String(settings.max_discount_percent),
    expiring_days: String(settings.expiring_days),
    credit_days: String(settings.credit_days),
    quote_days: String(settings.quote_days),
  });
  const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));
  const manager = useAuth((s) => s.me?.user.role === 'manager');

  const submit = async () => {
    const sent = await run('PUT', 'settings', {
      ...data,
      max_discount_percent: Number(data.max_discount_percent) || 0,
      expiring_days: Number(data.expiring_days) || 0,
      credit_days: Number(data.credit_days) || 0,
      quote_days: Number(data.quote_days) || 0,
    });
    // The name and currencies show everywhere.
    if (sent) await useAuth.getState().refreshMe();
  };

  const rate = Number(data.exchange_rate);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      {manager && (
        <Panel title="Backups">
          <View className="gap-3 p-4">
            <Text className="text-sm text-slate-500">
              Everything lives on this phone. A copy is made each day; share one off the phone now and then.
            </Text>
            <Button title="Backups" variant="outline" icon="save-outline" square onPress={() => router.push('/backup')} />
          </View>
        </Panel>
      )}

      <Panel title="The shop">
        <View className="gap-4 p-4">
          <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} />
          <Field label="Address" value={data.address} onChangeText={(t) => set('address', t)} error={errors.address} />
          <Field label="Phone" value={data.contact} onChangeText={(t) => set('contact', t)} error={errors.contact} keyboardType="phone-pad" />
          <Field
            label="Currency"
            value={data.currency}
            editable={false}
            hint={`${data.decimals} decimal place(s), set at setup`}
            error={errors.currency}
          />
        </View>
      </Panel>

      <Panel title="Second currency and exchange rate">
        <View className="gap-4 p-4">
          <Banner tone="info">
            Suppliers may bill in {data.second_currency || 'USD'}: buying prices are entered in it and turned into {data.currency} at this rate. Prices at the
            counter also show in it. Stock already received keeps the rate it was bought at.
          </Banner>
          <ChoiceRow
            label="Second currency"
            options={[{ value: '', label: 'None' }, ...CURRENCIES.filter(([code]) => code !== data.currency).map(([code]) => ({ value: code, label: code }))]}
            value={data.second_currency}
            onChange={(v) => set('second_currency', v)}
            error={errors.second_currency}
          />
          {!!data.second_currency && (
            <Field
              label={`1 ${data.second_currency} in ${data.currency}`}
              value={data.exchange_rate}
              onChangeText={(t) => set('exchange_rate', t)}
              keyboardType="decimal-pad"
              error={errors.exchange_rate}
              hint={rate > 0 ? `${data.currency} 1,000 = ${data.second_currency} ${(1000 / rate).toFixed(2)}` : 'The rate today, as 4500'}
            />
          )}
        </View>
      </Panel>

      <Panel title="The counter">
        <View className="gap-4 p-4">
          <View className="gap-1">
            <Text className="text-sm font-medium text-slate-700">Ways of paying taken</Text>
            {paymentMethods.map((m) => (
              <Toggle
                key={m.value}
                label={m.label}
                value={data.payment_methods.includes(m.value)}
                onChange={(on) => set('payment_methods', on ? [...data.payment_methods, m.value] : data.payment_methods.filter((v) => v !== m.value))}
              />
            ))}
            {errors.payment_methods && <Text className="text-sm text-red-600">{errors.payment_methods}</Text>}
          </View>
          <Field
            label="Most discount a line (%)"
            value={data.max_discount_percent}
            onChangeText={(t) => set('max_discount_percent', t)}
            keyboardType="number-pad"
            error={errors.max_discount_percent}
            hint="Supervisors and managers; cashiers give none"
          />
          <Field
            label="Name the customer on sales over"
            value={data.require_customer_above}
            onChangeText={(t) => set('require_customer_above', t)}
            keyboardType="decimal-pad"
            error={errors.require_customer_above}
            hint="Empty: never needed"
          />
          <Toggle label="Name the customer on every sale" value={data.require_customer_always} onChange={(on) => set('require_customer_always', on)} />
          <Toggle
            label="Selling needs an open shift"
            hint="A counted cash drawer"
            value={data.shifts_required}
            onChange={(on) => set('shifts_required', on)}
            error={errors.shifts_required}
          />
          <Toggle
            label="Cashiers count the drawer blind"
            hint="They do not see what it should hold"
            value={data.blind_close}
            onChange={(on) => set('blind_close', on)}
          />
          <Field
            label="Usual float"
            value={data.default_float}
            onChangeText={(t) => set('default_float', t)}
            keyboardType="decimal-pad"
            error={errors.default_float}
            hint="Offered when a shift opens"
          />
        </View>
      </Panel>

      <Panel title="Stock">
        <View className="gap-4 p-4">
          <Field
            label="Expiring soon is within (days)"
            value={data.expiring_days}
            onChangeText={(t) => set('expiring_days', t)}
            keyboardType="number-pad"
            error={errors.expiring_days}
          />
        </View>
      </Panel>

      <Panel title="Customers">
        <View className="gap-4 p-4">
          <Field
            label="An invoice is due after (days)"
            value={data.credit_days}
            onChangeText={(t) => set('credit_days', t)}
            keyboardType="number-pad"
            error={errors.credit_days}
            hint="0: on the day it is issued"
          />
          <Field
            label="A quotation is valid for (days)"
            value={data.quote_days}
            onChangeText={(t) => set('quote_days', t)}
            keyboardType="number-pad"
            error={errors.quote_days}
          />
        </View>
      </Panel>

      <Panel title="The receipt">
        <View className="gap-4 p-4">
          <Field label="Line at the top" value={data.receipt_header} onChangeText={(t) => set('receipt_header', t)} error={errors.receipt_header} placeholder="TIN 123456" />
          <Field label="Line at the bottom" value={data.receipt_footer} onChangeText={(t) => set('receipt_footer', t)} error={errors.receipt_footer} />
          <Choice
            label="Paper"
            options={[
              { value: '80mm', label: '80 mm roll' },
              { value: '58mm', label: '58 mm roll' },
            ]}
            value={data.paper_size}
            onChange={(v) => set('paper_size', v)}
            error={errors.paper_size}
          />
          <Toggle label="Show the customer" value={data.show_customer} onChange={(on) => set('show_customer', on)} />
          <Toggle label="Show who served" value={data.show_staff} onChange={(on) => set('show_staff', on)} />
        </View>
      </Panel>

      <Button title="Save settings" icon="checkmark" square size="lg" loading={busy} onPress={submit} />
    </PageScroll>
  );
}
