import { ArrowRightLeft, Ban, Minus, Plus, Printer, Search, Send, Trash2, Wallet, X } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

import { Badge, Button, Field, Input, Modal, Notice, PageHeader, Panel, Select, cn } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { money, time, toMinor } from '@/lib/format';
import { billHtml } from '@/lib/receipt';
import { useAuth, useBranch } from '@/store/auth';
import { toast, useUi } from '@/store/ui';

import type { ScreenProps } from './types';

type LineStatus = 'new' | 'preparing' | 'ready' | 'served' | 'voided';

interface Line {
  id: string;
  name: string;
  kind: 'dish' | 'drink';
  quantity: number;
  note: string | null;
  status: LineStatus;
  ticket: string | null;
  voidReason: string | null;
  voidedBy: string | null;
}

interface MenuItem {
  id: string;
  name: string;
  kind: 'dish' | 'drink';
  section: string | null;
  priceMinor: number;
}

interface Props {
  order: {
    id: string;
    number: string;
    service: 'dine_in' | 'takeaway';
    table: { id: string; name: string } | null;
    guests: number | null;
    customerName: string | null;
    status: 'open' | 'paid' | 'cancelled';
    openedAt: string;
    openedBy: string;
    cancelReason: string | null;
    bill: { id: string; number: string; totalMinor: number; status: string } | null;
    lines: Line[];
  };
  bill: {
    lines: { item_id: string; name: string; quantity: number; unit_price_minor: number; total_minor: number }[];
    total_minor: number;
  };
  menu: MenuItem[];
  freeTables: { id: string; name: string }[];
  paymentMethods: { value: string; label: string }[];
  tillOpen: boolean;
  can: { void: boolean; pay: boolean };
}

type Draft = { key: number; item: MenuItem; quantity: number; note: string };

const STATUS: Record<LineStatus, { label: string; tone: 'neutral' | 'amber' | 'green' | 'blue' | 'red' }> = {
  new: { label: 'Not sent', tone: 'blue' },
  preparing: { label: 'Cooking', tone: 'amber' },
  ready: { label: 'Ready', tone: 'green' },
  served: { label: 'Served', tone: 'neutral' },
  voided: { label: 'Voided', tone: 'red' },
};

/**
 * One order: the menu to add from, what the kitchen has, and the bill. New
 * dishes are gathered here first, added to the order together, then sent to
 * the kitchen on one ticket. The cashier takes the bill into their shift.
 */
export function OrderScreen({ page, path, reload }: ScreenProps<Props>) {
  const { order, bill, menu, freeTables, paymentMethods, tillOpen, can } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const workspaceName = useAuth((s) => s.me?.organization.name ?? '');
  const branch = useBranch();
  const openPage = useUi((s) => s.open);
  const isOpen = order.status === 'open';

  const [search, setSearch] = useState('');
  const [section, setSection] = useState<string | null>(null);
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [nextKey, setNextKey] = useState(0);
  const [voiding, setVoiding] = useState<Line | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [moving, setMoving] = useState(false);
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');

  const sections = useMemo(() => [...new Set(menu.map((item) => item.section ?? 'Other'))], [menu]);
  const shown = menu.filter(
    (item) => (section === null || (item.section ?? 'Other') === section) && (search.trim() === '' || item.name.toLowerCase().includes(search.trim().toLowerCase())),
  );
  const newLines = order.lines.filter((line) => line.status === 'new');
  const draftTotal = drafts.reduce((sum, draft) => sum + draft.quantity * draft.item.priceMinor, 0);
  const where = order.table ? `Table ${order.table.name}` : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`;
  const tenderedMinor = tendered.trim() === '' ? null : toMinor(tendered);
  const change = tenderedMinor !== null ? tenderedMinor - bill.total_minor : null;

  const pick = (item: MenuItem) => {
    const same = drafts.find((draft) => draft.item.id === item.id && draft.note === '');
    if (same) {
      setDrafts(drafts.map((draft) => (draft === same ? { ...draft, quantity: draft.quantity + 1 } : draft)));
    } else {
      setDrafts([...drafts, { key: nextKey, item, quantity: 1, note: '' }]);
      setNextKey(nextKey + 1);
    }
  };

  const changeDraft = (key: number, patch: Partial<Draft>) =>
    setDrafts(drafts.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)).filter((draft) => draft.quantity > 0));

  const addDrafts = async (thenSend: boolean) => {
    const ok = await run('POST', `orders/${order.id}/lines`, {
      lines: drafts.map((draft) => ({ item_id: draft.item.id, quantity: draft.quantity, note: draft.note.trim() || null })),
    });
    if (!ok) return;
    setDrafts([]);
    if (thenSend) await run('POST', `orders/${order.id}/send`);
  };

  const withReason = async (e: FormEvent) => {
    e.preventDefault();
    const ok = voiding
      ? await run('POST', `orders/${order.id}/lines/${voiding.id}/void`, { reason: reason.trim() })
      : await run('POST', `orders/${order.id}/cancel`, { reason: reason.trim() });
    if (ok) {
      setVoiding(null);
      setCancelling(false);
    }
  };

  const pay = async (e: FormEvent) => {
    e.preventDefault();
    if (newLines.length > 0 || drafts.length > 0) return toast('Send the new lines to the kitchen, or take them off, before the bill.', 'amber');
    await run('POST', `orders/${order.id}/pay`, {
      payment_method: method,
      amount_tendered: method === 'cash' && tendered.trim() !== '' ? tendered.trim() : null,
      payment_reference: method !== 'cash' && reference.trim() !== '' ? reference.trim() : null,
    });
  };

  const printBill = () =>
    void window.desktop.print(
      billHtml({
        business: workspaceName,
        branch: branch?.name ?? null,
        title: 'Bill',
        details: [`${where} · ${order.number}`, `Opened ${time(order.openedAt)} by ${order.openedBy}`],
        lines: bill.lines.map((line) => ({ name: line.name, quantity: line.quantity, unitPriceMinor: line.unit_price_minor, totalMinor: line.total_minor })),
        totalMinor: bill.total_minor,
        footer: ['Not yet paid.'],
      }),
    );

  return (
    <div className="space-y-4">
      <PageHeader
        title={where}
        description={`${order.number} · opened ${time(order.openedAt)} by ${order.openedBy}${order.guests ? ` · ${order.guests} guests` : ''}`}
        actions={
          <>
            <Badge tone={order.status === 'open' ? 'blue' : order.status === 'paid' ? 'green' : 'red'}>{order.status}</Badge>
            {isOpen && bill.lines.length > 0 ? (
              <Button variant="secondary" size="sm" onClick={printBill}>
                <Printer size={14} /> Print bill
              </Button>
            ) : null}
            {isOpen && order.service === 'dine_in' && freeTables.length > 0 ? (
              <Button variant="secondary" size="sm" onClick={() => setMoving(true)}>
                <ArrowRightLeft size={14} /> Move
              </Button>
            ) : null}
            {isOpen ? (
              <Button variant="secondary" size="sm" onClick={() => (setReason(''), setCancelling(true))}>
                <Ban size={14} /> Cancel order
              </Button>
            ) : null}
          </>
        }
      />

      {order.status === 'cancelled' ? <Notice tone="red">Cancelled: {order.cancelReason}</Notice> : null}
      {order.bill ? (
        <Notice tone="green">
          Paid on bill {order.bill.number}, {money(order.bill.totalMinor)}.{' '}
          <button type="button" className="font-medium underline" onClick={() => openPage(`bills/${order.bill!.id}`)}>
            Open the bill
          </button>
        </Notice>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-5">
        {isOpen ? (
          <div className="space-y-4 lg:col-span-3">
            <Panel>
              <div className="space-y-3 p-4">
                <div className="relative">
                  <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
                  <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Find a dish or drink" aria-label="Find on the menu" className="pl-9" />
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant={section === null ? 'primary' : 'secondary'} onClick={() => setSection(null)}>
                    All
                  </Button>
                  {sections.map((name) => (
                    <Button key={name} size="sm" variant={section === name ? 'primary' : 'secondary'} onClick={() => setSection(name)}>
                      {name}
                    </Button>
                  ))}
                </div>
                {shown.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Nothing on the menu matches.</p>
                ) : (
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                    {shown.map((item) => (
                      <button key={item.id} type="button" onClick={() => pick(item)} className="border border-border p-2.5 text-left transition hover:bg-accent active:scale-[0.98]">
                        <span className="block text-sm font-medium">{item.name}</span>
                        <span className="text-xs text-muted-foreground tabular">{money(item.priceMinor)}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </Panel>

            {drafts.length > 0 ? (
              <Panel title="Adding" actions={<span className="text-sm font-medium tabular">{money(draftTotal)}</span>}>
                <ul className="divide-y divide-border">
                  {drafts.map((draft) => (
                    <li key={draft.key} className="flex flex-wrap items-center gap-2 px-4 py-2">
                      <span className="min-w-32 flex-1 text-sm font-medium">{draft.item.name}</span>
                      <div className="flex items-center gap-1">
                        <Button size="sm" variant="secondary" className="size-7 px-0" aria-label={`One fewer ${draft.item.name}`} onClick={() => changeDraft(draft.key, { quantity: draft.quantity - 1 })}>
                          <Minus size={12} />
                        </Button>
                        <span className="w-6 text-center tabular">{draft.quantity}</span>
                        <Button size="sm" variant="secondary" className="size-7 px-0" aria-label={`One more ${draft.item.name}`} onClick={() => changeDraft(draft.key, { quantity: draft.quantity + 1 })}>
                          <Plus size={12} />
                        </Button>
                      </div>
                      {draft.item.kind === 'dish' ? (
                        <Input
                          value={draft.note}
                          onChange={(e) => changeDraft(draft.key, { note: e.target.value })}
                          placeholder="Note: no onions…"
                          aria-label={`Note for ${draft.item.name}`}
                          maxLength={160}
                          className="h-8 w-48"
                        />
                      ) : null}
                      <button type="button" onClick={() => changeDraft(draft.key, { quantity: 0 })} className="p-1 text-muted-foreground hover:text-foreground" aria-label={`Take off ${draft.item.name}`}>
                        <X size={15} />
                      </button>
                    </li>
                  ))}
                </ul>
                <div className="flex justify-end gap-2 border-t border-border px-4 py-3">
                  <Button variant="secondary" disabled={busy} onClick={() => void addDrafts(false)}>
                    Add to order
                  </Button>
                  <Button disabled={busy} onClick={() => void addDrafts(true)}>
                    <Send size={15} /> Add and send to kitchen
                  </Button>
                </div>
              </Panel>
            ) : null}
          </div>
        ) : null}

        <div className={cn('space-y-4', isOpen ? 'lg:col-span-2' : 'lg:col-span-5')}>
          <Panel
            title="On the order"
            actions={
              isOpen && newLines.length > 0 ? (
                <Button size="sm" disabled={busy} onClick={() => void run('POST', `orders/${order.id}/send`)}>
                  <Send size={14} /> Send {newLines.length} to kitchen
                </Button>
              ) : null
            }
          >
            {order.lines.length === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-muted-foreground">Nothing yet: pick from the menu.</p>
            ) : (
              <ul className="divide-y divide-border">
                {order.lines.map((line) => (
                  <li key={line.id} className={cn('flex items-start gap-2 px-4 py-2.5', line.status === 'voided' && 'opacity-60')}>
                    <span className="w-6 shrink-0 font-medium tabular">{line.quantity}×</span>
                    <span className="min-w-0 flex-1">
                      <span className={cn('block text-sm font-medium', line.status === 'voided' && 'line-through')}>{line.name}</span>
                      {line.note ? <span className="block text-xs text-muted-foreground">“{line.note}”</span> : null}
                      {line.status === 'voided' ? (
                        <span className="block text-xs text-red-700">
                          {line.voidReason}
                          {line.voidedBy ? ` · ${line.voidedBy}` : ''}
                        </span>
                      ) : null}
                    </span>
                    <Badge tone={STATUS[line.status].tone}>
                      {STATUS[line.status].label}
                      {line.ticket ? ` · ${line.ticket}` : ''}
                    </Badge>
                    {isOpen && line.status === 'new' ? (
                      <button type="button" disabled={busy} onClick={() => void run('DELETE', `orders/${order.id}/lines/${line.id}`)} className="p-1 text-muted-foreground hover:text-destructive" aria-label={`Take off ${line.name}`}>
                        <Trash2 size={15} />
                      </button>
                    ) : null}
                    {isOpen && can.void && line.status !== 'new' && line.status !== 'voided' ? (
                      <button type="button" disabled={busy} onClick={() => (setReason(''), setVoiding(line))} className="p-1 text-muted-foreground hover:text-destructive" aria-label={`Void ${line.name}`}>
                        <Ban size={15} />
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {isOpen ? (
            <Panel title="Bill" actions={<span className="text-lg font-semibold tabular">{money(bill.total_minor)}</span>}>
              {bill.lines.length > 0 ? (
                <ul className="divide-y divide-border text-sm">
                  {bill.lines.map((line) => (
                    <li key={line.item_id} className="flex justify-between gap-2 px-4 py-1.5">
                      <span>
                        {line.quantity} × {line.name}
                      </span>
                      <span className="tabular">{money(line.total_minor)}</span>
                    </li>
                  ))}
                </ul>
              ) : null}

              {can.pay && bill.lines.length > 0 ? (
                tillOpen ? (
                  <form onSubmit={pay} className="space-y-3 border-t border-border p-4">
                    <div className="grid grid-cols-2 gap-3">
                      <Field label="Paid by" error={errors.payment_method}>
                        <Select value={method} onChange={(e) => setMethod(e.target.value)}>
                          {paymentMethods.map((m) => (
                            <option key={m.value} value={m.value}>
                              {m.label}
                            </option>
                          ))}
                        </Select>
                      </Field>
                      {method === 'cash' ? (
                        <Field
                          label="Cash given"
                          error={errors.amount_tendered}
                          hint={change !== null && change >= 0 ? `Change ${money(change)}` : change !== null ? 'Less than the bill' : 'Optional'}
                        >
                          <Input inputMode="decimal" value={tendered} onChange={(e) => setTendered(e.target.value)} placeholder={money(bill.total_minor, false)} />
                        </Field>
                      ) : (
                        <Field label="Reference" error={errors.payment_reference} hint="Optional">
                          <Input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={64} />
                        </Field>
                      )}
                    </div>
                    <Button type="submit" size="lg" className="w-full" disabled={busy || (change !== null && change < 0)}>
                      <Wallet size={17} /> Take {money(bill.total_minor)}
                    </Button>
                  </form>
                ) : (
                  <div className="space-y-2 border-t border-border p-4">
                    <Notice>Start your shift to take the bill.</Notice>
                    <Button variant="secondary" onClick={() => openPage('tills')}>
                      <Wallet size={15} /> Go to my shift
                    </Button>
                  </div>
                )
              ) : null}
            </Panel>
          ) : null}
        </div>
      </div>

      {voiding || cancelling ? (
        <Modal
          title={voiding ? `Void ${voiding.quantity} × ${voiding.name}` : `Cancel order ${order.number}`}
          onClose={() => (setVoiding(null), setCancelling(false))}
          footer={
            <>
              <Button variant="secondary" onClick={() => (setVoiding(null), setCancelling(false))}>
                Keep it
              </Button>
              <Button variant="danger" type="submit" form="reason" disabled={busy || reason.trim() === ''}>
                {voiding ? 'Void' : 'Cancel order'}
              </Button>
            </>
          }
        >
          <form id="reason" onSubmit={withReason} className="space-y-3">
            <p className="text-sm text-muted-foreground">
              {voiding ? 'The kitchen has it: it stays on the ticket, crossed out, and is never billed.' : 'Everything on it is voided and the table is free again.'}
            </p>
            <Field label="Reason" error={errors.reason}>
              <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} placeholder="Sent back, ordered by mistake…" />
            </Field>
          </form>
        </Modal>
      ) : null}

      {moving ? (
        <Modal title={`Move ${where} to`} onClose={() => setMoving(false)}>
          <div className="grid grid-cols-3 gap-2">
            {freeTables.map((table) => (
              <Button
                key={table.id}
                variant="secondary"
                disabled={busy}
                onClick={() => void run('POST', `orders/${order.id}/move`, { dining_table_id: table.id }).then((ok) => ok && setMoving(false))}
              >
                {table.name}
              </Button>
            ))}
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
