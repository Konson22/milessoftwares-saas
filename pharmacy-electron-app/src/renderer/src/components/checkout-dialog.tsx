import { CheckCircle2, CloudOff, Loader2, Printer } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

import { api, errorMessage, isOffline } from '@/lib/api';
import { place } from '@/lib/config';
import { currencyCode, money, toMinor } from '@/lib/format';
import { OFFLINE_NOTE, printReceipt, queuedSaleAsSale } from '@/lib/receipt';
import type { Counter, PaymentMethod, Sale, SalePayload } from '@/lib/types';
import { useConnectivity } from '@/offline/connectivity';
import { useOutbox, type QueuedSale } from '@/offline/outbox';
import { useAuth, useOwner } from '@/store/auth';
import { cartTotals, serialsOf, useCart } from '@/store/cart';
import { toast } from '@/store/ui';

import { Button, Field, Input, Modal, Notice, Select } from './ui';

type Done = { kind: 'sent'; sale: Sale } | { kind: 'queued'; entry: QueuedSale };

/**
 * Payment and the sale. Online, the sale goes to the pharmacy; when the
 * pharmacy cannot be reached (or the call never gets an answer) it is kept
 * in the outbox with the same submission id and sent when the connection is
 * back, so the customer is never kept waiting and never charged twice.
 */
export function CheckoutDialog({ counter, onClose }: { counter: Counter; onClose: () => void }) {
  const cart = useCart();
  const owner = useOwner();
  const me = useAuth((s) => s.me);
  const online = useConnectivity((s) => s.online);
  const totals = useMemo(() => cartTotals(cart.lines), [cart.lines]);
  const [method, setMethod] = useState<PaymentMethod>(counter.payment_methods[0]?.value ?? 'cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<Done | null>(null);

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
      items: cart.lines.map((l) => ({
        item_id: l.item.id,
        quantity: l.quantity,
        discount_percent: l.discount_percent || undefined,
        pack_id: l.pack_id,
        serials: l.item.tracks_serials ? serialsOf(l) : undefined,
      })),
      payment_method: method,
      payment_reference: reference.trim() || null,
      amount_tendered_minor: method === 'cash' ? (tenderedMinor ?? totals.total) : null,
      customer_name: cart.customerName.trim() || null,
      customer_phone: cart.customerPhone.trim() || null,
    };

    setBusy(true);
    try {
      if (online) {
        try {
          const { sale } = await api.sell(payload);
          cart.clear();
          setDone({ kind: 'sent', sale });
          return;
        } catch (e) {
          if (!isOffline(e)) return setError(errorMessage(e));
        }
      }
      const entry: Omit<QueuedSale, 'queued_at' | 'error' | 'attempts'> = {
        kind: 'sale',
        id: payload.submission_id,
        owner,
        payload,
        lines: cart.lines,
        total_minor: totals.total,
        discount_minor: totals.discount,
        payment_method: method,
        payment_label: counter.payment_methods.find((m) => m.value === method)?.label ?? method,
        sold_by: me?.user.name ?? '',
      };
      useOutbox.getState().enqueue(entry);
      cart.clear();
      setDone({ kind: 'queued', entry: useOutbox.getState().entries.find((x) => x.id === entry.id) as QueuedSale });
    } finally {
      setBusy(false);
    }
  };

  if (done) return <SaleDone done={done} counter={counter} onClose={onClose} />;

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
            {online ? 'Complete sale' : 'Complete sale offline'}
          </Button>
        </>
      }
    >
      <form id="checkout" onSubmit={submit} className="space-y-4">
        <div className="flex items-baseline justify-between border-b border-border pb-3">
          <span className="text-muted-foreground">Total</span>
          <span className="text-3xl font-semibold tabular">{money(totals.total)}</span>
        </div>
        {!online ? (
          <Notice>
            <CloudOff size={14} className="mr-1 inline" /> No connection: the sale is kept on this PC and sent to the {place()} when the connection
            is back.
          </Notice>
        ) : null}
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

function SaleDone({ done, counter, onClose }: { done: Done; counter: Counter; onClose: () => void }) {
  const sale = done.kind === 'sent' ? done.sale : queuedSaleAsSale(done.entry, currencyCode());
  const [printing, setPrinting] = useState(false);

  const print = async () => {
    setPrinting(true);
    try {
      let copy: number | null = null;
      if (done.kind === 'sent') copy = (await api.printed(sale.id).catch(() => ({ copy: null }))).copy;
      await printReceipt(sale, counter.receipt, copy, done.kind === 'queued' ? OFFLINE_NOTE : null);
    } catch (e) {
      toast(errorMessage(e), 'red');
    } finally {
      setPrinting(false);
    }
  };

  return (
    <Modal
      title={done.kind === 'sent' ? `Sale ${sale.number}` : 'Sale kept offline'}
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
        {done.kind === 'sent' ? (
          <CheckCircle2 size={44} className="mx-auto text-emerald-600" />
        ) : (
          <CloudOff size={44} className="mx-auto text-amber-600" />
        )}
        <div className="text-3xl font-semibold tabular">{money(sale.total_minor)}</div>
        {sale.change_minor ? <div className="text-lg">Change: {money(sale.change_minor)}</div> : null}
        <p className="text-sm text-muted-foreground">
          {done.kind === 'sent'
            ? `Recorded by the ${place()}, paid by ${sale.payment_method_label.toLowerCase()}.`
            : `Saved on this PC. It is sent to the ${place()} on its own when the connection is back, and gets its receipt number then.`}
        </p>
      </div>
    </Modal>
  );
}
