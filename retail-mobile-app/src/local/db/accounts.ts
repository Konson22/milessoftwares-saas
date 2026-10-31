import type { CustomerOption } from '@/lib/types';
import { all, one } from './database';
import { formatMoney } from './settings';
import { invalid, today } from './util';

/**
 * Customers' accounts. An invoice is a sale on account; a customer owes its
 * total less what came back, less what they have paid. Payments are not tied
 * to an invoice: they settle the oldest first.
 */

export interface CustomerRow {
  id: string;
  number: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  note: string | null;
  on_account: number;
  credit_limit_minor: number | null;
  active: number;
  created_at: string;
}

/** What an invoice still owes: its total less returns, so far as payments have not settled it. */
export const NET = "s.total_minor - COALESCE((SELECT SUM(r.total_minor) FROM sale_returns r WHERE r.sale_id = s.id), 0)";

export function balanceOf(customerId: string): number {
  return one<{ balance: number }>(
    `SELECT
       COALESCE((SELECT SUM(${NET}) FROM sales s WHERE s.customer_id = ? AND s.payment_method = 'account' AND s.status = 'completed'), 0)
       - COALESCE((SELECT SUM(amount_minor) FROM customer_payments WHERE customer_id = ? AND voided_at IS NULL), 0) AS balance`,
    customerId,
    customerId,
  )!.balance;
}

/** What more they may owe; null: no limit. */
export function availableCredit(customer: CustomerRow): number | null {
  if (customer.credit_limit_minor === null) return null;
  return Math.max(0, customer.credit_limit_minor - balanceOf(customer.id));
}

/** Throws unless this much more may go on the customer's account. */
export function checkCredit(customer: CustomerRow, amount: number, field: string) {
  if (!customer.active) throw invalid(field, `${customer.name} is no longer a customer here.`);
  if (!customer.on_account) throw invalid(field, `${customer.name} does not buy on account. A supervisor can allow it under Customers.`);
  const available = availableCredit(customer);
  if (available !== null && amount > available) {
    throw invalid(field, `Over ${customer.name}’s credit limit: ${formatMoney(available)} is left of ${formatMoney(customer.credit_limit_minor!)}.`);
  }
}

export function customerOption(c: CustomerRow): CustomerOption {
  return {
    id: c.id,
    number: c.number,
    name: c.name,
    phone: c.phone,
    on_account: Boolean(c.on_account),
    balance_minor: balanceOf(c.id),
    available_minor: c.on_account ? availableCredit(c) : 0,
  };
}

export interface InvoiceState {
  id: string;
  number: string;
  sold_at: string;
  due_on: string | null;
  total_minor: number;
  returned_minor: number;
  /** Total less returns. */
  net_minor: number;
  paid_minor: number;
  owing_minor: number;
  status: 'paid' | 'part_paid' | 'unpaid';
  overdue: boolean;
}

/** The customer's invoices, oldest first, each settled so far as payments reach. */
export function invoicesOf(customerId: string): InvoiceState[] {
  const rows = all<{ id: string; number: string; sold_at: string; due_on: string | null; total_minor: number; net: number }>(
    `SELECT s.id, s.number, s.sold_at, s.due_on, s.total_minor, ${NET} AS net
     FROM sales s WHERE s.customer_id = ? AND s.payment_method = 'account' AND s.status = 'completed'
     ORDER BY s.sold_at`,
    customerId,
  );
  let pool = one<{ paid: number }>('SELECT COALESCE(SUM(amount_minor), 0) AS paid FROM customer_payments WHERE customer_id = ? AND voided_at IS NULL', customerId)!.paid;
  const t = today();
  return rows.map((r) => {
    const net = Math.max(0, r.net);
    const paid = Math.min(net, pool);
    pool -= paid;
    const owing = net - paid;
    return {
      id: r.id,
      number: r.number,
      sold_at: r.sold_at,
      due_on: r.due_on,
      total_minor: r.total_minor,
      returned_minor: r.total_minor - r.net,
      net_minor: net,
      paid_minor: paid,
      owing_minor: owing,
      status: owing === 0 ? 'paid' : paid > 0 ? 'part_paid' : 'unpaid',
      overdue: owing > 0 && r.due_on !== null && r.due_on < t,
    };
  });
}
