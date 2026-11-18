import type { ReactNode } from 'react';
import { Text, View } from 'react-native';

import { openPage } from '@/hooks/use-page';
import { money, plural } from '@/lib/format';
import { withQuery } from '@/lib/pages';

import { DayPicker, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

type Group = { name: string; count: number; totalMinor: number };

interface Props {
  day: string;
  takings: { count: number; totalMinor: number; averageMinor: number; discountMinor: number; refundedMinor: number };
  byMethod: Group[];
  byCashier: Group[];
  byWaiter: Group[];
  bestSellers: { name: string; quantity: number }[];
  discounts: { id: string; number: string; discountMinor: number; reason: string | null; by: string }[];
  voidedBills: { id: string; number: string; totalMinor: number; reason: string | null; by: string | null }[];
  cancelledOrders: { id: string; number: string; reason: string | null }[];
  voidedLines: { id: string; order: string; orderId: string; name: string; quantity: number; reason: string | null; by: string | null }[];
}

function Section({ title, empty, children }: { title: string; empty: boolean; children: ReactNode }) {
  return <Panel title={title}>{empty ? <Text className="px-4 py-4 text-base text-slate-500">None.</Text> : children}</Panel>;
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <View className="min-w-[45%] flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3">
      <Text className="text-sm text-slate-500">{label}</Text>
      <Text className="text-xl font-bold text-slate-900">{value}</Text>
    </View>
  );
}

const by = (who: string | null) => (who ? ` · ${who}` : '');

/** One day at the branch: takings and how they came in, what sold, and what was given away or taken back. */
export function DayReportScreen({ page, reload, go }: ScreenProps<Props>) {
  const p = page.props;
  const groups = (rows: Group[]) =>
    rows.map((row) => <ListRow key={row.name} title={row.name} subtitle={plural(row.count, 'bill')} right={<Text className="text-base font-semibold text-slate-900">{money(row.totalMinor)}</Text>} />);

  return (
    <Scroll onRefresh={reload}>
      <View className="flex-row items-center justify-between border border-slate-200 bg-white px-4 py-3">
        <View>
          <Text className="text-sm text-slate-500">Takings · {plural(p.takings.count, 'bill')}</Text>
          <Text className="text-2xl font-bold text-slate-900">{money(p.takings.totalMinor)}</Text>
        </View>
        <DayPicker day={p.day} onChange={(next) => go(withQuery('reports', { day: next }))} />
      </View>
      <View className="flex-row flex-wrap gap-2">
        <Figure label="Average bill" value={money(p.takings.averageMinor)} />
        <Figure label="Discounts" value={money(p.takings.discountMinor)} />
        <Figure label="Refunds" value={money(p.takings.refundedMinor)} />
        <Figure label="Orders cancelled" value={String(p.cancelledOrders.length)} />
      </View>

      <Section title="Paid by" empty={p.byMethod.length === 0}>
        {groups(p.byMethod)}
      </Section>
      <Section title="Best sellers" empty={p.bestSellers.length === 0}>
        {p.bestSellers.map((item) => (
          <ListRow key={item.name} title={item.name} right={<Text className="text-base font-semibold text-slate-900">{item.quantity} sold</Text>} />
        ))}
      </Section>
      <Section title="Taken by cashier" empty={p.byCashier.length === 0}>
        {groups(p.byCashier)}
      </Section>
      <Section title="Sold by waiter" empty={p.byWaiter.length === 0}>
        {groups(p.byWaiter)}
      </Section>
      <Section title="Discounts" empty={p.discounts.length === 0}>
        {p.discounts.map((bill) => (
          <ListRow
            key={bill.id}
            title={`Bill ${bill.number}`}
            subtitle={`${bill.reason ?? ''}${by(bill.by)}`}
            right={<Text className="text-base text-slate-900">− {money(bill.discountMinor)}</Text>}
            onPress={() => openPage(`bills/${bill.id}`)}
          />
        ))}
      </Section>
      <Section title="Refunds" empty={p.voidedBills.length === 0}>
        {p.voidedBills.map((bill) => (
          <ListRow
            key={bill.id}
            title={`Bill ${bill.number}`}
            subtitle={`${bill.reason ?? ''}${by(bill.by)}`}
            right={<Text className="text-base text-slate-900">{money(bill.totalMinor)}</Text>}
            onPress={() => openPage(`bills/${bill.id}`)}
          />
        ))}
      </Section>
      <Section title="Lines voided" empty={p.voidedLines.length === 0}>
        {p.voidedLines.map((line) => (
          <ListRow
            key={line.id}
            title={`${line.quantity} × ${line.name}`}
            subtitle={`${line.reason ?? ''}${by(line.by)}`}
            right={<Text className="text-sm text-slate-500">{line.order}</Text>}
            onPress={() => openPage(`orders/${line.orderId}`)}
          />
        ))}
      </Section>
      <Section title="Orders cancelled" empty={p.cancelledOrders.length === 0}>
        {p.cancelledOrders.map((order) => (
          <ListRow key={order.id} title={order.number} subtitle={order.reason ?? ''} onPress={() => openPage(`orders/${order.id}`)} />
        ))}
      </Section>
    </Scroll>
  );
}
