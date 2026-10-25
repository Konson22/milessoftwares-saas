import { BellRing, Check, ChefHat, ShoppingBag } from 'lucide-react';

import { Badge, Button, Empty, PageHeader, Panel, cn } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { time } from '@/lib/format';
import { useUi } from '@/store/ui';

import { since } from './floor';
import type { ScreenProps } from './types';

interface Ticket {
  id: string;
  number: string;
  status: 'preparing' | 'ready';
  sentAt: string;
  readyAt: string | null;
  sentBy: string;
  order: { id: string; number: string; service: 'dine_in' | 'takeaway'; table: string | null; customerName: string | null };
  lines: { id: string; name: string; quantity: number; note: string | null; voided: boolean }[];
}

/**
 * The kitchen screen: tickets oldest first, cooking then ready. The kitchen
 * marks a ticket ready; the waiter marks it served when it is out. Loaded
 * again every few seconds.
 */
export function KitchenScreen({ page, path, reload }: ScreenProps<{ tickets: Ticket[] }>) {
  const { tickets } = page.props;
  const { run, busy } = useAction(path, reload);
  const open = useUi((s) => s.open);
  const cooking = tickets.filter((t) => t.status === 'preparing');
  const ready = tickets.filter((t) => t.status === 'ready');

  return (
    <div className="space-y-4">
      <PageHeader title="Kitchen" description={`${cooking.length} cooking · ${ready.length} ready to take out`} />

      {tickets.length === 0 ? (
        <Panel>
          <Empty icon={<ChefHat />} title="Nothing to cook">
            Tickets sent from the floor show up here.
          </Empty>
        </Panel>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {tickets.map((ticket) => {
            const isReady = ticket.status === 'ready';
            const minutes = Math.round((Date.now() - new Date(ticket.sentAt).getTime()) / 60000);
            return (
              <section key={ticket.id} className={cn('flex flex-col border bg-card', isReady ? 'border-emerald-400' : minutes >= 20 ? 'border-amber-400' : 'border-border')}>
                <header className={cn('flex items-start justify-between gap-2 border-b px-3 py-2', isReady ? 'border-emerald-200 bg-emerald-50' : 'border-border')}>
                  <button type="button" className="text-left" onClick={() => open(`orders/${ticket.order.id}`)}>
                    <span className="block text-lg font-semibold">
                      {ticket.order.table ? (
                        `Table ${ticket.order.table}`
                      ) : (
                        <span className="flex items-center gap-1.5">
                          <ShoppingBag size={16} /> {ticket.order.customerName ?? 'Takeaway'}
                        </span>
                      )}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                      {ticket.number} · {time(ticket.sentAt)} · {ticket.sentBy}
                    </span>
                  </button>
                  {isReady ? (
                    <Badge tone="green">
                      <BellRing size={12} /> Ready
                    </Badge>
                  ) : (
                    <Badge tone={minutes >= 20 ? 'amber' : 'neutral'}>{since(ticket.sentAt)}</Badge>
                  )}
                </header>
                <ul className="flex-1 space-y-1.5 px-3 py-2.5">
                  {ticket.lines.map((line) => (
                    <li key={line.id} className={cn(line.voided && 'text-muted-foreground line-through')}>
                      <span className="font-semibold tabular">{line.quantity}×</span> {line.name}
                      {line.note ? <span className="block pl-6 text-sm font-medium text-amber-800">“{line.note}”</span> : null}
                    </li>
                  ))}
                </ul>
                <footer className="border-t border-border p-2">
                  {isReady ? (
                    <Button variant="secondary" className="w-full" disabled={busy} onClick={() => void run('POST', `kitchen/${ticket.id}/served`)}>
                      <Check size={15} /> Served
                    </Button>
                  ) : (
                    <Button className="w-full" disabled={busy} onClick={() => void run('POST', `kitchen/${ticket.id}/ready`)}>
                      <BellRing size={15} /> Ready
                    </Button>
                  )}
                </footer>
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}
