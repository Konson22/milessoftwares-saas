import { all, nextNumber, one, run, transaction, type Param } from './database';
import type { ActionResult, Query } from './pages';
import { hasPermission, type UserRow } from './session';
import { settings } from './settings';
import { addDays, bool, flag, guard, invalid, isDay, notFound, now, text, toMajor, toMinor, today, uuid, Validation } from './util';

/**
 * What the shop spends, by category: operating costs, recurring bills
 * (due again a week, a month, a quarter or a year after the last payment)
 * and one-off spending. Categories paid from the till are paid out of a
 * shift's cash drawer: the shift records them, and they count against it.
 */

export type ExpenseType = 'operating' | 'recurring' | 'one_off';
export type Frequency = 'weekly' | 'monthly' | 'quarterly' | 'yearly';
export type PaidFrom = 'till' | 'cash' | 'bank' | 'mobile_money';

export interface CategoryRow {
  id: string;
  name: string;
  type: ExpenseType;
  frequency: Frequency | null;
  usual_amount_minor: number | null;
  from_till: number;
  active: number;
  created_at: string;
}

export const TYPES: { value: ExpenseType; label: string; hint: string }[] = [
  { value: 'operating', label: 'Operating', hint: 'Day-to-day running costs: transport, cleaning, fuel' },
  { value: 'recurring', label: 'Recurring', hint: 'Bills that come back: rent, salaries, electricity' },
  { value: 'one_off', label: 'One-off', hint: 'Spent once: repairs, equipment' },
];

export const FREQUENCIES: { value: Frequency; label: string }[] = [
  { value: 'weekly', label: 'Every week' },
  { value: 'monthly', label: 'Every month' },
  { value: 'quarterly', label: 'Every 3 months' },
  { value: 'yearly', label: 'Every year' },
];

/** Paid from the till goes through a shift; the others are recorded by a manager. */
export const PAID_FROM: { value: PaidFrom; label: string }[] = [
  { value: 'till', label: 'The till (this shift’s drawer)' },
  { value: 'cash', label: 'Cash from the safe' },
  { value: 'bank', label: 'The bank' },
  { value: 'mobile_money', label: 'Mobile money' },
];

const typeLabel = (type: string) => TYPES.find((t) => t.value === type)?.label ?? type;
const paidFromLabel = (from: string) => (from === 'till' ? 'The till' : (PAID_FROM.find((p) => p.value === from)?.label ?? from));
const minor = (value: unknown) => toMinor(value, settings().decimals);
const major = (value: number) => toMajor(value, settings().decimals);
const done = (message: string | null, redirect: string | null = null): ActionResult => ({ message, print: null, redirect });
const manages = (user: UserRow) => hasPermission(user, 'retail.expenses.manage');

function addMonths(day: string, months: number): string {
  const [y, m, d] = day.split('-').map(Number);
  const target = new Date(Date.UTC(y, m - 1 + months, 1));
  // The same day of the month, or its last day (31 Jan + 1 month = 28 Feb).
  const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  target.setUTCDate(Math.min(d, last));
  return target.toISOString().slice(0, 10);
}

/** When a recurring bill is due again after `last` was paid. */
export function nextDue(frequency: Frequency, last: string): string {
  if (frequency === 'weekly') return addDays(last, 7);
  return addMonths(last, frequency === 'monthly' ? 1 : frequency === 'quarterly' ? 3 : 12);
}

/** Recurring bills: when each was last paid and when it is due again. One never paid has no due day yet. */
export function recurring() {
  const t = today();
  return all<CategoryRow & { last_paid: string | null; last_amount: number | null }>(
    `SELECT c.*,
       (SELECT MAX(e.spent_on) FROM expenses e WHERE e.category_id = c.id AND e.voided_at IS NULL) AS last_paid,
       (SELECT e.amount_minor FROM expenses e WHERE e.category_id = c.id AND e.voided_at IS NULL ORDER BY e.spent_on DESC, e.created_at DESC LIMIT 1) AS last_amount
     FROM expense_categories c WHERE c.active = 1 AND c.type = 'recurring' AND c.frequency IS NOT NULL
     ORDER BY c.name COLLATE NOCASE`,
  ).map((c) => {
    const due = c.last_paid ? nextDue(c.frequency!, c.last_paid) : null;
    return {
      categoryId: c.id,
      name: c.name,
      frequency: FREQUENCIES.find((f) => f.value === c.frequency)?.label ?? c.frequency,
      lastPaid: c.last_paid,
      dueOn: due,
      status: due === null ? 'never' : due < t ? 'overdue' : due <= addDays(t, 7) ? 'due' : 'later',
      amountMinor: c.usual_amount_minor ?? c.last_amount,
    };
  });
}

/** Bills due within a week or overdue: for the dashboard. */
export function billsDue(): number {
  return recurring().filter((r) => r.status === 'overdue' || r.status === 'due').length;
}

// Pages ----------------------------------------------------------------------

export function expensesIndex(user: UserRow, query: Query) {
  guard(manages(user));
  const to = isDay(query.get('to')) ? query.get('to')! : today();
  const from = isDay(query.get('from')) ? query.get('from')! : `${to.slice(0, 7)}-01`;
  const category = query.get('category') ?? '';
  const where = ['e.spent_on BETWEEN ? AND ?'];
  const params: Param[] = [from, to];
  if (category) {
    where.push('e.category_id = ?');
    params.push(category);
  }
  const rows = all<{
    id: string;
    number: string;
    spent_on: string;
    category: string;
    type: ExpenseType;
    amount_minor: number;
    paid_from: PaidFrom;
    till_number: string | null;
    till_open: number | null;
    payee: string | null;
    reference: string | null;
    note: string | null;
    by: string;
    voided_at: string | null;
    void_reason: string | null;
  }>(
    `SELECT e.*, c.name AS category, c.type, t.number AS till_number, t.status = 'open' AS till_open, u.name AS by
     FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN users u ON u.id = e.user_id LEFT JOIN tills t ON t.id = e.till_id
     WHERE ${where.join(' AND ')} ORDER BY e.spent_on DESC, e.created_at DESC LIMIT 500`,
    ...params,
  );
  const kept = rows.filter((r) => !r.voided_at);
  const byCategory = new Map<string, number>();
  for (const r of kept) byCategory.set(r.category, (byCategory.get(r.category) ?? 0) + r.amount_minor);
  const byType = Object.fromEntries(TYPES.map((t) => [t.value, kept.filter((r) => r.type === t.value).reduce((sum, r) => sum + r.amount_minor, 0)]));

  return {
    expenses: rows.map((r) => ({
      id: r.id,
      number: r.number,
      spentOn: r.spent_on,
      category: r.category,
      typeLabel: typeLabel(r.type),
      amountMinor: r.amount_minor,
      paidFrom: r.paid_from,
      paidFromLabel: paidFromLabel(r.paid_from),
      tillNumber: r.till_number,
      payee: r.payee,
      reference: r.reference,
      note: r.note,
      by: r.by,
      voided: r.voided_at !== null,
      voidReason: r.void_reason,
      // From a till only while its shift is open: the cash goes back in the drawer.
      canVoid: !r.voided_at && (r.paid_from !== 'till' || Boolean(r.till_open)),
    })),
    totals: {
      totalMinor: kept.reduce((sum, r) => sum + r.amount_minor, 0),
      count: kept.length,
      byType,
      byCategory: [...byCategory.entries()].map(([name, total]) => ({ name, total })).sort((a, b) => b.total - a.total),
    },
    recurring: recurring(),
    categories: categoryOptions(),
    paidFrom: PAID_FROM.filter((p) => p.value !== 'till'),
    filters: { from, to, category },
    today: today(),
  };
}

function categoryOptions() {
  return all<CategoryRow>('SELECT * FROM expense_categories WHERE active = 1 ORDER BY name COLLATE NOCASE').map((c) => ({
    id: c.id,
    name: c.name,
    type: c.type,
    typeLabel: typeLabel(c.type),
    fromTill: Boolean(c.from_till),
    usualAmount: c.usual_amount_minor === null ? '' : major(c.usual_amount_minor),
  }));
}

export function categoriesIndex(user: UserRow) {
  guard(manages(user));
  return {
    categories: all<CategoryRow & { spent: number; count: number }>(
      `SELECT c.*, COALESCE(SUM(CASE WHEN e.voided_at IS NULL THEN e.amount_minor END), 0) AS spent, COUNT(CASE WHEN e.voided_at IS NULL THEN e.id END) AS count
       FROM expense_categories c LEFT JOIN expenses e ON e.category_id = c.id
       GROUP BY c.id ORDER BY c.active DESC, c.type, c.name COLLATE NOCASE`,
    ).map((c) => ({
      id: c.id,
      name: c.name,
      type: c.type,
      typeLabel: typeLabel(c.type),
      frequency: c.frequency,
      frequencyLabel: FREQUENCIES.find((f) => f.value === c.frequency)?.label ?? null,
      usualAmount: c.usual_amount_minor === null ? '' : major(c.usual_amount_minor),
      usualAmountMinor: c.usual_amount_minor,
      fromTill: Boolean(c.from_till),
      active: Boolean(c.active),
      spentMinor: c.spent,
      count: c.count,
    })),
    types: TYPES,
    frequencies: FREQUENCIES,
  };
}

// Actions --------------------------------------------------------------------

type Data = Record<string, unknown>;

export function saveCategory(user: UserRow, id: string | null, data: Data): ActionResult {
  guard(manages(user));
  if (id && !one('SELECT id FROM expense_categories WHERE id = ?', id)) throw notFound();
  const v = new Validation();
  const name = text(data.name);
  const type = TYPES.find((t) => t.value === data.type)?.value;
  const frequency = type === 'recurring' ? FREQUENCIES.find((f) => f.value === data.frequency)?.value : null;
  const usual = text(data.usual_amount);
  const usualMinor = usual ? minor(usual) : null;
  if (!name) v.add('name', 'Enter the name.');
  else {
    const taken = one<{ id: string }>('SELECT id FROM expense_categories WHERE name = ?', name);
    if (taken && taken.id !== id) v.add('name', 'There is a category with this name.');
  }
  if (!type) v.add('type', 'Choose the type.');
  if (type === 'recurring' && !frequency) v.add('frequency', 'Choose how often it comes back.');
  if (usual && (usualMinor === null || usualMinor <= 0)) v.add('usual_amount', 'Not an amount.');
  v.check();

  const values: Param[] = [name!, type!, frequency ?? null, usualMinor, flag(bool(data.from_till)), flag(data.active === undefined ? true : bool(data.active))];
  if (id) run('UPDATE expense_categories SET name = ?, type = ?, frequency = ?, usual_amount_minor = ?, from_till = ?, active = ? WHERE id = ?', ...values, id);
  else run('INSERT INTO expense_categories (name, type, frequency, usual_amount_minor, from_till, active, created_at, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', ...values, now(), uuid());
  return done(`${name} saved.`);
}

interface NewExpense {
  category: CategoryRow;
  amount: number;
  spentOn: string;
  paidFrom: PaidFrom;
  tillId: string | null;
  cashMovementId: string | null;
  payee: string | null;
  reference: string | null;
  note: string | null;
}

/** Records an expense (in the caller's transaction); its number. */
export function insertExpense(user: UserRow, e: NewExpense): string {
  const number = nextNumber('expense', 'EX', 6);
  run(
    `INSERT INTO expenses (id, number, category_id, amount_minor, spent_on, paid_from, till_id, cash_movement_id, payee, reference, note, user_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    uuid(),
    number,
    e.category.id,
    e.amount,
    e.spentOn,
    e.paidFrom,
    e.tillId,
    e.cashMovementId,
    e.payee,
    e.reference,
    e.note,
    user.id,
    now(),
  );
  return number;
}

/** An expense paid from the safe, the bank or mobile money. Till expenses go through a shift (Cash in or out). */
export function recordExpense(user: UserRow, data: Data): ActionResult {
  guard(manages(user));
  const v = new Validation();
  const category = one<CategoryRow>('SELECT * FROM expense_categories WHERE id = ? AND active = 1', text(data.category_id) ?? '');
  const amount = minor(data.amount);
  const spentOn = text(data.spent_on);
  const paidFrom = PAID_FROM.find((p) => p.value === data.paid_from && p.value !== 'till')?.value;
  if (!category) v.add('category_id', 'Choose the category.');
  if (amount === null || amount <= 0) v.add('amount', 'Enter an amount above zero.');
  if (!spentOn || !isDay(spentOn)) v.add('spent_on', 'Enter the day it was paid.');
  else if (spentOn > today()) v.add('spent_on', 'It cannot be paid in the future.');
  if (data.paid_from === 'till') v.add('paid_from', 'Cash from the till is paid out from a shift, under My shift.');
  else if (!paidFrom) v.add('paid_from', 'Choose how it was paid.');
  v.check();

  const number = transaction(() =>
    insertExpense(user, {
      category: category!,
      amount: amount!,
      spentOn: spentOn!,
      paidFrom: paidFrom!,
      tillId: null,
      cashMovementId: null,
      payee: text(data.payee),
      reference: text(data.reference),
      note: text(data.note),
    }),
  );
  return done(`Expense ${number} recorded.`);
}

/** Takes an expense back. One paid from the till only while its shift is open: the cash goes back in its drawer. */
export function voidExpense(user: UserRow, id: string, data: Data): ActionResult {
  guard(manages(user));
  const expense = one<{ id: string; number: string; paid_from: PaidFrom; till_id: string | null; cash_movement_id: string | null; voided_at: string | null }>(
    'SELECT * FROM expenses WHERE id = ?',
    id,
  );
  if (!expense) throw notFound();
  const why = text(data.reason);
  if (expense.voided_at) throw invalid('reason', 'Already taken back.');
  if (!why) throw invalid('reason', 'Say why it is taken back.');
  transaction(() => {
    if (expense.paid_from === 'till') {
      const open = one("SELECT id FROM tills WHERE id = ? AND status = 'open'", expense.till_id ?? '');
      if (!open) throw invalid('reason', 'Its shift is closed: the drawer was counted with this paid out. Record a correction instead.');
      run('UPDATE expenses SET cash_movement_id = NULL WHERE id = ?', expense.id);
      if (expense.cash_movement_id) run('DELETE FROM cash_movements WHERE id = ?', expense.cash_movement_id);
    }
    run('UPDATE expenses SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?', now(), user.id, why, expense.id);
  });
  return done(`Expense ${expense.number} taken back.`);
}

// The till -------------------------------------------------------------------

/** Categories a shift may pay out of its drawer. */
export function tillCategories(): { id: string; name: string }[] {
  return all<{ id: string; name: string }>('SELECT id, name FROM expense_categories WHERE active = 1 AND from_till = 1 ORDER BY name COLLATE NOCASE');
}

/** For a shift's cash form: the category of a paid-out (or why it cannot be one). */
export function tillCategory(id: unknown): CategoryRow {
  const category = typeof id === 'string' ? one<CategoryRow>('SELECT * FROM expense_categories WHERE id = ? AND active = 1', id) : null;
  if (!category) throw invalid('category_id', 'Choose what the expense was for.');
  if (!category.from_till) throw invalid('category_id', `${category.name} is not paid from the till.`);
  return category;
}

/** Expenses between two days, by category: for the reports. */
export function expenseTotals(from: string, to: string) {
  const rows = all<{ name: string; type: ExpenseType; total: number; count: number }>(
    `SELECT c.name, c.type, SUM(e.amount_minor) AS total, COUNT(*) AS count
     FROM expenses e JOIN expense_categories c ON c.id = e.category_id
     WHERE e.voided_at IS NULL AND e.spent_on BETWEEN ? AND ? GROUP BY c.id ORDER BY total DESC`,
    from,
    to,
  );
  return {
    totalMinor: rows.reduce((sum, r) => sum + r.total, 0),
    byCategory: rows.map((r) => ({ key: r.name, count: r.count, total: r.total, type: typeLabel(r.type) })),
  };
}
