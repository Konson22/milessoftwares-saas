import { CheckCircle2, CloudOff, Database, RefreshCw, Wifi } from 'lucide-react';
import { useMemo } from 'react';

import { Badge, Button, Empty, Notice, PageHeader, Panel } from '@/components/ui';
import { currentProduct, place, PRODUCTS } from '@/lib/config';
import { dateTime, money } from '@/lib/format';
import { useCatalog } from '@/offline/catalog';
import { useConnectivity } from '@/offline/connectivity';
import { queuedFor, useOutbox, type QueuedEntry } from '@/offline/outbox';
import { syncNow, useSync } from '@/offline/sync';
import { useOwner } from '@/store/auth';
import { useCart } from '@/store/cart';
import { toast, useUi } from '@/store/ui';

/** What this PC holds for the pharmacy, and what it still has to send. */
export function SyncPage() {
  const owner = useOwner();
  const online = useConnectivity((s) => s.online);
  const lastSeenAt = useConnectivity((s) => s.lastSeenAt);
  const running = useSync((s) => s.running);
  const lastSyncAt = useSync((s) => s.lastSyncAt);
  const entries = useOutbox((s) => s.entries);
  const allSent = useOutbox((s) => s.sent);
  const queue = useMemo(() => queuedFor(entries, owner), [entries, owner]);
  const sent = useMemo(() => allSent.filter((s) => s.owner === owner), [allSent, owner]);
  const items = useCatalog((s) => s.items);
  const syncedAt = useCatalog((s) => s.syncedAt);
  const syncing = useCatalog((s) => s.syncing);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Sync"
        description={`Sales and cash movements made without a connection are kept here and sent, oldest first, as soon as the ${place()} can be reached.`}
        actions={
          <Button onClick={() => void syncNow({ catalog: true })} disabled={running}>
            <RefreshCw size={16} className={running ? 'animate-spin' : ''} /> Sync now
          </Button>
        }
      />

      <div className="grid grid-cols-3 gap-3">
        <Card icon={online ? <Wifi className="text-emerald-600" /> : <CloudOff className="text-amber-600" />} title={online ? 'Online' : 'Offline'}>
          {lastSeenAt ? `${PRODUCTS[currentProduct()].label} last reached ${dateTime(lastSeenAt)}` : 'Not reached yet this session'}
        </Card>
        <Card icon={<RefreshCw className="text-muted-foreground" />} title={`${queue.waiting.length} waiting · ${queue.refused.length} refused`}>
          {lastSyncAt ? `Last synced ${dateTime(lastSyncAt)}` : 'Not synced yet this session'}
        </Card>
        <Card icon={<Database className="text-muted-foreground" />} title={`${Object.keys(items).length} ${place() === 'shop' ? 'products' : 'medicines'} on this PC`}>
          {syncing ? 'Updating…' : syncedAt ? `Updated ${dateTime(syncedAt)}` : 'Not loaded yet: connect once'}
        </Card>
      </div>

      {queue.refused.length > 0 ? (
        <Notice tone="red">
          The {place()} refused {queue.refused.length} item(s): stock may have run out, or the shift was closed. Put a sale back in the cart to
          sell it again, try again after fixing the cause, or discard it.
        </Notice>
      ) : null}

      <Panel title="Waiting to send">
        {queue.all.length === 0 ? (
          <Empty icon={<CheckCircle2 size={28} className="text-emerald-600" />} title="Everything is sent" />
        ) : (
          <div className="divide-y divide-border">
            {queue.all.map((entry) => (
              <EntryRow key={entry.id} entry={entry} owner={owner} />
            ))}
          </div>
        )}
      </Panel>

      {sent.length > 0 ? (
        <Panel title="Sent recently">
          <table className="w-full text-sm">
            <tbody className="divide-y divide-border">
              {sent.map((s) => (
                <tr key={s.id}>
                  <td className="px-4 py-2">{s.kind === 'sale' ? `Sale ${s.number}` : 'Cash movement'}</td>
                  <td className="px-4 py-2 text-muted-foreground">Made {dateTime(s.queued_at)}</td>
                  <td className="px-4 py-2 text-muted-foreground">Sent {dateTime(s.sent_at)}</td>
                  <td className="px-4 py-2 text-right tabular">{money(s.total_minor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      ) : null}
    </div>
  );
}

function Card({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-3 border border-border bg-card p-4">
      {icon}
      <div>
        <div className="font-semibold">{title}</div>
        <div className="text-xs text-muted-foreground">{children}</div>
      </div>
    </div>
  );
}

function EntryRow({ entry, owner }: { entry: QueuedEntry; owner: string }) {
  const { remove, retry } = useOutbox.getState();
  const go = useUi((s) => s.go);

  const putBack = () => {
    if (entry.kind !== 'sale') return;
    useCart.getState().putBack(owner, entry.lines, entry.payload.customer_name ?? '', entry.payload.customer_phone ?? '');
    remove(entry.id);
    toast('Back in the cart. The cart you had is held.', 'blue');
    go('sell');
  };

  const discard = () => {
    if (window.confirm(`Discard it? The ${place()} will never record it.`)) remove(entry.id);
  };

  return (
    <div className="flex items-center gap-4 px-4 py-3">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2 font-medium">
          {entry.kind === 'sale'
            ? `Sale · ${entry.lines.length} line(s) · ${entry.payment_label}`
            : `Cash ${entry.direction === 'in' ? 'into' : 'out of'} the drawer · ${entry.reason}`}
          {entry.error ? <Badge tone="red">Refused</Badge> : <Badge tone="amber">Waiting</Badge>}
        </div>
        <div className="text-xs text-muted-foreground">
          {dateTime(entry.queued_at)}
          {entry.attempts ? ` · tried ${entry.attempts} time(s)` : ''}
        </div>
        {entry.error ? <div className="text-sm text-destructive">{entry.error}</div> : null}
      </div>
      <div className="text-right font-medium tabular">{money(entry.kind === 'sale' ? entry.total_minor : entry.amount_minor)}</div>
      {entry.error ? (
        <div className="flex gap-1">
          <Button size="sm" variant="secondary" onClick={() => retry(entry.id)}>
            Try again
          </Button>
          {entry.kind === 'sale' ? (
            <Button size="sm" variant="secondary" onClick={putBack}>
              Back to cart
            </Button>
          ) : null}
          <Button size="sm" variant="ghost" onClick={discard}>
            Discard
          </Button>
        </div>
      ) : null}
    </div>
  );
}
