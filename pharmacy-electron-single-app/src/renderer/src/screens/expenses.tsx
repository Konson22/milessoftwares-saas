import { Plus, Tags, Wallet } from 'lucide-react';
import { useState } from 'react';

import { Badge, Button, cn, Empty, Field, Input, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { currencyCode, money, prettyDate } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import { FormDialog } from './stock';
import type { ScreenProps } from './types';

type ExpenseType = 'operating' | 'recurring' | 'one_off';

interface CategoryOption {
  id: string;
  name: string;
  type: ExpenseType;
  typeLabel: string;
  fromTill: boolean;
  usualAmount: string;
}

interface ExpenseRow {
  id: string;
  number: string;
  spentOn: string;
  category: string;
  typeLabel: string;
  amountMinor: number;
  paidFrom: 'till' | 'cash' | 'bank' | 'mobile_money';
  paidFromLabel: string;
  tillNumber: string | null;
  payee: string | null;
  reference: string | null;
  note: string | null;
  by: string;
  voided: boolean;
  voidReason: string | null;
  canVoid: boolean;
}

interface Bill {
  categoryId: string;
  name: string;
  frequency: string;
  lastPaid: string | null;
  /** Null until it is first paid. */
  dueOn: string | null;
  status: 'overdue' | 'due' | 'later' | 'never';
  amountMinor: number | null;
}

interface Props {
  expenses: ExpenseRow[];
  totals: { totalMinor: number; count: number; byType: Record<ExpenseType, number>; byCategory: { name: string; total: number }[] };
  recurring: Bill[];
  categories: CategoryOption[];
  paidFrom: { value: string; label: string }[];
  filters: { from: string; to: string; category: string };
  today: string;
}

type Dialog = { kind: 'record'; categoryId: string; amount: string } | { kind: 'void'; expense: ExpenseRow };

const major = (minor: number | null) => (minor === null ? '' : money(minor, false).replace(/,/g, ''));

/** What the pharmacy spends: by category, from the safe, the bank or a shift's till. */
export function ExpensesScreen({ page, path, reload }: ScreenProps<Props>) {
  const p = page.props;
  const open = useUi((s) => s.open);
  const action = useAction(path, reload);
  const [filters, setFilters] = useState(p.filters);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const close = () => setDialog(null);
  const category = (id: string) => p.categories.find((c) => c.id === id);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Expenses"
        description={`${prettyDate(p.filters.from)} to ${prettyDate(p.filters.to)} · ${p.totals.count} payment(s)`}
        actions={
          <>
            <Button variant="secondary" onClick={() => open('expenses/categories')}>
              <Tags size={16} /> Categories
            </Button>
            <Button onClick={() => setDialog({ kind: 'record', categoryId: '', amount: '' })}>
              <Plus size={16} /> Record expense
            </Button>
          </>
        }
      />

      <form
        className="flex items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          open(withQuery('expenses', filters));
        }}
      >
        <Field label="From">
          <Input type="date" value={filters.from} max={filters.to} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
        </Field>
        <Field label="To">
          <Input type="date" value={filters.to} min={filters.from} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
        </Field>
        <Field label="Category">
          <Select value={filters.category} onChange={(e) => setFilters({ ...filters, category: e.target.value })}>
            <option value="">All</option>
            {p.categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
        </Field>
        <Button type="submit">Show</Button>
      </form>

      <div className="grid grid-cols-4 gap-3">
        <Stat label="Spent" value={money(p.totals.totalMinor)} note={`${p.totals.count} payment(s)`} />
        <Stat label="Operating" value={money(p.totals.byType.operating)} note="Day-to-day running costs" />
        <Stat label="Recurring" value={money(p.totals.byType.recurring)} note="Rent, salaries, bills" />
        <Stat label="One-off" value={money(p.totals.byType.one_off)} note="Repairs, equipment" />
      </div>

      {p.recurring.length > 0 ? (
        <Panel title="Recurring bills">
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Bill</th>
                <th className="px-4 py-2 font-medium">How often</th>
                <th className="px-4 py-2 font-medium">Last paid</th>
                <th className="px-4 py-2 font-medium">Due</th>
                <th className="px-4 py-2 text-right font-medium">Usually</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.recurring.map((b) => (
                <tr key={b.categoryId}>
                  <td className="px-4 py-2.5 font-medium">{b.name}</td>
                  <td className="px-4 py-2.5">{b.frequency}</td>
                  <td className="px-4 py-2.5">{b.lastPaid ?? 'Never'}</td>
                  <td className="px-4 py-2.5">
                    <span className="flex items-center gap-2">
                      {b.dueOn ?? <Badge>Not paid yet</Badge>}
                      {b.status === 'overdue' ? <Badge tone="red">Overdue</Badge> : b.status === 'due' ? <Badge tone="amber">Due this week</Badge> : null}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">{b.amountMinor === null ? '—' : money(b.amountMinor)}</td>
                  <td className="px-4 py-2.5 text-right">
                    <Button size="sm" variant={b.status === 'overdue' || b.status === 'due' ? 'secondary' : 'ghost'} onClick={() => setDialog({ kind: 'record', categoryId: b.categoryId, amount: major(b.amountMinor) })}>
                      Record payment
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      ) : null}

      <Panel title="Payments">
        {p.expenses.length === 0 ? (
          <Empty icon={<Wallet size={28} />} title="No expenses in these days" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Number</th>
                <th className="px-4 py-2 font-medium">Paid on</th>
                <th className="px-4 py-2 font-medium">Category</th>
                <th className="px-4 py-2 font-medium">Paid from</th>
                <th className="px-4 py-2 font-medium">To, what for</th>
                <th className="px-4 py-2 text-right font-medium">Amount</th>
                <th className="px-4 py-2 font-medium">By</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.expenses.map((e) => (
                <tr key={e.id} className={cn(e.voided && 'text-muted-foreground')}>
                  <td className="px-4 py-2.5 font-medium">
                    <span className={cn(e.voided && 'line-through')}>{e.number}</span>
                    {e.voided ? (
                      <div>
                        <Badge tone="red">Taken back</Badge>
                      </div>
                    ) : null}
                  </td>
                  <td className="px-4 py-2.5">{e.spentOn}</td>
                  <td className="px-4 py-2.5">
                    {e.category}
                    <div className="text-xs text-muted-foreground">{e.typeLabel}</div>
                  </td>
                  <td className="px-4 py-2.5">
                    {e.paidFromLabel}
                    {e.tillNumber ? <div className="text-xs text-muted-foreground">Shift {e.tillNumber}</div> : null}
                  </td>
                  <td className="px-4 py-2.5">
                    {[e.payee, e.note].filter(Boolean).join(': ') || '—'}
                    {e.reference ? <div className="text-xs text-muted-foreground">Ref. {e.reference}</div> : null}
                    {e.voidReason ? <div className="text-xs text-red-700">{e.voidReason}</div> : null}
                  </td>
                  <td className={cn('px-4 py-2.5 text-right font-medium tabular', e.voided && 'line-through')}>{money(e.amountMinor)}</td>
                  <td className="px-4 py-2.5">{e.by}</td>
                  <td className="px-4 py-2.5 text-right">
                    {e.canVoid ? (
                      <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'void', expense: e })}>
                        Take back
                      </Button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>

      {dialog?.kind === 'record' ? (
        <FormDialog
          title="Record an expense"
          submit="Record"
          action={action}
          onClose={close}
          to="expenses"
          initial={{
            category_id: dialog.categoryId,
            amount: dialog.amount,
            spent_on: p.today,
            paid_from: p.paidFrom[0]?.value ?? 'cash',
            payee: '',
            reference: '',
            note: '',
          }}
        >
          {(data, set, errors) => {
            const chosen = category(data.category_id);
            return (
              <>
                <Notice tone="blue">Cash taken from the till during a shift is recorded under My shift, Cash in or out, so the drawer’s count allows for it.</Notice>
                <Field label="Category" error={errors.category_id} hint={chosen ? chosen.typeLabel : undefined}>
                  <Select
                    value={data.category_id}
                    onChange={(e) => {
                      set('category_id', e.target.value);
                      const next = category(e.target.value);
                      if (next?.usualAmount && !data.amount) set('amount', next.usualAmount);
                    }}
                    autoFocus
                  >
                    <option value="">Choose…</option>
                    {p.categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label={`Amount (${currencyCode()})`} error={errors.amount}>
                    <Input inputMode="decimal" value={data.amount} onChange={(e) => set('amount', e.target.value)} required />
                  </Field>
                  <Field label="Paid on" error={errors.spent_on}>
                    <Input type="date" max={p.today} value={data.spent_on} onChange={(e) => set('spent_on', e.target.value)} required />
                  </Field>
                </div>
                <Field label="Paid from" error={errors.paid_from}>
                  <Select value={data.paid_from} onChange={(e) => set('paid_from', e.target.value)}>
                    {p.paidFrom.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Paid to" error={errors.payee}>
                    <Input value={data.payee} onChange={(e) => set('payee', e.target.value)} placeholder="The landlord" />
                  </Field>
                  <Field label="Receipt or reference" error={errors.reference}>
                    <Input value={data.reference} onChange={(e) => set('reference', e.target.value)} />
                  </Field>
                </div>
                <Field label="Note" error={errors.note}>
                  <Input value={data.note} onChange={(e) => set('note', e.target.value)} placeholder="October rent" />
                </Field>
              </>
            );
          }}
        </FormDialog>
      ) : null}

      {dialog?.kind === 'void' ? (
        <FormDialog title={`Take back expense ${dialog.expense.number}`} submit="Take back" action={action} onClose={close} to={`expenses/${dialog.expense.id}/void`} initial={{ reason: '' }}>
          {(data, set, errors) => (
            <>
              <p className="text-sm text-muted-foreground">
                {money(dialog.expense.amountMinor)} for {dialog.expense.category}, {dialog.expense.spentOn}.
                {dialog.expense.paidFrom === 'till' ? ' The cash counts as back in its shift’s drawer.' : ' It stays on the list, crossed out.'}
              </p>
              <Field label="Why" error={errors.reason}>
                <Input value={data.reason} onChange={(e) => set('reason', e.target.value)} placeholder="Recorded twice" required autoFocus />
              </Field>
            </>
          )}
        </FormDialog>
      ) : null}
    </div>
  );
}

function Stat({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <div className="border border-border bg-card px-4 py-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="text-xl font-semibold tabular">{value}</div>
      <div className="text-xs text-muted-foreground">{note}</div>
    </div>
  );
}

interface CategoryRow {
  id: string;
  name: string;
  type: ExpenseType;
  typeLabel: string;
  frequency: string | null;
  frequencyLabel: string | null;
  usualAmount: string;
  usualAmountMinor: number | null;
  fromTill: boolean;
  active: boolean;
  spentMinor: number;
  count: number;
}

interface CategoriesProps {
  categories: CategoryRow[];
  types: { value: ExpenseType; label: string; hint: string }[];
  frequencies: { value: string; label: string }[];
}

/** What expenses are recorded under, set by a manager. */
export function ExpenseCategoriesScreen({ page, path, reload }: ScreenProps<CategoriesProps>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [editing, setEditing] = useState<CategoryRow | 'new' | null>(null);
  const close = () => setEditing(null);
  const row = editing === 'new' ? null : editing;

  return (
    <div className="space-y-4">
      <PageHeader
        title="Expense categories"
        description="Operating costs, recurring bills and one-off spending. Those paid from the till can be paid out of a shift’s drawer."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus size={16} /> New category
          </Button>
        }
      />
      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-muted text-left text-xs text-muted-foreground">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Type</th>
              <th className="px-4 py-2 font-medium">How often</th>
              <th className="px-4 py-2 text-right font-medium">Usually</th>
              <th className="px-4 py-2 font-medium">From the till</th>
              <th className="px-4 py-2 text-right font-medium">Spent, all time</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {p.categories.map((c) => (
              <tr key={c.id} className={cn(!c.active && 'text-muted-foreground')}>
                <td className="px-4 py-2.5 font-medium">
                  {c.name} {!c.active ? <Badge>Not used</Badge> : null}
                </td>
                <td className="px-4 py-2.5">
                  <Badge tone={c.type === 'recurring' ? 'blue' : c.type === 'one_off' ? 'amber' : 'neutral'}>{c.typeLabel}</Badge>
                </td>
                <td className="px-4 py-2.5">{c.frequencyLabel ?? '—'}</td>
                <td className="px-4 py-2.5 text-right tabular">{c.usualAmountMinor === null ? '—' : money(c.usualAmountMinor)}</td>
                <td className="px-4 py-2.5">{c.fromTill ? <Badge tone="green">Yes</Badge> : '—'}</td>
                <td className="px-4 py-2.5 text-right tabular">
                  {money(c.spentMinor)}
                  <div className="text-xs text-muted-foreground">{c.count} payment(s)</div>
                </td>
                <td className="px-4 py-2.5 text-right">
                  <Button size="sm" variant="ghost" onClick={() => setEditing(c)}>
                    Edit
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>

      {editing ? (
        <FormDialog
          title={row ? `Edit ${row.name}` : 'New category'}
          submit="Save"
          action={action}
          onClose={close}
          method={row ? 'PUT' : 'POST'}
          to={row ? `expense-categories/${row.id}` : 'expense-categories'}
          initial={{
            name: row?.name ?? '',
            type: row?.type ?? 'operating',
            frequency: row?.frequency ?? 'monthly',
            usual_amount: row?.usualAmount ?? '',
            from_till: row?.fromTill ? '1' : '',
            active: row && !row.active ? '' : '1',
          }}
        >
          {(data, set, errors) => (
            <>
              <Field label="Name" error={errors.name}>
                <Input value={data.name} onChange={(e) => set('name', e.target.value)} placeholder="Internet" required autoFocus />
              </Field>
              <Field label="Type" error={errors.type} hint={p.types.find((t) => t.value === data.type)?.hint}>
                <Select value={data.type} onChange={(e) => set('type', e.target.value)}>
                  {p.types.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </Select>
              </Field>
              {data.type === 'recurring' ? (
                <Field label="Comes back" error={errors.frequency} hint="Shown as due again this long after the last payment">
                  <Select value={data.frequency} onChange={(e) => set('frequency', e.target.value)}>
                    {p.frequencies.map((f) => (
                      <option key={f.value} value={f.value}>
                        {f.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              ) : null}
              <Field label={`Usual amount (${currencyCode()})`} error={errors.usual_amount} hint="Offered when recording one. Empty: none">
                <Input inputMode="decimal" value={data.usual_amount} onChange={(e) => set('usual_amount', e.target.value)} />
              </Field>
              <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" className="mt-1" checked={data.from_till === '1'} onChange={(e) => set('from_till', e.target.checked ? '1' : '')} />
                <span>
                  Paid from the till
                  <span className="block text-xs text-muted-foreground">Whoever has a shift open can pay it from the cash drawer; it counts against that shift.</span>
                </span>
              </label>
              {row ? (
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={data.active === '1'} onChange={(e) => set('active', e.target.checked ? '1' : '')} /> In use
                </label>
              ) : null}
            </>
          )}
        </FormDialog>
      ) : null}
    </div>
  );
}
