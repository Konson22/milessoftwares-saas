import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useBusiness } from '@/hooks/use-business';
import { openPage, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api/client';
import { dateTime, money, plural, time } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { printPaidBill } from '@/lib/receipt';
import { toast } from '@/store/toast';

import { DayPicker, Detail, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface ListProps {
  day: string;
  bills: {
    id: string;
    number: string;
    paidAt: string;
    paidBy: string;
    table: string | null;
    customerName: string | null;
    totalMinor: number;
    paymentMethod: string;
    status: 'paid' | 'voided';
  }[];
  takings: { count: number; totalMinor: number };
}

/** The bills paid at this branch on a day, and what was kept. */
export function BillsScreen({ page, reload, go }: ScreenProps<ListProps>) {
  const { day, bills, takings } = page.props;

  return (
    <Scroll onRefresh={reload}>
      <View className="flex-row items-center justify-between border border-slate-200 bg-white px-4 py-3">
        <View>
          <Text className="text-xs font-medium text-slate-500">Kept · {plural(takings.count, 'bill')}</Text>
          <Text className="text-2xl font-bold text-slate-900">{money(takings.totalMinor)}</Text>
        </View>
        <DayPicker day={day} onChange={(next) => go(withQuery('bills', { day: next }))} />
      </View>
      <Panel>
        {bills.length === 0 ? (
          <EmptyState icon="receipt-outline" title="No bills this day" />
        ) : (
          bills.map((bill) => (
            <ListRow
              key={bill.id}
              title={`${bill.number} · ${bill.table ? `Table ${bill.table}` : (bill.customerName ?? 'Takeaway')}`}
              subtitle={`${time(bill.paidAt)} · ${bill.paymentMethod} · ${bill.paidBy}`}
              muted={bill.status === 'voided'}
              right={
                <>
                  {bill.status === 'voided' ? <Badge label="voided" tone="red" /> : null}
                  <Text className="text-base font-semibold text-slate-900">{money(bill.totalMinor)}</Text>
                </>
              }
              onPress={() => openPage(`bills/${bill.id}`)}
            />
          ))
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
  canVoid: boolean;
}

/** One paid bill: printed again, or voided whole with the money given back. */
export function BillScreen({ page, path, reload }: ScreenProps<BillProps>) {
  const { bill, canVoid } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const business = useBusiness();
  const [voiding, setVoiding] = useState(false);
  const [reason, setReason] = useState('');
  const where = bill.order.table ? `Table ${bill.order.table}` : (bill.customerName ?? 'Takeaway');

  const print = () => printPaidBill(bill, business).catch((e) => toast(errorMessage(e), 'error'));

  const submitVoid = async () => {
    if (await run('POST', `bills/${bill.id}/void`, { reason: reason.trim() })) setVoiding(false);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <View className="flex-row items-start gap-2">
          <View className="flex-1">
            <Text className="text-2xl font-bold text-slate-900">Bill {bill.number}</Text>
            <Text className="text-base text-slate-500">
              {where} · {dateTime(bill.paidAt)} · {bill.paidBy}
            </Text>
          </View>
          <Badge label={bill.status} tone={bill.status === 'paid' ? 'green' : 'red'} />
        </View>

        {bill.status === 'voided' ? (
          <Banner tone="error">
            Voided {bill.voidedAt ? dateTime(bill.voidedAt) : ''} by {bill.voidedBy}: {bill.voidReason}
          </Banner>
        ) : null}

        <Panel title="Lines">
          {bill.lines.map((line) => (
            <View key={line.id} className="flex-row items-center gap-2 border-b border-slate-100 px-4 py-2">
              <View className="flex-1">
                <Text className="text-base text-slate-900">
                  {line.quantity} × {line.name}
                </Text>
                <Text className="text-sm text-slate-500">{money(line.unitPriceMinor)} each</Text>
              </View>
              <Text className="text-base font-medium text-slate-900">{money(line.totalMinor)}</Text>
            </View>
          ))}
          <View className="px-4 py-2">
            <Detail label="Total" value={money(bill.totalMinor)} strong />
          </View>
        </Panel>

        <Panel title="Payment">
          <View className="px-4 py-2">
            <Detail label="Method" value={bill.paymentMethod} />
            {bill.paymentReference ? <Detail label="Reference" value={bill.paymentReference} /> : null}
            {bill.tenderedMinor !== null ? (
              <>
                <Detail label="Given" value={money(bill.tenderedMinor)} />
                <Detail label="Change" value={money(bill.changeMinor ?? 0)} />
              </>
            ) : null}
            <Detail
              label="Order"
              value={
                <Pressable accessibilityRole="link" onPress={() => openPage(`orders/${bill.order.id}`)}>
                  <Text className="text-base font-semibold text-brand-700">{bill.order.number}</Text>
                </Pressable>
              }
            />
          </View>
        </Panel>

        <View className="gap-2">
          <Button title="Print" icon="print-outline" variant="outline" size="lg" onPress={print} />
          {canVoid && bill.status === 'paid' ? (
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
        <Text className="text-base text-slate-600">The money is given back from your shift, and the drinks go back into stock.</Text>
        <Field label="Reason" value={reason} onChangeText={setReason} maxLength={255} autoFocus error={errors.reason} />
        <Button title={`Void and refund ${money(bill.totalMinor)}`} variant="danger" size="lg" loading={busy} disabled={reason.trim() === ''} onPress={submitVoid} />
      </Sheet>
    </>
  );
}
