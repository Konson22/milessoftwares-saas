import * as Crypto from 'expo-crypto';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useBusiness } from '@/hooks/use-business';
import { openPage, useAction, type FieldErrors } from '@/hooks/use-page';
import { ApiError, errorMessage } from '@/lib/api/client';
import { decimalsFor } from '@/lib/currencies';
import { haptic } from '@/lib/feedback';
import { amountInput, dateTime, litres, moneyIn, time } from '@/lib/format';
import { act } from '@/lib/pages';
import { printFuelSale } from '@/lib/receipt';
import { scope } from '@/offline/cache';
import { queuedFor, useOutbox } from '@/offline/outbox';
import { toast } from '@/store/toast';

import { Chips, Detail, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

/* ---------------------------------------------------------------- the sum */

/**
 * The station's arithmetic (petrol-app App\Support\Volume), so the phone
 * shows and sends exactly what the station will post: payments must add up
 * to the total to the last minor unit.
 */
export function toMilli(text: string): number | null {
  const m = /^(\d{1,7})(?:\.(\d{1,3}))?$/.exec(text.trim());
  if (!m) return null;
  return Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0'));
}

/** "12.5" in a currency → its minor units; null when it is not an amount in it. */
export function minorIn(text: string, code: string): number | null {
  const digits = decimalsFor(code);
  const m = /^(\d{1,12})(?:\.(\d*))?$/.exec(amountInput(text));
  if (!m) return null;
  const fraction = m[2] ?? '';
  if (fraction.length > digits) return null;
  return Number(m[1]) * 10 ** digits + Number(fraction.padEnd(digits, '0') || '0');
}

/** Minor units → what the station takes as an amount ("1250.00"). */
export const amountText = (minor: number, code: string) => {
  const digits = decimalsFor(code);
  return digits === 0 ? String(minor) : (minor / 10 ** digits).toFixed(digits);
};

export const milliForAmount = (minor: number, price: number) => Math.floor((minor * 1000 + Math.floor(price / 2)) / price);
export const totalFor = (milli: number, price: number) => Math.floor((milli * price + 500) / 1000);

/* ---------------------------------------------------------------- sell */

interface SellProps {
  shift: { id: string; number: string } | null;
  nozzles: { id: string; name: string; code: string; product: string; priceMinor: number | null; currency: string; attendantIds: string[] }[];
  collectionMode: 'attendant' | 'cashier' | 'both' | null;
  attendants: { id: string; name: string }[];
  methods: { id: string; name: string; kind: string }[];
  customers: { id: string; name: string }[];
  canCollectForOthers: boolean;
}

interface PaymentRow {
  methodId: string | null;
  amount: string;
  reference: string;
  customerId: string | null;
}

const blankPayment = (methodId: string | null): PaymentRow => ({ methodId, amount: '', reference: '', customerId: null });

/**
 * A sale at the pump: the nozzle, litres or an amount, who served, how it
 * was paid. Without a connection it waits on the phone and is sent when it
 * can, under the same key, so it is never posted twice.
 */
export function SellScreen({ page, path, reload }: ScreenProps<SellProps>) {
  const { shift, nozzles, attendants, methods, customers, collectionMode } = page.props;
  const owner = scope();
  const enqueue = useOutbox((s) => s.enqueue);

  // The phone makes each sale's key (a kept page's key may already have been used); a new one after each sale.
  const [key, setKey] = useState(() => Crypto.randomUUID());

  const [nozzleId, setNozzleId] = useState<string | null>(nozzles.length === 1 ? nozzles[0].id : null);
  const [by, setBy] = useState<'amount' | 'litres'>('amount');
  const [value, setValue] = useState('');
  const [chosenAttendant, setAttendantId] = useState<string | null>(null);
  const [collectedBy, setCollectedBy] = useState<'attendant' | 'cashier'>(collectionMode === 'cashier' ? 'cashier' : 'attendant');
  const [split, setSplit] = useState(false);
  const cash = methods.find((m) => m.kind === 'cash') ?? methods[0] ?? null;
  const [payments, setPayments] = useState<PaymentRow[]>([blankPayment(cash?.id ?? null)]);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [picking, setPicking] = useState<number | null>(null);

  const nozzle = nozzles.find((n) => n.id === nozzleId) ?? null;
  const currency = nozzle?.currency ?? page.props.currency ?? 'USD';
  const price = nozzle?.priceMinor ?? null;

  // Only those working this nozzle on the open shift.
  const serving = nozzle && nozzle.attendantIds.length > 0 ? attendants.filter((a) => nozzle.attendantIds.includes(a.id)) : attendants;
  // The one chosen while they serve this pump; the only one when there is one.
  const attendantId = chosenAttendant && serving.some((a) => a.id === chosenAttendant) ? chosenAttendant : serving.length === 1 ? serving[0].id : null;

  const amountMinor = by === 'amount' ? minorIn(value, currency) : null;
  const milli = price === null ? null : by === 'litres' ? toMilli(value) : amountMinor === null ? null : milliForAmount(amountMinor, price);
  const total = price && milli ? totalFor(milli, price) : null;

  const paid = split ? payments.reduce((sum, p) => sum + (minorIn(p.amount, currency) ?? 0), 0) : (total ?? 0);
  const left = total === null ? null : total - paid;

  const methodOf = (id: string | null) => methods.find((m) => m.id === id) ?? null;
  const change = (i: number, row: Partial<PaymentRow>) => setPayments((rows) => rows.map((r, j) => (j === i ? { ...r, ...row } : r)));

  /** Split: the first payment starts at the whole total; back to one: the rest go. */
  const toggleSplit = () => {
    setPayments((rows) => (split ? rows.slice(0, 1) : [{ ...rows[0], amount: total === null ? '' : amountText(total, currency) }]));
    setSplit(!split);
  };

  const reset = () => {
    setValue('');
    setSplit(false);
    setPayments([blankPayment(cash?.id ?? null)]);
    setErrors({});
  };

  const payload = () => {
    const rows = split ? payments : [{ ...payments[0], amount: total === null ? '' : amountText(total, currency) }];
    return {
      nozzle_id: nozzleId ?? '',
      litres: by === 'litres' ? value.trim() : null,
      amount: by === 'amount' ? amountInput(value) : null,
      attendant_id: attendantId ?? '',
      collected_by: collectionMode === 'both' ? collectedBy : collectionMode === 'cashier' ? 'cashier' : 'attendant',
      payments: rows.map((p) => ({
        method_id: p.methodId ?? '',
        amount: amountInput(p.amount),
        reference: p.reference.trim() || null,
        customer_id: methodOf(p.methodId)?.kind === 'credit' ? p.customerId : null,
      })),
    };
  };

  const label = () => `${nozzle?.code ?? '?'} · ${nozzle?.product ?? ''} · ${total === null ? '' : moneyIn(total, currency)}`;

  /** Kept on the phone and sent when the station answers again. */
  const keep = () => {
    if (!owner) return;
    enqueue({ id: key, owner, kind: 'sale.record', payload: payload(), label: label() });
    haptic.success();
    toast('No connection: the sale is kept on this phone and sent when the connection is back.', 'warning');
    setKey(Crypto.randomUUID());
    reset();
  };

  const check = (): string | null => {
    if (!nozzle) return 'Choose the pump.';
    if (price === null) return `${nozzle.product} has no selling price yet.`;
    if (milli === null || milli <= 0 || total === null) return by === 'litres' ? 'Enter the litres.' : 'Enter the amount.';
    if (!attendantId) return 'Choose who served.';
    if (split && left !== 0) return `Payments must add up to ${moneyIn(total, currency)}.`;
    const rows = split ? payments : payments.slice(0, 1);
    if (rows.some((p) => !p.methodId)) return 'Choose how it was paid.';
    if (rows.some((p) => methodOf(p.methodId)?.kind === 'credit' && !p.customerId)) return 'A credit sale needs a customer.';
    return null;
  };

  const submit = async () => {
    const problem = check();
    if (problem) {
      haptic.error();
      toast(problem, 'error');
      return;
    }
    if (page.stale) return keep();
    setBusy(true);
    setErrors({});
    try {
      const result = await act('POST', 'sales', { ...payload(), idempotency_key: key }, path);
      haptic.success();
      if (result.message) toast(result.message);
      setKey(Crypto.randomUUID());
      reset();
      if (result.redirect && result.redirect !== path) openPage(result.redirect);
      void reload();
    } catch (e) {
      if (e instanceof ApiError && e.offline) return keep();
      haptic.error();
      if (e instanceof ApiError && e.status === 422) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      toast(errorMessage(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  if (!shift) {
    return (
      <Scroll onRefresh={reload}>
        <EmptyState icon="time-outline" title="No shift is open" message="Sales start once a supervisor opens the shift with the meter readings.">
          <Button title="Shifts" icon="time-outline" variant="outline" onPress={() => router.navigate('/shifts' as never)} />
        </EmptyState>
      </Scroll>
    );
  }

  return (
    <Scroll onRefresh={reload}>
      {page.stale ? <Banner tone="warning">No connection. Sales are kept on this phone and sent when the connection is back.</Banner> : null}

      <Panel title={`Pump · shift ${shift.number}`}>
        <View className="gap-2 p-4">
          {nozzles.length === 0 ? <Text className="text-base text-slate-500">No active pumps at this station.</Text> : null}
          <View className="flex-row flex-wrap gap-2">
            {nozzles.map((n) => {
              const on = n.id === nozzleId;
              return (
                <Pressable
                  key={n.id}
                  accessibilityRole="radio"
                  accessibilityState={{ selected: on }}
                  onPress={() => setNozzleId(n.id)}
                  className={`min-w-[30%] flex-1 border px-3 py-2.5 ${on ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white active:bg-brand-50'}`}>
                  <Text className={`text-lg font-bold ${on ? 'text-white' : 'text-slate-900'}`}>{n.code}</Text>
                  <Text className={`text-sm ${on ? 'text-brand-100' : 'text-slate-600'}`} numberOfLines={1}>
                    {n.product}
                  </Text>
                  <Text className={`text-xs ${on ? 'text-brand-100' : 'text-slate-500'}`}>{n.priceMinor === null ? 'No price' : `${moneyIn(n.priceMinor, n.currency)} / L`}</Text>
                </Pressable>
              );
            })}
          </View>
          {errors.nozzle_id ? <Text className="text-sm text-red-700">{errors.nozzle_id}</Text> : null}
        </View>
      </Panel>

      <Panel title="How much">
        <View className="gap-3 p-4">
          <Chips
            options={[
              { value: 'amount', label: `Amount (${currency})` },
              { value: 'litres', label: 'Litres' },
            ]}
            value={by}
            onChange={(next) => {
              setBy(next);
              setValue('');
            }}
          />
          <Field
            value={value}
            onChangeText={setValue}
            keyboardType="decimal-pad"
            placeholder={by === 'litres' ? '0.000' : '0'}
            prefix={by === 'amount' ? currency : undefined}
            error={errors.litres ?? errors.amount}
            accessibilityLabel={by === 'litres' ? 'Litres' : 'Amount'}
          />
          {total !== null && milli !== null ? (
            <View className="border border-slate-200 bg-slate-50 px-3 py-2">
              <Detail label="Litres" value={litres(milli / 1000)} />
              <Detail label="Total" value={moneyIn(total, currency)} strong />
            </View>
          ) : null}
        </View>
      </Panel>

      <Panel title="Served by">
        <View className="gap-3 p-4">
          {serving.length === 0 ? (
            <Text className="text-base text-slate-500">No attendant is assigned to this pump on the shift.</Text>
          ) : (
            <Chips options={serving.map((a) => ({ value: a.id, label: a.name }))} value={attendantId} onChange={setAttendantId} />
          )}
          {errors.attendant_id ? <Text className="text-sm text-red-700">{errors.attendant_id}</Text> : null}
          {collectionMode === 'both' ? (
            <>
              <Text className="text-sm font-medium text-slate-700">Money taken by</Text>
              <Chips
                options={[
                  { value: 'attendant', label: 'The attendant' },
                  { value: 'cashier', label: 'The cashier' },
                ]}
                value={collectedBy}
                onChange={setCollectedBy}
              />
            </>
          ) : null}
        </View>
      </Panel>

      <Panel
        title="Payment"
        right={<Button title={split ? 'One payment' : 'Split'} variant="ghost" onPress={toggleSplit} />}>
        <View className="gap-4 p-4">
          {(split ? payments : payments.slice(0, 1)).map((row, i) => {
            const method = methodOf(row.methodId);
            const customer = customers.find((c) => c.id === row.customerId);
            return (
              <View key={i} className={`gap-2 ${i > 0 ? 'border-t border-slate-100 pt-4' : ''}`}>
                <Chips scroll options={methods.map((m) => ({ value: m.id, label: m.name }))} value={row.methodId} onChange={(id) => change(i, { methodId: id })} />
                {split ? <Field value={row.amount} onChangeText={(t) => change(i, { amount: t })} keyboardType="decimal-pad" prefix={currency} placeholder="0" accessibilityLabel="Amount paid" /> : null}
                {method && method.kind !== 'cash' && method.kind !== 'credit' ? (
                  <Field value={row.reference} onChangeText={(t) => change(i, { reference: t })} placeholder="Reference (optional)" accessibilityLabel="Reference" />
                ) : null}
                {method?.kind === 'credit' ? <Button title={customer ? customer.name : 'Choose the customer'} icon="person-outline" variant="outline" onPress={() => setPicking(i)} /> : null}
                {split && payments.length > 1 ? <Button title="Remove" variant="ghost" icon="trash-outline" onPress={() => setPayments((rows) => rows.filter((_, j) => j !== i))} /> : null}
              </View>
            );
          })}
          {split ? (
            <View className="flex-row items-center justify-between">
              <Text className={`text-sm font-semibold ${left === 0 ? 'text-emerald-700' : 'text-amber-700'}`}>
                {left === null ? '' : left === 0 ? 'Paid in full' : left > 0 ? `${moneyIn(left, currency)} left` : `${moneyIn(-left, currency)} too much`}
              </Text>
              <Button
                title="Add payment"
                icon="add"
                variant="outline"
                disabled={payments.length >= methods.length}
                onPress={() => setPayments((rows) => [...rows, { ...blankPayment(methods.find((m) => !rows.some((r) => r.methodId === m.id))?.id ?? null), amount: left && left > 0 ? amountText(left, currency) : '' }])}
              />
            </View>
          ) : null}
          {errors.payments ? <Text className="text-sm text-red-700">{errors.payments}</Text> : null}
        </View>
      </Panel>

      <Button title={total === null ? 'Record sale' : `Record sale · ${moneyIn(total, currency)}`} size="lg" icon="checkmark" loading={busy} onPress={() => void submit()} />

      <Sheet visible={picking !== null} onClose={() => setPicking(null)} title="Customer">
        <CustomerPicker
          customers={customers}
          onPick={(id) => {
            if (picking !== null) change(picking, { customerId: id });
            setPicking(null);
          }}
        />
      </Sheet>
    </Scroll>
  );
}

function CustomerPicker({ customers, onPick }: { customers: { id: string; name: string }[]; onPick: (id: string) => void }) {
  const [search, setSearch] = useState('');
  const shown = customers.filter((c) => c.name.toLowerCase().includes(search.trim().toLowerCase())).slice(0, 50);
  return (
    <>
      <Field value={search} onChangeText={setSearch} placeholder="Search" icon="search" accessibilityLabel="Search customers" />
      {shown.length === 0 ? <Text className="text-base text-slate-500">No customer by that name.</Text> : null}
      {shown.map((c) => (
        <ListRow key={c.id} title={c.name} onPress={() => onPick(c.id)} />
      ))}
    </>
  );
}

/* ---------------------------------------------------------------- the list */

interface SaleRow {
  id: string;
  number: string;
  status: string;
  product: string;
  nozzle: string;
  attendant: string;
  litres: string;
  totalMinor: number;
  currency: string;
  soldAt: string;
}

/** The latest sales at this branch, with what waits on this phone above them. */
export function SalesScreen({ page, reload }: ScreenProps<{ sales: SaleRow[]; canSell: boolean }>) {
  const { sales, canSell } = page.props;
  const owner = scope();
  const queued = useOutbox((s) => queuedFor(s.entries, owner).all.filter((e) => e.kind === 'sale.record'));

  return (
    <Scroll onRefresh={reload}>
      {canSell ? <Button title="Sell fuel" icon="add-circle-outline" size="lg" onPress={() => router.navigate('/sell' as never)} /> : null}
      {queued.length > 0 ? (
        <Panel title="On this phone" right={<Button title="Outbox" variant="ghost" onPress={() => router.push('/outbox')} />}>
          {queued.map((e) => (
            <ListRow key={e.id} title={e.label} subtitle={e.error ?? `Waiting to be sent · ${time(e.queued_at)}`} right={<Badge label={e.error ? 'refused' : 'waiting'} tone={e.error ? 'red' : 'amber'} />} />
          ))}
        </Panel>
      ) : null}
      <Panel>
        {sales.length === 0 ? (
          <EmptyState icon="receipt-outline" title="No sales yet" />
        ) : (
          sales.map((sale) => (
            <ListRow
              key={sale.id}
              title={`${sale.number} · ${sale.nozzle} · ${sale.product}`}
              subtitle={`${time(sale.soldAt)} · ${litres(sale.litres)} · ${sale.attendant}`}
              muted={sale.status === 'voided'}
              right={
                <>
                  {sale.status === 'voided' ? <Badge label="voided" tone="red" /> : null}
                  <Text className="text-base font-semibold text-slate-900">{moneyIn(sale.totalMinor, sale.currency)}</Text>
                </>
              }
              onPress={() => openPage(`sales/${sale.id}`)}
            />
          ))
        )}
      </Panel>
    </Scroll>
  );
}

/* ---------------------------------------------------------------- one sale */

interface SaleProps {
  sale: SaleRow & {
    priceMinor: number;
    costMinor: number;
    voidReason: string | null;
    payments: { method: string; kind: string; amountMinor: number; reference: string | null }[];
  };
  canVoid: boolean;
}

/** One sale: what was sold, how it was paid; print the receipt, or void it. */
export function SaleScreen({ page, path, reload }: ScreenProps<SaleProps>) {
  const { sale, canVoid } = page.props;
  const business = useBusiness();
  const { run, busy, errors } = useAction(path, reload);
  const [voiding, setVoiding] = useState(false);
  const [reason, setReason] = useState('');

  const print = async () => {
    try {
      await printFuelSale(sale, business);
    } catch (e) {
      toast(errorMessage(e), 'error');
    }
  };

  return (
    <Scroll onRefresh={reload}>
      <Panel title={`Receipt ${sale.number}`} right={sale.status === 'voided' ? <Badge label="voided" tone="red" /> : <Badge label="posted" tone="green" />}>
        <View className="px-4 py-3">
          <Detail label="Pump" value={`${sale.nozzle} · ${sale.product}`} />
          <Detail label="Litres" value={litres(sale.litres)} />
          <Detail label="Price" value={`${moneyIn(sale.priceMinor, sale.currency)} / L`} />
          <Detail label="Served by" value={sale.attendant} />
          <Detail label="Sold" value={dateTime(sale.soldAt)} />
          <Detail label="Total" value={moneyIn(sale.totalMinor, sale.currency)} strong />
        </View>
      </Panel>
      <Panel title="Paid">
        {sale.payments.map((p, i) => (
          <ListRow key={i} title={p.method.replace(/_/g, ' ')} subtitle={p.reference} right={<Text className="text-base font-semibold text-slate-900">{moneyIn(p.amountMinor, sale.currency)}</Text>} />
        ))}
      </Panel>
      {sale.voidReason ? <Banner tone="error">Voided: {sale.voidReason}</Banner> : null}
      <Button title="Print receipt" icon="print-outline" variant="outline" size="lg" onPress={() => void print()} />
      {canVoid ? <Button title="Void sale" icon="close-circle-outline" variant="danger" onPress={() => setVoiding(true)} /> : null}

      <Sheet visible={voiding} onClose={() => setVoiding(false)} title={`Void ${sale.number}`}>
        <Text className="text-base text-slate-600">The fuel goes back to its tank and the payment is reversed.</Text>
        <Field label="Reason" value={reason} onChangeText={setReason} error={errors.reason} />
        <Button
          title="Void sale"
          variant="danger"
          size="lg"
          loading={busy}
          disabled={reason.trim() === ''}
          onPress={async () => {
            if (await run('POST', `sales/${sale.id}/void`, { reason: reason.trim() })) setVoiding(false);
          }}
        />
      </Sheet>
    </Scroll>
  );
}
