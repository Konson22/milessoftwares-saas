import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { currencyCode, fullDate, money, prettyDate } from '@/lib/format';

import { Choice, ChoiceRow, DateField, Figure, FilterSheet, FormSheet, Muted, Panel, PanelRow, RowActions, SmallButton, Toggle } from '../forms';
import { PageScroll } from '../kit';
import type { ScreenProps } from '../types';

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
  totals: { totalMinor: number; count: number; byType: Record<ExpenseType, number> };
  recurring: Bill[];
  categories: CategoryOption[];
  paidFrom: { value: string; label: string }[];
  filters: { from: string; to: string; category: string };
  today: string;
}

type Dialog = { kind: 'record'; categoryId: string; amount: string } | { kind: 'void'; expense: ExpenseRow } | { kind: 'filter' };

const major = (minor: number | null) => (minor === null ? '' : money(minor, false).replace(/,/g, ''));

/** What the shop spends: by category, from the safe, the bank or a shift's till. */
export function ExpensesScreen({ page, path, reload, loading, setQuery }: ScreenProps<Props>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const close = () => setDialog(null);
  const category = (id: string) => p.categories.find((c) => c.id === id);
  const filtered = p.filters.category ? category(p.filters.category)?.name : null;

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">
        {prettyDate(p.filters.from)} to {prettyDate(p.filters.to)}
        {filtered ? ` · ${filtered}` : ''} · {p.totals.count} payment(s)
      </Text>
      <View className="flex-row gap-2">
        <Button title="Record expense" icon="add" square className="flex-1" onPress={() => setDialog({ kind: 'record', categoryId: '', amount: '' })} />
        <Button title="Filter" variant="outline" icon="filter-outline" square onPress={() => setDialog({ kind: 'filter' })} />
      </View>
      <Button title="Categories" variant="outline" icon="pricetags-outline" square onPress={() => openPage('expenses/categories')} />

      <View className="flex-row flex-wrap gap-3">
        <Figure label="Spent" value={money(p.totals.totalMinor)} note={`${p.totals.count} payment(s)`} />
        <Figure label="Operating" value={money(p.totals.byType.operating)} note="Day-to-day running costs" />
        <Figure label="Recurring" value={money(p.totals.byType.recurring)} note="Rent, salaries, bills" />
        <Figure label="One-off" value={money(p.totals.byType.one_off)} note="Repairs, equipment" />
      </View>

      {p.recurring.length > 0 && (
        <Panel title="Recurring bills">
          {p.recurring.map((b) => (
            <PanelRow
              key={b.categoryId}
              title={b.name}
              subtitle={`${b.frequency} · last paid ${b.lastPaid ? fullDate(b.lastPaid) : 'never'}${b.dueOn ? ` · due ${fullDate(b.dueOn)}` : ''}`}
              right={b.amountMinor === null ? null : money(b.amountMinor)}
              tone={b.status === 'overdue' ? 'red' : b.status === 'due' ? 'amber' : undefined}>
              <View className="flex-row flex-wrap items-center gap-2">
                {b.status === 'overdue' && <Badge label="Overdue" tone="red" />}
                {b.status === 'due' && <Badge label="Due this week" tone="amber" />}
                {!b.dueOn && <Badge label="Not paid yet" />}
                <SmallButton title="Record payment" onPress={() => setDialog({ kind: 'record', categoryId: b.categoryId, amount: major(b.amountMinor) })} />
              </View>
            </PanelRow>
          ))}
        </Panel>
      )}

      <Panel title="Payments">
        {p.expenses.length === 0 ? (
          <Muted>No expenses in these days.</Muted>
        ) : (
          p.expenses.map((e) => (
            <PanelRow
              key={e.id}
              dim={e.voided}
              title={`${e.category} · ${e.number}`}
              subtitle={[
                `${fullDate(e.spentOn)} · ${e.paidFromLabel}${e.tillNumber ? ` (shift ${e.tillNumber})` : ''} · by ${e.by}`,
                [e.payee, e.note].filter(Boolean).join(': ') || null,
                e.reference ? `Ref. ${e.reference}` : null,
              ]
                .filter(Boolean)
                .join('\n')}
              right={money(e.amountMinor)}
              rightSub={e.typeLabel}>
              {e.voided && (
                <View className="flex-row flex-wrap items-center gap-2">
                  <Badge label="Taken back" tone="red" />
                  {e.voidReason && <Text className="text-xs text-red-700">{e.voidReason}</Text>}
                </View>
              )}
              {e.canVoid && (
                <RowActions>
                  <SmallButton title="Take back" tone="red" onPress={() => setDialog({ kind: 'void', expense: e })} />
                </RowActions>
              )}
            </PanelRow>
          ))
        )}
      </Panel>

      {dialog?.kind === 'filter' && (
        <FilterSheet
          title="Which expenses"
          onApply={setQuery}
          onClose={close}
          initial={{ from: p.filters.from, to: p.filters.to, category: p.filters.category }}>
          {(data, set) => (
            <>
              <DateField label="From" value={data.from} onChange={(t) => set('from', t)} />
              <DateField label="To" value={data.to} onChange={(t) => set('to', t)} />
              <ChoiceRow
                label="Category"
                options={[{ value: '', label: 'All' }, ...p.categories.map((c) => ({ value: c.id, label: c.name }))]}
                value={data.category}
                onChange={(v) => set('category', v)}
              />
            </>
          )}
        </FilterSheet>
      )}

      {dialog?.kind === 'record' && (
        <FormSheet
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
          }}>
          {(data, set, errors) => {
            const chosen = category(data.category_id);
            return (
              <>
                <Banner tone="info">Cash taken from the till during a shift is recorded under My shift, Cash in or out, so the drawer’s count allows for it.</Banner>
                <ChoiceRow
                  label={chosen ? `Category · ${chosen.typeLabel}` : 'Category'}
                  options={p.categories.map((c) => ({ value: c.id, label: c.name }))}
                  value={data.category_id}
                  onChange={(v) => {
                    set('category_id', v);
                    const next = category(v);
                    if (next?.usualAmount && !data.amount) set('amount', next.usualAmount);
                  }}
                  error={errors.category_id}
                />
                <Field
                  label={`Amount (${currencyCode()})`}
                  value={data.amount}
                  onChangeText={(t) => set('amount', t)}
                  error={errors.amount}
                  keyboardType="decimal-pad"
                />
                <DateField label="Paid on" value={data.spent_on} onChange={(t) => set('spent_on', t)} error={errors.spent_on} hint=" " />
                <Choice label="Paid from" options={p.paidFrom} value={data.paid_from} onChange={(v) => set('paid_from', v)} error={errors.paid_from} />
                <Field label="Paid to" value={data.payee} onChangeText={(t) => set('payee', t)} error={errors.payee} placeholder="The landlord" />
                <Field label="Receipt or reference" value={data.reference} onChangeText={(t) => set('reference', t)} error={errors.reference} />
                <Field label="Note" value={data.note} onChangeText={(t) => set('note', t)} error={errors.note} placeholder="October rent" />
              </>
            );
          }}
        </FormSheet>
      )}

      {dialog?.kind === 'void' && (
        <FormSheet
          title={`Take back expense ${dialog.expense.number}`}
          submit="Take back"
          danger
          action={action}
          onClose={close}
          to={`expenses/${dialog.expense.id}/void`}
          initial={{ reason: '' }}>
          {(data, set, errors) => (
            <>
              <Text className="text-sm text-slate-500">
                {money(dialog.expense.amountMinor)} for {dialog.expense.category}, {fullDate(dialog.expense.spentOn)}.
                {dialog.expense.paidFrom === 'till' ? ' The cash counts as back in its shift’s drawer.' : ' It stays on the list, crossed out.'}
              </Text>
              <Field label="Why" value={data.reason} onChangeText={(t) => set('reason', t)} error={errors.reason} placeholder="Recorded twice" autoFocus />
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
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
export function ExpenseCategoriesScreen({ page, path, reload, loading }: ScreenProps<CategoriesProps>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [editing, setEditing] = useState<CategoryRow | 'new' | null>(null);
  const row = editing === 'new' ? null : editing;

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">
        Operating costs, recurring bills and one-off spending. Those paid from the till can be paid out of a shift’s drawer.
      </Text>
      <Button title="New category" icon="add" square onPress={() => setEditing('new')} />
      <Panel>
        {p.categories.map((c) => (
          <PanelRow
            key={c.id}
            dim={!c.active}
            title={c.name}
            subtitle={[
              [c.typeLabel, c.frequencyLabel, c.usualAmountMinor === null ? null : `usually ${money(c.usualAmountMinor)}`].filter(Boolean).join(' · '),
              [c.fromTill ? 'Paid from the till' : null, c.active ? null : 'Not used'].filter(Boolean).join(' · ') || null,
            ]
              .filter(Boolean)
              .join('\n')}
            right={money(c.spentMinor)}
            rightSub={`${c.count} payment(s)`}
            onPress={() => setEditing(c)}
          />
        ))}
      </Panel>

      {editing && (
        <FormSheet
          title={row ? `Edit ${row.name}` : 'New category'}
          submit="Save"
          action={action}
          onClose={() => setEditing(null)}
          method={row ? 'PUT' : 'POST'}
          to={row ? `expense-categories/${row.id}` : 'expense-categories'}
          initial={{
            name: row?.name ?? '',
            type: row?.type ?? 'operating',
            frequency: row?.frequency ?? 'monthly',
            usual_amount: row?.usualAmount ?? '',
            from_till: row?.fromTill ? '1' : '',
            active: row && !row.active ? '' : '1',
          }}>
          {(data, set, errors) => (
            <>
              <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} placeholder="Internet" />
              <Choice
                label="Type"
                options={p.types}
                value={data.type as ExpenseType}
                onChange={(v) => set('type', v)}
                error={errors.type}
                hint={p.types.find((t) => t.value === data.type)?.hint}
              />
              {data.type === 'recurring' && (
                <Choice
                  label="Comes back"
                  options={p.frequencies}
                  value={data.frequency}
                  onChange={(v) => set('frequency', v)}
                  error={errors.frequency}
                  hint="Shown as due again this long after the last payment"
                />
              )}
              <Field
                label={`Usual amount (${currencyCode()})`}
                value={data.usual_amount}
                onChangeText={(t) => set('usual_amount', t)}
                error={errors.usual_amount}
                hint="Offered when recording one. Empty: none"
                keyboardType="decimal-pad"
              />
              <Toggle
                label="Paid from the till"
                hint="Whoever has a shift open can pay it from the cash drawer; it counts against that shift."
                value={data.from_till === '1'}
                onChange={(on) => set('from_till', on ? '1' : '')}
              />
              {row && <Toggle label="In use" value={data.active === '1'} onChange={(on) => set('active', on ? '1' : '')} />}
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
  );
}
