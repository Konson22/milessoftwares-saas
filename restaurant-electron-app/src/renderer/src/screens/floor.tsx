import { Armchair, BellRing, ShoppingBag, Users } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Badge, Button, Empty, Field, Input, Modal, PageHeader, Panel, cn } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { money } from '@/lib/format';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

export interface OrderCard {
  id: string;
  number: string;
  guests: number | null;
  customerName: string | null;
  openedAt: string;
  openedBy: string;
  totalMinor: number;
  /** The kitchen has a ticket of it ready to take out. */
  hasReady: boolean;
}

interface Props {
  tables: { id: string; name: string; area: string | null; seats: number; order: OrderCard | null }[];
  takeaways: OrderCard[];
}

export function since(iso: string): string {
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * The branch's tables, free or being served, and the takeaways still open:
 * a free table opens an order, a busy one goes to its order. Loaded again
 * every few seconds, as other waiters seat tables too.
 */
export function FloorScreen({ page, path, reload }: ScreenProps<Props>) {
  const { tables, takeaways } = page.props;
  const open = useUi((s) => s.open);
  const { run, busy, errors } = useAction(path, reload);
  const [seating, setSeating] = useState<{ id: string; name: string; seats: number } | null>(null);
  const [guests, setGuests] = useState('');
  const [takeaway, setTakeaway] = useState('');
  const areas = [...new Set(tables.map((table) => table.area ?? ''))];

  const seat = async (e: FormEvent) => {
    e.preventDefault();
    if (!seating) return;
    const ok = await run('POST', 'orders', { service: 'dine_in', dining_table_id: seating.id, guests: guests ? Number(guests) : null });
    if (ok) setSeating(null);
  };

  const startTakeaway = async (e: FormEvent) => {
    e.preventDefault();
    if (await run('POST', 'orders', { service: 'takeaway', customer_name: takeaway.trim() || null })) setTakeaway('');
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="Floor"
        description="A free table seats guests; a busy one opens its order."
        actions={
          <form onSubmit={startTakeaway} className="flex items-center gap-2">
            <Input value={takeaway} onChange={(e) => setTakeaway(e.target.value)} placeholder="Name for the takeaway" aria-label="Name for the takeaway" className="w-52" />
            <Button type="submit" disabled={busy}>
              <ShoppingBag size={15} /> Takeaway
            </Button>
          </form>
        }
      />

      {tables.length === 0 ? (
        <Panel>
          <Empty icon={<Armchair />} title="No tables at this branch yet">
            The manager adds them under Tables.
          </Empty>
        </Panel>
      ) : (
        areas.map((area) => (
          <section key={area} className="space-y-2">
            {areas.length > 1 || area ? <h2 className="text-sm font-semibold text-muted-foreground">{area || 'Other'}</h2> : null}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
              {tables
                .filter((table) => (table.area ?? '') === area)
                .map((table) => (
                  <button
                    key={table.id}
                    type="button"
                    disabled={busy}
                    onClick={() => (table.order ? open(`orders/${table.order.id}`) : (setGuests(''), setSeating(table)))}
                    className={cn(
                      'flex min-h-28 flex-col justify-between border p-3 text-left transition active:scale-[0.98]',
                      table.order ? 'border-primary/40 bg-primary/5 hover:bg-primary/10' : 'border-border bg-card hover:bg-accent',
                    )}
                  >
                    <span className="flex items-start justify-between gap-2">
                      <span className="text-lg font-semibold">{table.name}</span>
                      {table.order?.hasReady ? (
                        <Badge tone="green">
                          <BellRing size={12} /> Ready
                        </Badge>
                      ) : null}
                    </span>
                    {table.order ? (
                      <span className="space-y-0.5 text-xs text-muted-foreground">
                        <span className="block text-sm font-medium text-foreground tabular">{money(table.order.totalMinor)}</span>
                        <span className="block">
                          {table.order.guests ? `${table.order.guests} guests · ` : ''}
                          {since(table.order.openedAt)} · {table.order.openedBy}
                        </span>
                      </span>
                    ) : (
                      <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Users size={12} /> {table.seats} seats · free
                      </span>
                    )}
                  </button>
                ))}
            </div>
          </section>
        ))
      )}

      {takeaways.length > 0 ? (
        <Panel title={`Takeaways (${takeaways.length})`}>
          <ul className="divide-y divide-border">
            {takeaways.map((order) => (
              <li key={order.id}>
                <button type="button" onClick={() => open(`orders/${order.id}`)} className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-accent">
                  <ShoppingBag size={16} className="text-muted-foreground" />
                  <span className="flex-1">
                    <span className="block font-medium">{order.customerName ?? order.number}</span>
                    <span className="block text-xs text-muted-foreground">
                      {order.number} · {since(order.openedAt)} · {order.openedBy}
                    </span>
                  </span>
                  {order.hasReady ? (
                    <Badge tone="green">
                      <BellRing size={12} /> Ready
                    </Badge>
                  ) : null}
                  <span className="font-medium tabular">{money(order.totalMinor)}</span>
                </button>
              </li>
            ))}
          </ul>
        </Panel>
      ) : null}

      {seating ? (
        <Modal
          title={`Seat table ${seating.name}`}
          onClose={() => setSeating(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setSeating(null)}>
                Cancel
              </Button>
              <Button type="submit" form="seat" disabled={busy}>
                Open order
              </Button>
            </>
          }
        >
          <form id="seat" onSubmit={seat}>
            <Field label="Guests" hint={`The table seats ${seating.seats}.`} error={errors.guests ?? errors.dining_table_id}>
              <Input autoFocus type="number" min={1} max={100} value={guests} onChange={(e) => setGuests(e.target.value)} />
            </Field>
          </form>
        </Modal>
      ) : null}
    </div>
  );
}
