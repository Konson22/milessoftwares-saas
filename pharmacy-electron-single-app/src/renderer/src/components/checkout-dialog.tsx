import { CheckCircle2, Loader2, Printer } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

import { api, errorMessage } from '@/lib/api';
import { currencyCode, inSecond, money, toMinor } from '@/lib/format';
import { printReceipt } from '@/lib/receipt';
import type { Counter, PaymentMethod, Sale, SalePayload } from '@/lib/types';
import { cartTotals, useCart } from '@/store/cart';
import { toast } from '@/store/ui';

import { Button, Field, Input, Modal, Notice, Select } from './ui';

/**
 * Payment and the sale. The cart keeps its submission id until the sale is
 * recorded, so pressing Complete twice never sells twice.
 */
export function CheckoutDialog({ counter, onClose }: { counter: Counter; onClose: () => void }) {
  const cart = useCart();
  const totals = useMemo(() => cartTotals(cart.lines), [cart.lines]);
  const [method, setMethod] = useState<PaymentMethod>(counter.payment_methods[0]?.value ?? 'cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<Sale | null>(null);

  const tenderedMinor = tendered.trim() ? toMinor(tendered) : null;
  const change = method === 'cash' && tenderedMinor !== null ? tenderedMinor - totals.total : null;
  const rules = counter.counter;
  const needsCustomer =
    rules.require_customer_always || (rules.require_customer_above_minor !== null && totals.total > rules.require_customer_above_minor);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    if (needsCustomer && !cart.customerName.trim()) return setError('This sale needs the customer’s name: add it in the cart.');
    if (method === 'cash' && tendered.trim() && (tenderedMinor === null || tenderedMinor < totals.total)) {
      return setError('The cash given is less than the total.');
    }

    const payload: SalePayload = {
      submission_id: cart.submissionId,
      items: cart.lines.map((l) => ({ item_id: l.item.id, quantity: l.quantity, discount_percent: l.discount_percent || undefined, pack_id: l.pack_id })),
      payment_method: method,
      payment_reference: reference.trim() || null,
      amount_tendered_minor: method === 'cash' ? (tenderedMinor ?? totals.total) : null,
      customer_name: cart.customerName.trim() || null,
      customer_phone: cart.customerPhone.trim() || null,
    };

    setBusy(true);
    try {
      const { sale } = await api.sell(payload);
      cart.clear();
      setDone(sale);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (done) return <SaleDone sale={done} counter={counter} onClose={onClose} />;

  return (
    <Modal
      title="Take payment"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Back
          </Button>
          <Button type="submit" form="checkout" disabled={busy}>
            {busy ? <Loader2 size={16} className="animate-spin" /> : null}
            Complete sale
          </Button>
        </>
      }
    >
      <form id="checkout" onSubmit={submit} className="space-y-4">
        <div className="flex items-baseline justify-between border-b border-border pb-3">
          <span className="text-muted-foreground">Total</span>
          <span className="text-right">
            <span className="block text-3xl font-semibold tabular">{money(totals.total)}</span>
            {inSecond(totals.total) ? <span className="block text-sm text-muted-foreground tabular">{inSecond(totals.total)}</span> : null}
          </span>
        </div>
        {error ? <Notice tone="red">{error}</Notice> : null}

        <Field label="Paid by">
          <Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
            {counter.payment_methods.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </Select>
        </Field>

        {method === 'cash' ? (
          <Field label={`Cash given (${currencyCode()})`} hint={change !== null && change >= 0 ? `Change: ${money(change)}` : 'Empty: the exact amount'}>
            <Input autoFocus inputMode="decimal" value={tendered} onChange={(e) => setTendered(e.target.value)} placeholder={money(totals.total, false)} />
          </Field>
        ) : (
          <Field label="Reference (optional)">
            <Input autoFocus value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Transaction id" />
          </Field>
        )}
        {needsCustomer && !cart.customerName.trim() ? <Notice>This sale needs the customer’s name.</Notice> : null}
      </form>
    </Modal>
  );
}

export function SaleDone({ sale, counter, onClose }: { sale: Pick<Sale, 'id' | 'number' | 'total_minor' | 'change_minor'>; counter: Counter; onClose: () => void }) {
  const [printing, setPrinting] = useState(false);

  const print = async () => {
    setPrinting(true);
    try {
      const full = await api.sale(sale.id);
      const { copy } = await api.printed(sale.id);
      await printReceipt(full, counter.receipt, copy);
    } catch (e) {
      toast(errorMessage(e), 'red');
    } finally {
      setPrinting(false);
    }
  };

  return (
    <Modal
      title={`Sale ${sale.number}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={() => void print()} disabled={printing}>
            <Printer size={16} /> Print receipt
          </Button>
          <Button autoFocus onClick={onClose}>
            New sale
          </Button>
        </>
      }
    >
      <div className="space-y-4 text-center">
        <CheckCircle2 size={44} className="mx-auto text-emerald-600" />
        <div className="text-3xl font-semibold tabular">{money(sale.total_minor)}</div>
        {sale.change_minor ? <div className="text-lg">Change: {money(sale.change_minor)}</div> : null}
        <p className="text-sm text-muted-foreground">Recorded.</p>
      </div>
    </Modal>
  );
}
