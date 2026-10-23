import { useState, type ReactNode } from 'react';

import { Badge, Button, cn, Empty, Field, Input, PageHeader, Panel } from '@/components/ui';
import { dateTime, money, prettyDate } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

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
  topMedicines: { name: string; unit: string; units: number; total: number }[];
  stock: {
    valueMinor: number;
    expiredValueMinor: number;
    expiring: { medicine_id: string; name: string; batch_number: string; expires_on: string; quantity: number; unit: string }[];
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
}

/** Sales, profit, shifts and stock over a range of days. */
export function ReportsScreen({ page }: ScreenProps<Props>) {
  const p = page.props;
  const open = useUi((s) => s.open);
  const [from, setFrom] = useState(p.filters.from);
  const [to, setTo] = useState(p.filters.to);
  const t = p.totals;

  return (
    <div className="space-y-4">
      <PageHeader
        title="Reports"
        description={`${prettyDate(p.filters.from)} to ${prettyDate(p.filters.to)}`}
        actions={
          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              open(withQuery('reports', { from, to }));
            }}
          >
            <Field label="From">
              <Input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
            </Field>
            <Field label="To">
              <Input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
            </Field>
            <Button type="submit">Show</Button>
          </form>
        }
      />

      <div className="grid grid-cols-3 gap-3 xl:grid-cols-6">
        <Stat label="Net sales" value={money(t.netMinor)} note={`${t.salesCount} sale(s); returns ${money(t.returnsMinor)}`} />
        <Stat label="Cost of what was sold" value={money(t.costMinor)} note="At each batch’s cost" />
        <Stat label="Gross profit" value={money(t.profitMinor)} note={t.netMinor ? `${Math.round((t.profitMinor / t.netMinor) * 100)}% of net sales` : '—'} />
        <Stat label="Expenses" value={money(t.expensesMinor)} note="Paid in these days" />
        <Stat label="Net profit" value={money(t.netProfitMinor)} note="Gross profit less expenses" tone={t.netProfitMinor < 0 ? 'red' : undefined} />
        <Stat label="Discounts given" value={money(t.discountMinor)} note={`${t.voidedCount} sale(s) voided`} />
      </div>

      <Panel title="Expenses by category" actions={<Button size="sm" variant="ghost" onClick={() => open(withQuery('expenses', { from: p.filters.from, to: p.filters.to }))}>All expenses</Button>}>
        {p.expensesByCategory.length === 0 ? (
          <Empty title="No expenses in these days" />
        ) : (
          <Table head={['Category', 'Type', 'Payments', 'Spent']} numbers={2}>
            {p.expensesByCategory.map((r) => (
              <tr key={r.key}>
                <td className="px-4 py-2">{r.key}</td>
                <td className="px-4 py-2 text-muted-foreground">{r.type}</td>
                <td className="px-4 py-2 text-right tabular">{r.count}</td>
                <td className="px-4 py-2 text-right tabular">{money(r.total)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>

      <div className="grid grid-cols-3 gap-4">
        <GroupPanel title="By day" rows={p.byDay.map((r) => ({ ...r, key: prettyDate(r.key) }))} />
        <GroupPanel title="By way of paying" rows={p.byMethod} />
        <GroupPanel title="By who sold" rows={p.byStaff} />
      </div>

      <Panel title="Best sellers">
        {p.topMedicines.length === 0 ? (
          <Empty title="No sales in these days" />
        ) : (
          <Table head={['Medicine', 'Sold', 'Takings']}>
            {p.topMedicines.map((m) => (
              <tr key={m.name}>
                <td className="px-4 py-2">{m.name}</td>
                <td className="px-4 py-2 text-right tabular">
                  {m.units} {m.unit}
                </td>
                <td className="px-4 py-2 text-right tabular">{money(m.total)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>

      <Panel title="Shifts">
        {p.shifts.length === 0 ? (
          <Empty title="No shifts in these days" />
        ) : (
          <Table head={['Shift', 'Who', 'Opened', 'Closed', 'Should hold', 'Counted', 'Over or short']} numbers={3}>
            {p.shifts.map((s) => (
              <tr key={s.id}>
                <td className="px-4 py-2 font-medium">
                  {s.number} {s.status === 'open' ? <Badge tone="blue">Open</Badge> : null}
                </td>
                <td className="px-4 py-2">{s.user}</td>
                <td className="px-4 py-2">{dateTime(s.openedAt)}</td>
                <td className="px-4 py-2">{s.closedAt ? dateTime(s.closedAt) : '—'}</td>
                <td className="px-4 py-2 text-right tabular">{money(s.expectedMinor)}</td>
                <td className="px-4 py-2 text-right tabular">{s.countedMinor === null ? '—' : money(s.countedMinor)}</td>
                <td
                  className={cn('px-4 py-2 text-right tabular', s.differenceMinor ? (s.differenceMinor < 0 ? 'text-red-700' : 'text-amber-700') : '')}
                  title={s.note ?? undefined}
                >
                  {s.differenceMinor === null ? '—' : s.differenceMinor === 0 ? 'Exact' : `${s.differenceMinor > 0 ? '+' : ''}${money(s.differenceMinor)}`}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>

      <div className="grid grid-cols-2 gap-4">
        <Panel title={`Stock at cost: ${money(p.stock.valueMinor)}`} actions={p.stock.expiredValueMinor ? <Badge tone="red">Expired: {money(p.stock.expiredValueMinor)}</Badge> : null}>
          <div className="px-4 pt-3 text-xs text-muted-foreground">Low on stock now</div>
          {p.stock.low.length === 0 ? (
            <p className="px-4 py-3 text-sm text-muted-foreground">Nothing is low.</p>
          ) : (
            <Table head={['Medicine', 'Sellable', 'Reorder at']}>
              {p.stock.low.map((r) => (
                <tr key={r.id} onClick={() => open(`stock/${r.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2">{r.label}</td>
                  <td className="px-4 py-2 text-right tabular">
                    {r.sellable} {r.unit}
                  </td>
                  <td className="px-4 py-2 text-right tabular">{r.reorderLevel}</td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
        <Panel title={`Expired or expiring within ${p.expiringDays} days`}>
          {p.stock.expiring.length === 0 ? (
            <p className="px-4 py-3 text-sm text-muted-foreground">Nothing.</p>
          ) : (
            <Table head={['Medicine', 'Batch', 'Expires', 'On hand']} numbers={1}>
              {p.stock.expiring.map((b) => (
                <tr key={`${b.medicine_id}-${b.batch_number}-${b.expires_on}`} onClick={() => open(`stock/${b.medicine_id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2">{b.name}</td>
                  <td className="px-4 py-2">{b.batch_number}</td>
                  <td className="px-4 py-2">{b.expires_on}</td>
                  <td className="px-4 py-2 text-right tabular">
                    {b.quantity} {b.unit}
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      </div>
    </div>
  );
}

function Stat({ label, value, note, tone }: { label: string; value: string; note: string; tone?: 'red' }) {
  return (
    <div className="border border-border bg-card px-4 py-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className={cn('text-xl font-semibold tabular', tone === 'red' && 'text-red-700')}>{value}</div>
      <div className="text-xs text-muted-foreground">{note}</div>
    </div>
  );
}

function GroupPanel({ title, rows }: { title: string; rows: Grouped[] }) {
  return (
    <Panel title={title}>
      {rows.length === 0 ? (
        <p className="px-4 py-3 text-sm text-muted-foreground">No sales.</p>
      ) : (
        <Table head={['', 'Sales', 'Net']}>
          {rows.map((r) => (
            <tr key={r.key}>
              <td className="px-4 py-2">{r.key}</td>
              <td className="px-4 py-2 text-right tabular">{r.count}</td>
              <td className="px-4 py-2 text-right tabular">{money(r.total)}</td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}

/** Text columns first; the last `numbers` columns are amounts, aligned right. */
function Table({ head, numbers = head.length - 1, children }: { head: string[]; numbers?: number; children: ReactNode }) {
  return (
    <table className="w-full text-sm">
      <thead className="text-left text-xs text-muted-foreground">
        <tr>
          {head.map((h, i) => (
            <th key={i} className={cn('px-4 py-2 font-medium', i >= head.length - numbers && 'text-right')}>
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody className="divide-y divide-border">{children}</tbody>
    </table>
  );
}
