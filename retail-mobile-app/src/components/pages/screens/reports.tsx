import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { openPage } from '@/hooks/use-page';
import { withQuery } from '@/lib/pages';
import { dateTime, fullDate, money, prettyDate } from '@/lib/format';

import { DateField, Figure, FilterSheet, Muted, Panel, PanelRow, SmallButton } from '../forms';
import { FilterChips, PageScroll } from '../kit';
import type { ScreenProps } from '../types';

interface Grouped {
  key: string;
  count: number;
  total: number;
}

interface Props {
  filters: { from: string; to: string };
  totals: {
    salesCount: number;
    grossMinor: number;
    discountMinor: number;
    returnsMinor: number;
    netMinor: number;
    costMinor: number;
    profitMinor: number;
    expensesMinor: number;
    netProfitMinor: number;
    voidedCount: number;
  };
  expensesByCategory: (Grouped & { type: string })[];
  byDay: Grouped[];
  byMethod: Grouped[];
  byStaff: Grouped[];
  credit: { owedMinor: number; overdueMinor: number; overdueCount: number; received: Grouped[] };
  topProducts: { name: string; unit: string; units: number; total: number }[];
  stock: {
    valueMinor: number;
    expiredValueMinor: number;
    expiring: { product_id: string; name: string; batch_number: string; expires_on: string; quantity: number; unit: string }[];
    low: { id: string; label: string; unit: string; sellable: number; reorderLevel: number }[];
  };
  shifts: {
    id: string;
    number: string;
    user: string;
    status: string;
    openedAt: string;
    closedAt: string | null;
    expectedMinor: number;
    countedMinor: number | null;
    differenceMinor: number | null;
    note: string | null;
  }[];
  expiringDays: number;
  today?: string;
}

const GROUPS = [
  { value: 'day', label: 'By day' },
  { value: 'method', label: 'By way of paying' },
  { value: 'staff', label: 'By who sold' },
] as const;

/** Sales, profit, shifts and stock over a range of days. */
export function ReportsScreen({ page, loading, reload, setQuery }: ScreenProps<Props>) {
  const p = page.props;
  const t = p.totals;
  const [range, setRange] = useState(false);
  const [group, setGroup] = useState<(typeof GROUPS)[number]['value']>('day');
  const rows = group === 'day' ? p.byDay.map((r) => ({ ...r, key: prettyDate(r.key) })) : group === 'method' ? p.byMethod : p.byStaff;

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <View className="flex-row items-center justify-between gap-3">
        <Text className="flex-1 text-base font-semibold text-slate-700">
          {prettyDate(p.filters.from)} to {prettyDate(p.filters.to)}
        </Text>
        <Button title="Dates" variant="outline" icon="calendar-outline" square onPress={() => setRange(true)} />
      </View>

      <View className="flex-row flex-wrap gap-3">
        <Figure label="Net sales" value={money(t.netMinor)} note={`${t.salesCount} sale(s); returns ${money(t.returnsMinor)}`} />
        <Figure label="Cost of what was sold" value={money(t.costMinor)} note="At each batch’s cost" />
        <Figure label="Gross profit" value={money(t.profitMinor)} note={t.netMinor ? `${Math.round((t.profitMinor / t.netMinor) * 100)}% of net sales` : '—'} />
        <Figure label="Expenses" value={money(t.expensesMinor)} note="Paid in these days" />
        <Figure label="Net profit" value={money(t.netProfitMinor)} note="Gross profit less expenses" tone={t.netProfitMinor < 0 ? 'red' : undefined} />
        <Figure label="Discounts given" value={money(t.discountMinor)} note={`${t.voidedCount} sale(s) voided`} />
      </View>

      <Panel title="Sales">
        <View className="px-4 pt-3">
          <FilterChips options={[...GROUPS]} value={group} onChange={setGroup} />
        </View>
        {rows.length === 0 ? (
          <Muted>No sales.</Muted>
        ) : (
          rows.map((r) => <PanelRow key={r.key} title={r.key} subtitle={`${r.count} sale(s)`} right={money(r.total)} />)
        )}
      </Panel>

      <Panel
        title="Expenses by category"
        right={<SmallButton title="All expenses" onPress={() => openPage(withQuery('expenses', { from: p.filters.from, to: p.filters.to }))} />}>
        {p.expensesByCategory.length === 0 ? (
          <Muted>No expenses in these days.</Muted>
        ) : (
          p.expensesByCategory.map((r) => <PanelRow key={r.key} title={r.key} subtitle={`${r.type} · ${r.count} payment(s)`} right={money(r.total)} />)
        )}
      </Panel>

      <Panel title="Customer accounts" right={<SmallButton title="Invoices" onPress={() => openPage('invoices')} />}>
        <View className="flex-row flex-wrap gap-3 p-4">
          <Figure label="Owed now" value={money(p.credit.owedMinor)} note="By every customer" />
          <Figure
            label="Overdue now"
            value={money(p.credit.overdueMinor)}
            note={`${p.credit.overdueCount} invoice(s)`}
            tone={p.credit.overdueCount ? 'red' : undefined}
          />
        </View>
        {p.credit.received.length === 0 ? (
          <Muted>No payments from customers in these days.</Muted>
        ) : (
          p.credit.received.map((r) => <PanelRow key={r.key} title={`Paid in: ${r.key}`} subtitle={`${r.count} payment(s)`} right={money(r.total)} />)
        )}
      </Panel>

      <Panel title="Best sellers">
        {p.topProducts.length === 0 ? (
          <Muted>No sales in these days.</Muted>
        ) : (
          p.topProducts.map((m) => <PanelRow key={m.name} title={m.name} subtitle={`${m.units} ${m.unit} sold`} right={money(m.total)} />)
        )}
      </Panel>

      <Panel title="Shifts">
        {p.shifts.length === 0 ? (
          <Muted>No shifts in these days.</Muted>
        ) : (
          p.shifts.map((s) => (
            <PanelRow
              key={s.id}
              title={`${s.number} · ${s.user}${s.status === 'open' ? ' (open)' : ''}`}
              subtitle={[
                `${dateTime(s.openedAt)} to ${s.closedAt ? dateTime(s.closedAt) : 'now'}`,
                `Should hold ${money(s.expectedMinor)} · counted ${s.countedMinor === null ? '—' : money(s.countedMinor)}`,
                s.note,
              ]
                .filter(Boolean)
                .join('\n')}
              right={s.differenceMinor === null ? '—' : s.differenceMinor === 0 ? 'Exact' : `${s.differenceMinor > 0 ? '+' : ''}${money(s.differenceMinor)}`}
              rightSub={s.differenceMinor ? (s.differenceMinor < 0 ? 'Short' : 'Over') : null}
              tone={s.differenceMinor ? (s.differenceMinor < 0 ? 'red' : 'amber') : undefined}
            />
          ))
        )}
      </Panel>

      <Panel title={`Stock at cost: ${money(p.stock.valueMinor)}`}>
        {p.stock.expiredValueMinor > 0 && <Muted>Expired on the shelf: {money(p.stock.expiredValueMinor)}</Muted>}
        <Text className="px-4 pt-3 text-xs font-bold uppercase tracking-wider text-slate-500">Low on stock now</Text>
        {p.stock.low.length === 0 ? (
          <Muted>Nothing is low.</Muted>
        ) : (
          p.stock.low.map((r) => (
            <PanelRow
              key={r.id}
              title={r.label}
              subtitle={`Reorder at ${r.reorderLevel}`}
              right={`${r.sellable} ${r.unit}`}
              tone="amber"
              onPress={() => openPage(`stock/${r.id}`)}
            />
          ))
        )}
      </Panel>

      <Panel title={`Expired or expiring within ${p.expiringDays} days`}>
        {p.stock.expiring.length === 0 ? (
          <Muted>Nothing.</Muted>
        ) : (
          p.stock.expiring.map((b) => (
            <PanelRow
              key={`${b.product_id}-${b.batch_number}-${b.expires_on}`}
              title={b.name}
              subtitle={`Batch ${b.batch_number} · expires ${fullDate(b.expires_on)}`}
              right={`${b.quantity} ${b.unit}`}
              onPress={() => openPage(`stock/${b.product_id}`)}
            />
          ))
        )}
      </Panel>

      {range && (
        <FilterSheet title="Which days" initial={{ from: p.filters.from, to: p.filters.to }} onApply={setQuery} onClose={() => setRange(false)}>
          {(data, set) => (
            <>
              <DateField label="From" value={data.from} onChange={(v) => set('from', v)} />
              <DateField label="To" value={data.to} onChange={(v) => set('to', v)} />
            </>
          )}
        </FilterSheet>
      )}
    </PageScroll>
  );
}
