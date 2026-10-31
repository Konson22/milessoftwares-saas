import { ChevronLeft, ChevronRight, CloudOff, Loader2, Printer, ReceiptText, Undo2, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';

import { Badge, Button, cn, Empty, Field, Input, Modal, Notice, PageHeader, Panel } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { place } from '@/lib/config';
import { currencyCode, dateTime, isoDate, money, prettyDate, shiftDate, time } from '@/lib/format';
import { OFFLINE_NOTE, printReceipt, queuedSaleAsSale } from '@/lib/receipt';
import type { Sale, SaleSummary } from '@/lib/types';
import { cached } from '@/offline/cache';
import { useConnectivity } from '@/offline/connectivity';
import { queuedFor, useOutbox, type QueuedSale } from '@/offline/outbox';
import { useAuth, useLocal, useOwner } from '@/store/auth';
import { toast } from '@/store/ui';

export function SalesPage() {
  const [day, setDay] = useState(isoDate());
  const owner = useOwner();
  const local = useLocal();
  const online = useConnectivity((s) => s.online);
  const entries = useOutbox((s) => s.entries);
  const sent = useOutbox((s) => s.sent);
  const [list, setList] = useState<{ sales: SaleSummary[]; everyone: boolean; fresh: boolean; savedAt: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState<{ id: string } | { queued: QueuedSale } | null>(null);
  /** Bumped by a void or a return: the list again. */
  const [changes, setChanges] = useState(0);

  const queued = useMemo(
    () => queuedFor(entries, owner).all.filter((e): e is QueuedSale => e.kind === 'sale' && isoDate(new Date(e.queued_at)) === day),
    [entries, owner, day],
  );

  // Again when the connection is back or the outbox sends something.
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    cached(owner, `sales.${day}`, () => api.sales(day))
      .then(({ data, fresh, savedAt }) => !cancelled && (setList({ ...data, fresh, savedAt }), setError(null)))
      .catch((e) => !cancelled && (setList(null), setError(errorMessage(e))))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [owner, day, online, sent.length, changes]);

  const total = (list?.sales ?? []).filter((s) => s.status === 'completed').reduce((sum, s) => sum + s.total_minor, 0);
  const queuedTotal = queued.reduce((sum, s) => sum + s.total_minor, 0);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Sales"
        description={list ? `${list.everyone ? (local ? 'Everyone’s sales' : 'Everyone’s sales at this branch') : 'Your sales'} · ${money(total + queuedTotal)}` : undefined}
        actions={
          <div className="flex items-center gap-1">
            <Button variant="secondary" size="sm" onClick={() => setDay(shiftDate(day, -1))}>
              <ChevronLeft size={16} />
            </Button>
            <span className="w-32 text-center text-sm font-medium">{prettyDate(day)}</span>
            <Button variant="secondary" size="sm" disabled={day >= isoDate()} onClick={() => setDay(shiftDate(day, 1))}>
              <ChevronRight size={16} />
            </Button>
          </div>
        }
      />

      {list && !list.fresh ? <Notice>Offline: the sales as the {place()} listed them at {dateTime(list.savedAt)}.</Notice> : null}
      {error && !list ? <Notice>{error} This day’s sales have not been loaded on this PC.</Notice> : null}

      {queued.length > 0 ? (
        <Panel title={`Made offline, waiting to send (${queued.length})`}>
          <SalesTable
            rows={queued.map((q) => ({
              key: q.id,
              number: 'Not sent yet',
              at: q.queued_at,
              by: q.sold_by,
              customer: q.payload.customer_name ?? null,
              method: q.payment_label,
              total: q.total_minor,
              badge: q.error ? <Badge tone="red">Refused</Badge> : <Badge tone="amber">Waiting</Badge>,
              onOpen: () => setOpen({ queued: q }),
            }))}
          />
        </Panel>
      ) : null}

      <Panel title={local ? undefined : `Recorded by the ${place()}`} actions={loading ? <Loader2 size={16} className="animate-spin text-muted-foreground" /> : null}>
        {list && list.sales.length > 0 ? (
          <SalesTable
            rows={list.sales.map((s) => ({
              key: s.id,
              number: s.number,
              at: s.sold_at,
              by: s.sold_by,
              customer: s.customer_name,
              method: s.payment_method_label,
              total: s.total_minor,
              badge: (
                <>
                  {s.status === 'voided' ? <Badge tone="red">Voided</Badge> : s.returned ? <Badge tone="blue">Returned</Badge> : null}
                  {s.prescription_number ? <Badge>{s.prescription_number}</Badge> : null}
                </>
              ),
              onOpen: () => setOpen({ id: s.id }),
            }))}
          />
        ) : (
          <Empty icon={<ReceiptText size={28} />} title="No sales this day" />
        )}
      </Panel>

      {open ? <SaleDialog target={open} onClose={() => setOpen(null)} onChanged={() => setChanges((n) => n + 1)} /> : null}
    </div>
  );
}

interface Row {
  key: string;
  number: string;
  at: string;
  by: string;
  customer: string | null;
  method: string;
  total: number;
  badge: React.ReactNode;
  onOpen: () => void;
}

function SalesTable({ rows }: { rows: Row[] }) {
  return (
    <table className="w-full text-sm">
      <thead className="bg-muted text-left text-xs text-muted-foreground">
        <tr>
          <th className="px-4 py-2 font-medium">Receipt</th>
          <th className="px-4 py-2 font-medium">Time</th>
          <th className="px-4 py-2 font-medium">Sold by</th>
          <th className="px-4 py-2 font-medium">Customer</th>
          <th className="px-4 py-2 font-medium">Paid by</th>
          <th className="px-4 py-2 text-right font-medium">Total</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-border">
        {rows.map((r) => (
          <tr key={r.key} onClick={r.onOpen} className="cursor-pointer hover:bg-accent">
            <td className="px-4 py-2.5 font-medium">
              <span className="flex items-center gap-2">
                {r.number} {r.badge}
              </span>
            </td>
            <td className="px-4 py-2.5 tabular">{time(r.at)}</td>
            <td className="px-4 py-2.5">{r.by}</td>
            <td className="px-4 py-2.5">{r.customer ?? '—'}</td>
            <td className="px-4 py-2.5">{r.method}</td>
            <td className="px-4 py-2.5 text-right font-medium tabular">{money(r.total)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

type View = 'view' | 'void' | 'return';

/** A sale, to print again. Offline, a manager also voids it or takes a return here (online, on the web). */
function SaleDialog({ target, onClose, onChanged }: { target: { id: string } | { queued: QueuedSale }; onClose: () => void; onChanged: () => void }) {
  const owner = useOwner();
  const local = useLocal();
  const [view, setView] = useState<View>('view');
  const counter = useAuth((s) => s.counter);
  const [sale, setSale] = useState<Sale | null>('queued' in target ? queuedSaleAsSale(target.queued, currencyCode()) : null);
  const [error, setError] = useState<string | null>(null);
  const queued = 'queued' in target ? target.queued : null;

  useEffect(() => {
    if (!('id' in target)) return;
    cached(owner, `sale.${target.id}`, () => api.sale(target.id))
      .then(({ data }) => setSale(data))
      .catch((e) => setError(errorMessage(e)));
  }, [owner, target]);

  const print = async () => {
    if (!sale || !counter) return;
    try {
      const copy = queued ? null : (await api.printed(sale.id).catch(() => ({ copy: null }))).copy;
      await printReceipt(sale, counter.receipt, copy, queued ? OFFLINE_NOTE : null);
    } catch (e) {
      toast(errorMessage(e), 'red');
    }
  };

  const changed = (next: Sale, message: string) => {
    setSale(next);
    setView('view');
    toast(message);
    onChanged();
  };

  return (
    <Modal
      title={queued ? 'Sale made offline' : `Sale ${sale?.number ?? ''}`}
      onClose={onClose}
      wide
      footer={
        view === 'view' ? (
          <>
            {local && sale?.can_void ? (
              <Button variant="secondary" onClick={() => setView('void')}>
                <XCircle size={16} /> Void
              </Button>
            ) : null}
            {local && sale?.can_return ? (
              <Button variant="secondary" onClick={() => setView('return')}>
                <Undo2 size={16} /> Return
              </Button>
            ) : null}
            <Button variant="secondary" onClick={() => void print()} disabled={!sale || !counter}>
              <Printer size={16} /> Print receipt
            </Button>
          </>
        ) : null
      }
    >
      {error ? <Notice>{error}</Notice> : null}
      {!sale ? (
        !error && <Loader2 className="mx-auto animate-spin text-muted-foreground" />
      ) : view === 'void' ? (
        <VoidForm sale={sale} onCancel={() => setView('view')} onDone={(s) => changed(s, `Sale ${s.number} voided; its stock is back on the shelf.`)} />
      ) : view === 'return' ? (
        <ReturnForm sale={sale} onCancel={() => setView('view')} onDone={(s, n, total) => changed(s, `Return ${n}: give back ${money(total)}.`)} />
      ) : (
        <div className="space-y-4">
          {queued ? (
            <Notice tone={queued.error ? 'red' : 'amber'}>
              <CloudOff size={14} className="mr-1 inline" />
              {queued.error ? `The ${place()} refused it: ${queued.error} See Sync.` : `Waiting to be sent to the ${place()}.`}
            </Notice>
          ) : null}
          {sale.status === 'voided' ? (
            <Notice tone="red">
              Voided{sale.voided_by ? ` by ${sale.voided_by}` : ''}
              {sale.voided_at ? ` at ${dateTime(sale.voided_at)}` : ''}: {sale.void_reason}
            </Notice>
          ) : null}
          <div className="grid grid-cols-3 gap-3 text-sm">
            <Info label="Date" value={dateTime(sale.sold_at)} />
            <Info label="Sold by" value={sale.sold_by} />
            <Info label="Customer" value={sale.customer_name ?? '—'} />
            <Info label="Paid by" value={sale.payment_method_label + (sale.payment_reference ? ` · ${sale.payment_reference}` : '')} />
            <Info label="Cash given" value={sale.amount_tendered_minor != null ? money(sale.amount_tendered_minor) : '—'} />
            <Info label="Change" value={sale.change_minor != null ? money(sale.change_minor) : '—'} />
          </div>
          <table className="w-full text-sm">
            <tbody className="divide-y divide-border border-y border-border">
              {sale.receipt_lines.map((l, i) => (
                <tr key={i}>
                  <td className="py-2">
                    {l.name} {l.pack ? <span className="text-muted-foreground">({l.pack})</span> : null}
                  </td>
                  <td className="py-2 tabular">
                    {l.quantity} × {money(l.unit_price_minor, false)}
                  </td>
                  <td className="py-2 text-right text-emerald-700 tabular">{l.discount_minor ? `-${money(l.discount_minor, false)}` : ''}</td>
                  <td className="py-2 text-right font-medium tabular">{money(l.line_total_minor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="flex justify-between text-lg font-semibold">
            <span>Total</span>
            <span className="tabular">{money(sale.total_minor)}</span>
          </div>
          {sale.returns.length > 0 ? (
            <div className="space-y-1 text-sm">
              <div className="font-medium">Returns</div>
              {sale.returns.map((r) => (
                <div key={r.id} className="flex justify-between text-muted-foreground">
                  <span>
                    {r.number} · {dateTime(r.returned_at)} · {r.reason}
                  </span>
                  <span className="tabular">-{money(r.total_minor)}</span>
                </div>
              ))}
            </div>
          ) : null}
        </div>
      )}
    </Modal>
  );
}

function VoidForm({ sale, onCancel, onDone }: { sale: Sale; onCancel: () => void; onDone: (sale: Sale) => void }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onDone(await api.voidSale(sale.id, reason));
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-3">
      <Notice>Voiding cancels the whole sale: its stock goes back on the shelf and {money(sale.total_minor)} comes off the shift’s takings.</Notice>
      {error ? <Notice tone="red">{error}</Notice> : null}
      <Field label="Why">
        <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} required placeholder="Rung up by mistake" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" variant="danger" disabled={busy}>
          Void sale
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function ReturnForm({ sale, onCancel, onDone }: { sale: Sale; onCancel: () => void; onDone: (sale: Sale, number: string, total: number) => void }) {
  const open = sale.lines.filter((l) => l.returned < l.quantity);
  const [quantities, setQuantities] = useState<Record<string, string>>({});
  const [restock, setRestock] = useState<Record<string, boolean>>(Object.fromEntries(open.map((l) => [l.id, true])));
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const lines = open
        .map((l) => ({ sale_line_id: l.id, quantity: Number(quantities[l.id] || 0), restock: restock[l.id] ?? true }))
        .filter((l) => l.quantity > 0);
      const result = await api.returnSale(sale.id, { reason, lines });
      onDone(result.sale, result.number, result.total_minor);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-3">
      {error ? <Notice tone="red">{error}</Notice> : null}
      <table className="w-full text-sm">
        <thead className="text-left text-xs text-muted-foreground">
          <tr>
            <th className="py-1 font-medium">Medicine, batch</th>
            <th className="py-1 font-medium">Can come back</th>
            <th className="py-1 font-medium">Coming back</th>
            <th className="py-1 font-medium">Back on the shelf</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {open.map((l) => (
            <tr key={l.id}>
              <td className="py-2">
                {l.name} <span className="text-xs text-muted-foreground">{l.batch_number}</span>
              </td>
              <td className="py-2 tabular">{l.quantity - l.returned}</td>
              <td className="py-2">
                <Input
                  type="number"
                  min={0}
                  max={l.quantity - l.returned}
                  value={quantities[l.id] ?? ''}
                  onChange={(e) => setQuantities({ ...quantities, [l.id]: e.target.value })}
                  className="w-24"
                />
              </td>
              <td className="py-2">
                <input type="checkbox" checked={restock[l.id] ?? true} onChange={(e) => setRestock({ ...restock, [l.id]: e.target.checked })} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="text-xs text-muted-foreground">Untick “back on the shelf” for medicine that cannot be sold again (opened, damaged).</p>
      <Field label="Why">
        <Input value={reason} onChange={(e) => setReason(e.target.value)} required placeholder="Wrong strength" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" disabled={busy}>
          Record return
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}


function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div>{value}</div>
    </div>
  );
}
