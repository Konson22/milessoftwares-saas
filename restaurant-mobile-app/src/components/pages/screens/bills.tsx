import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { Button, IconButton, type IconName } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useBusiness } from '@/hooks/use-business';
import { openPage, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api/client';
import { dateTime, money, plural, time } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { printPaidBill, type ReceiptLines } from '@/lib/receipt';
import { colors, webNoOutline } from '@/lib/theme';
import { toast } from '@/store/toast';

import { Chips, DayPicker, Detail, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface ListBill {
  id: string;
  number: string;
  paidAt: string;
  paidBy: string;
  table: string | null;
  customerName: string | null;
  totalMinor: number;
  paymentMethod: string;
  status: 'paid' | 'voided';
}

interface ListProps {
  day: string;
  bills: ListBill[];
  takings: { count: number; totalMinor: number };
}

const VOIDED = '__voided';

const whereOf = (bill: { table: string | null; customerName: string | null }) =>
  bill.table ? `Table ${bill.table}` : (bill.customerName ?? 'Takeaway');

const METHOD_ICON: Record<string, IconName> = {
  Cash: 'cash-outline',
  'Mobile money': 'phone-portrait-outline',
  Card: 'card-outline',
  'Bank transfer': 'business-outline',
};

/** The bills paid at this branch on a day: what was kept, by how it was paid, and each bill. */
export function BillsScreen({ page, reload, go }: ScreenProps<ListProps>) {
  const { day, bills, takings } = page.props;
  const [filter, setFilter] = useState<string>('');
  const [search, setSearch] = useState('');

  const kept = bills.filter((bill) => bill.status === 'paid');
  const voided = bills.length - kept.length;
  // Kept money by how it was paid, largest first.
  const byMethod = useMemo(() => {
    const totals = new Map<string, { count: number; totalMinor: number }>();
    for (const bill of bills) {
      if (bill.status !== 'paid') continue;
      const now = totals.get(bill.paymentMethod) ?? { count: 0, totalMinor: 0 };
      totals.set(bill.paymentMethod, { count: now.count + 1, totalMinor: now.totalMinor + bill.totalMinor });
    }
    return [...totals.entries()].map(([method, sum]) => ({ method, ...sum })).sort((a, b) => b.totalMinor - a.totalMinor);
  }, [bills]);

  const query = search.trim().toLowerCase();
  const shown = bills.filter(
    (bill) =>
      (filter === '' || (filter === VOIDED ? bill.status === 'voided' : bill.status === 'paid' && bill.paymentMethod === filter)) &&
      (query === '' || [bill.number, whereOf(bill), bill.paidBy].some((text) => text.toLowerCase().includes(query))),
  );
  const shownMinor = shown.filter((bill) => bill.status === 'paid').reduce((sum, bill) => sum + bill.totalMinor, 0);
  const filtered = filter !== '' || query !== '';

  const changeDay = (next: string | null) => {
    setFilter('');
    setSearch('');
    go(withQuery('bills', { day: next }));
  };

  return (
    <Scroll onRefresh={reload}>
      <View className="border border-slate-200 bg-white">
        <View className="flex-row items-center justify-between border-b border-slate-100 px-2 py-1">
          <DayPicker day={day} onChange={changeDay} />
          {voided > 0 ? (
            <View className="pr-2">
              <Badge label={`${voided} voided`} tone="red" />
            </View>
          ) : null}
        </View>
        <View className="px-4 pb-3 pt-3">
          <Text className="text-sm font-medium text-slate-500">Kept · {plural(takings.count, 'bill')}</Text>
          <Text className="text-3xl font-bold text-slate-900" numberOfLines={1} adjustsFontSizeToFit>
            {money(takings.totalMinor)}
          </Text>
        </View>
        {byMethod.length > 0 ? (
          <View className="flex-row flex-wrap gap-2 px-4 pb-4">
            {byMethod.map((row) => {
              const on = filter === row.method;
              return (
                <Pressable
                  key={row.method}
                  accessibilityRole="button"
                  accessibilityState={{ selected: on }}
                  accessibilityLabel={`${row.method}: ${money(row.totalMinor)}, ${plural(row.count, 'bill')}. Show only these.`}
                  onPress={() => setFilter(on ? '' : row.method)}
                  className={`min-w-[47%] flex-1 border px-3 py-2 ${on ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-slate-50 active:bg-brand-50'}`}>
                  <View className="flex-row items-center gap-1.5">
                    <Ionicons name={METHOD_ICON[row.method] ?? 'wallet-outline'} size={14} color={on ? colors.brand[700] : colors.muted} />
                    <Text className="flex-1 text-xs font-medium text-slate-600" numberOfLines={1}>
                      {row.method} · {row.count}
                    </Text>
                  </View>
                  <Text className="mt-0.5 text-base font-bold text-slate-900" numberOfLines={1}>
                    {money(row.totalMinor)}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        ) : null}
      </View>

      {bills.length > 0 ? (
        <View className="gap-3">
          <View className="min-h-12 flex-row items-center border border-slate-300 bg-white px-3">
            <Ionicons name="search" size={18} color={colors.muted} />
            <TextInput
              value={search}
              onChangeText={setSearch}
              placeholder="Bill number, table, customer, cashier"
              placeholderTextColor={colors.faint}
              accessibilityLabel="Find a bill"
              className="ml-2 flex-1 py-3 text-base text-slate-900"
              style={webNoOutline}
            />
            {search ? <IconButton icon="close" label="Clear" onPress={() => setSearch('')} /> : null}
          </View>
          {byMethod.length > 1 || voided > 0 ? (
            <Chips
              scroll
              options={[
                { value: '', label: `All (${bills.length})` },
                ...byMethod.map((row) => ({ value: row.method, label: row.method })),
                ...(voided > 0 ? [{ value: VOIDED, label: `Voided (${voided})` }] : []),
              ]}
              value={filter}
              onChange={setFilter}
            />
          ) : null}
        </View>
      ) : null}

      <Panel
        title={filtered ? plural(shown.length, 'bill') : undefined}
        right={filtered && filter !== VOIDED ? <Text className="text-base font-bold text-slate-900">{money(shownMinor)}</Text> : undefined}>
        {bills.length === 0 ? (
          <EmptyState icon="receipt-outline" title="No bills this day" message="Bills appear here once a table or takeaway is paid." />
        ) : shown.length === 0 ? (
          <View className="items-center gap-2 px-4 py-8">
            <Text className="text-center text-base text-slate-500">No bill matches.</Text>
            <Button
              title="Show all"
              variant="ghost"
              onPress={() => {
                setFilter('');
                setSearch('');
              }}
            />
          </View>
        ) : (
          shown.map((bill) => {
            const isVoided = bill.status === 'voided';
            return (
              <ListRow
                key={bill.id}
                icon={isVoided ? 'ban-outline' : (METHOD_ICON[bill.paymentMethod] ?? 'wallet-outline')}
                title={whereOf(bill)}
                subtitle={`${bill.number} · ${time(bill.paidAt)} · ${bill.paidBy}`}
                muted={isVoided}
                right={
                  <View className="items-end">
                    <Text className={`text-base font-semibold ${isVoided ? 'text-slate-500 line-through' : 'text-slate-900'}`}>{money(bill.totalMinor)}</Text>
                    {isVoided ? <Badge label="voided" tone="red" /> : <Text className="text-xs text-slate-500">{bill.paymentMethod}</Text>}
                  </View>
                }
                onPress={() => openPage(`bills/${bill.id}`)}
              />
            );
          })
        )}
      </Panel>
    </Scroll>
  );
}

export interface BillProps {
  bill: {
    id: string;
    number: string;
    status: 'paid' | 'voided';
    paidAt: string;
    paidBy: string;
    order: { id: string; number: string; table: string | null };
    customerName: string | null;
    subtotalMinor: number;
    discountMinor: number;
    discountReason: string | null;
    part: number | null;
    parts: number | null;
    totalMinor: number;
    paymentMethod: string;
    paymentReference: string | null;
    tenderedMinor: number | null;
    changeMinor: number | null;
    voidedAt: string | null;
    voidedBy: string | null;
    voidReason: string | null;
    lines: { id: string; name: string; quantity: number; unitPriceMinor: number; totalMinor: number }[];
  };
  /** The branch's own lines on its receipts; missing on a bill kept on the phone offline. */
  receipt?: ReceiptLines | null;
  canVoid: boolean;
}

/** One paid bill: printed again, or voided whole with the money given back. */
export function BillScreen({ page, path, reload }: ScreenProps<BillProps>) {
  const { bill, receipt, canVoid } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const business = useBusiness();
  const [voiding, setVoiding] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [reason, setReason] = useState('');
  const isVoided = bill.status === 'voided';
  const where = bill.order.table ? `Table ${bill.order.table}` : (bill.customerName ?? 'Takeaway');
  const items = bill.lines.reduce((sum, line) => sum + line.quantity, 0);

  const print = async () => {
    setPrinting(true);
    try {
      await printPaidBill(bill, business, receipt);
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setPrinting(false);
    }
  };

  const submitVoid = async () => {
    if (await run('POST', `bills/${bill.id}/void`, { reason: reason.trim() })) setVoiding(false);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <View className="border border-slate-200 bg-white p-4">
          <View className="flex-row items-start gap-2">
            <View className="flex-1">
              <Text className="text-sm font-medium text-slate-500">Bill {bill.number}</Text>
              <Text className="text-xl font-bold text-slate-900" numberOfLines={1}>
                {where}
              </Text>
            </View>
            <Badge label={isVoided ? 'Voided' : 'Paid'} tone={isVoided ? 'red' : 'green'} />
          </View>
          <Text className={`mt-2 text-3xl font-bold ${isVoided ? 'text-slate-500 line-through' : 'text-slate-900'}`} numberOfLines={1} adjustsFontSizeToFit>
            {money(bill.totalMinor)}
          </Text>
          <Text className="mt-1 text-sm text-slate-500">
            {bill.paymentMethod} · {dateTime(bill.paidAt)} · {bill.paidBy}
          </Text>
          {bill.parts ? (
            <View className="mt-2 flex-row">
              <Badge label={`Share ${bill.part} of ${bill.parts}`} tone="blue" />
            </View>
          ) : null}
        </View>

        {isVoided ? (
          <Banner tone="error">
            Voided{bill.voidedAt ? ` ${dateTime(bill.voidedAt)}` : ''}
            {bill.voidedBy ? ` by ${bill.voidedBy}` : ''}: {bill.voidReason}
          </Banner>
        ) : null}

        <Panel title={plural(items, 'item')}>
          {bill.lines.map((line) => (
            <View key={line.id} className="flex-row items-center gap-3 border-b border-slate-100 px-4 py-2.5">
              <Text className="w-8 text-base font-bold text-slate-900">{line.quantity}×</Text>
              <View className="flex-1">
                <Text className="text-base text-slate-900">{line.name}</Text>
                {line.quantity > 1 ? <Text className="text-sm text-slate-500">{money(line.unitPriceMinor)} each</Text> : null}
              </View>
              <Text className="text-base font-medium text-slate-900">{money(line.totalMinor)}</Text>
            </View>
          ))}
          <View className="px-4 py-2">
            {bill.discountMinor > 0 ? (
              <>
                <Detail label="Subtotal" value={money(bill.subtotalMinor)} />
                <Detail label={bill.discountReason ? `Discount (${bill.discountReason})` : 'Discount'} value={`− ${money(bill.discountMinor)}`} />
              </>
            ) : null}
            <Detail label="Total" value={money(bill.totalMinor)} strong />
          </View>
        </Panel>

        <Panel title="Payment">
          <View className="px-4 py-2">
            <Detail label="Paid by" value={bill.paymentMethod} />
            {bill.paymentReference ? <Detail label="Reference" value={bill.paymentReference} /> : null}
            {bill.tenderedMinor !== null ? (
              <>
                <Detail label="Cash given" value={money(bill.tenderedMinor)} />
                <Detail label="Change" value={money(bill.changeMinor ?? 0)} />
              </>
            ) : null}
          </View>
          <ListRow icon="restaurant-outline" title={`Order ${bill.order.number}`} subtitle={where} onPress={() => openPage(`orders/${bill.order.id}`)} />
        </Panel>

        <View className="gap-2">
          <Button title={isVoided ? 'Print (voided)' : 'Print receipt'} icon="print-outline" variant="outline" size="lg" loading={printing} onPress={() => void print()} />
          {canVoid && !isVoided ? (
            <Button
              title="Void and refund"
              icon="ban-outline"
              variant="ghost"
              onPress={() => {
                setReason('');
                setVoiding(true);
              }}
            />
          ) : null}
        </View>
      </Scroll>

      <Sheet visible={voiding} onClose={() => setVoiding(false)} title={`Void bill ${bill.number}`}>
        <View className="border border-red-200 bg-red-50 px-4 py-3">
          <Text className="text-sm text-red-800">Refund from your shift</Text>
          <Text className="text-2xl font-bold text-red-800">{money(bill.totalMinor)}</Text>
        </View>
        <Text className="text-base text-slate-600">The money is given back from your shift, and the drinks go back into stock. This cannot be undone.</Text>
        <Field
          label="Reason"
          value={reason}
          onChangeText={setReason}
          placeholder="Charged twice, wrong table…"
          maxLength={255}
          autoFocus
          error={errors.reason}
        />
        <Button title={`Void and refund ${money(bill.totalMinor)}`} variant="danger" size="lg" loading={busy} disabled={reason.trim() === ''} onPress={submitVoid} />
      </Sheet>
    </>
  );
}
