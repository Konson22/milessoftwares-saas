import { Plus, Search } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

import { Badge, Button, cn, Empty, Field, Input, Modal, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { dateTime, money } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

interface Row {
  id: string;
  label: string;
  unit: string;
  sellable: number;
  expired: number;
  held: number;
  nextExpiry: string | null;
  low: boolean;
  expiringSoon: boolean;
  reorderLevel: number;
}

const FILTERS = [
  ['all', 'All'],
  ['low', 'Low'],
  ['expiring', 'Expiring soon'],
  ['expired', 'Expired'],
  ['held', 'Held back'],
] as const;

/** The branch's stock (web: stock/index). */
export function StockScreen({ page }: ScreenProps<{ rows: Row[]; filters: { search: string; filter: string }; expiringDays: number }>) {
  const { rows, filters, expiringDays } = page.props;
  const open = useUi((s) => s.open);
  const [search, setSearch] = useState(filters.search);
  const filter = (next: Partial<typeof filters>) => open(withQuery('stock', { ...filters, search, ...next, filter: next.filter ?? filters.filter }));

  return (
    <div className="space-y-4">
      <PageHeader title="Stock" description={`${page.props.branch?.name ?? ''} · expiring soon is within ${expiringDays} days`} />
      <div className="flex items-center gap-2">
        <div className="flex border border-input bg-card">
          {FILTERS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => filter({ filter: value })}
              className={cn('px-3 py-1.5 text-sm', filters.filter === value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
            >
              {label}
            </button>
          ))}
        </div>
        <form
          className="relative flex-1"
          onSubmit={(e) => {
            e.preventDefault();
            filter({ search });
          }}
        >
          <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search, then Enter" className="pl-9" />
        </form>
      </div>
      <Panel>
        {rows.length === 0 ? (
          <Empty title="Nothing here" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Medicine</th>
                <th className="px-4 py-2 text-right font-medium">Sellable</th>
                <th className="px-4 py-2 text-right font-medium">Held back</th>
                <th className="px-4 py-2 text-right font-medium">Expired</th>
                <th className="px-4 py-2 font-medium">Next expiry</th>
                <th className="px-4 py-2 text-right font-medium">Reorder at</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rows.map((r) => (
                <tr key={r.id} onClick={() => open(`stock/${r.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2.5 font-medium">
                    {r.label} {r.low ? <Badge tone="amber">Low</Badge> : null}
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">
                    {r.sellable} {r.unit}
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">{r.held || '—'}</td>
                  <td className={cn('px-4 py-2.5 text-right tabular', r.expired && 'font-medium text-red-700')}>{r.expired || '—'}</td>
                  <td className={cn('px-4 py-2.5', r.expiringSoon && 'font-medium text-amber-700')}>{r.nextExpiry ?? '—'}</td>
                  <td className="px-4 py-2.5 text-right tabular">{r.reorderLevel}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}

interface BatchRow {
  id: string;
  batchNumber: string;
  expiresOn: string | null;
  expired: boolean;
  quantity: number;
  unitCostMinor: number;
  status: 'available' | 'quarantined' | 'recalled';
  holdReason: string | null;
  locations: { id: string; name: string; quantity: number }[];
  notPutAway: number;
}

interface ShowProps {
  item: { id: string; label: string; unit: string; controlled: boolean; reorderLevel: number; tracksExpiry: boolean };
  batches: BatchRow[];
  movements: { id: string; at: string; type: string; quantity: number; batchNumber: string; reason: string | null; note: string | null; by: string }[];
  pendingDisposals: { id: string; number: string; batchNumber: string; quantity: number; reason: string; requestedBy: string }[];
  locations: { id: string; name: string }[];
  reasons: { value: string; label: string }[];
  disposalReasons: { value: string; label: string }[];
  canAdjust: boolean;
  canHold: boolean;
}

type Dialog = { kind: 'opening' } | { kind: 'adjust' | 'dispose' | 'hold' | 'move'; batch: BatchRow };

/** One medicine's stock here, batch by batch (web: stock/show). */
export function StockItemScreen({ page, path, reload }: ScreenProps<ShowProps>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const close = () => setDialog(null);

  return (
    <div className="space-y-4">
      <PageHeader
        title={p.item.label}
        description={`Reorder at ${p.item.reorderLevel} ${p.item.unit}${p.item.controlled ? ' · controlled' : ''}`}
        actions={
          p.canAdjust ? (
            <Button onClick={() => setDialog({ kind: 'opening' })}>
              <Plus size={16} /> Add stock
            </Button>
          ) : null
        }
      />

      {p.pendingDisposals.length > 0 ? (
        <Notice>
          Waiting for approval to dispose:{' '}
          {p.pendingDisposals.map((d) => `${d.number} (${d.quantity} of ${d.batchNumber}, ${d.reason.toLowerCase()}, by ${d.requestedBy})`).join('; ')}
        </Notice>
      ) : null}

      <Panel title="Batches, soonest to expire first">
        {p.batches.length === 0 ? (
          <Empty title="No stock here" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Batch</th>
                <th className="px-4 py-2 font-medium">Expires</th>
                <th className="px-4 py-2 text-right font-medium">On hand</th>
                <th className="px-4 py-2 text-right font-medium">Unit cost</th>
                <th className="px-4 py-2 font-medium">Where</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.batches.map((b) => (
                <tr key={b.id} className={cn(b.quantity === 0 && 'opacity-50')}>
                  <td className="px-4 py-2.5 font-medium">
                    <span className="flex items-center gap-2">
                      {b.batchNumber}
                      {b.expired ? <Badge tone="red">Expired</Badge> : null}
                      {b.status !== 'available' ? (
                        <Badge tone="amber">
                          {b.status === 'recalled' ? 'Recalled' : 'Quarantined'}
                          {b.holdReason ? `: ${b.holdReason}` : ''}
                        </Badge>
                      ) : null}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">{b.expiresOn ?? '—'}</td>
                  <td className="px-4 py-2.5 text-right tabular">{b.quantity}</td>
                  <td className="px-4 py-2.5 text-right tabular">{money(b.unitCostMinor)}</td>
                  <td className="px-4 py-2.5 text-xs">
                    {[...b.locations.map((l) => `${l.name}: ${l.quantity}`), ...(b.notPutAway ? [`not put away: ${b.notPutAway}`] : [])].join(' · ') || '—'}
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex justify-end gap-1">
                      {p.canAdjust && b.quantity > 0 ? (
                        <>
                          <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'adjust', batch: b })}>
                            Adjust
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'dispose', batch: b })}>
                            Dispose
                          </Button>
                          {p.locations.length ? (
                            <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'move', batch: b })}>
                              Move
                            </Button>
                          ) : null}
                        </>
                      ) : null}
                      {p.canHold && b.quantity > 0 ? (
                        b.status === 'available' ? (
                          <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'hold', batch: b })}>
                            Hold back
                          </Button>
                        ) : (
                          <Button size="sm" variant="ghost" disabled={action.busy} onClick={() => void action.run('POST', `batches/${b.id}/release`)}>
                            Release
                          </Button>
                        )
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>

      <Panel title="Latest movements">
        {p.movements.length === 0 ? (
          <Empty title="None yet" />
        ) : (
          <table className="w-full text-sm">
            <tbody className="divide-y divide-border">
              {p.movements.map((m) => (
                <tr key={m.id}>
                  <td className="px-4 py-2 text-xs text-muted-foreground">{dateTime(m.at)}</td>
                  <td className="px-4 py-2 capitalize">{m.type.replace(/_/g, ' ')}</td>
                  <td className={cn('px-4 py-2 text-right font-medium tabular', m.quantity < 0 ? 'text-red-700' : 'text-emerald-700')}>
                    {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                  </td>
                  <td className="px-4 py-2">{m.batchNumber}</td>
                  <td className="px-4 py-2 text-muted-foreground">{[m.reason, m.note].filter(Boolean).join(': ')}</td>
                  <td className="px-4 py-2 text-muted-foreground">{m.by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>

      {dialog?.kind === 'opening' ? (
        <FormDialog title="Add stock" submit="Add" action={action} onClose={close} to={`stock/${p.item.id}/opening`} initial={{ batch_number: '', expires_on: '', quantity: '', unit_cost: '', location_id: '' }}>
          {(data, set, errors) => (
            <>
              <Notice tone="blue">Stock already on the shelf, or found in a count. Deliveries are recorded under Goods received.</Notice>
              <Field label="Batch number" error={errors.batch_number}>
                <Input value={data.batch_number} onChange={(e) => set('batch_number', e.target.value)} required autoFocus />
              </Field>
              <Field label="Expires on" error={errors.expires_on}>
                <Input type="date" value={data.expires_on} onChange={(e) => set('expires_on', e.target.value)} required={p.item.tracksExpiry} />
              </Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label={`Quantity (${p.item.unit})`} error={errors.quantity}>
                  <Input type="number" min={1} value={data.quantity} onChange={(e) => set('quantity', e.target.value)} required />
                </Field>
                <Field label="Unit cost" error={errors.unit_cost}>
                  <Input value={data.unit_cost} onChange={(e) => set('unit_cost', e.target.value)} inputMode="decimal" required />
                </Field>
              </div>
              {p.locations.length ? (
                <Field label="Put away in" error={errors.location_id}>
                  <Select value={data.location_id} onChange={(e) => set('location_id', e.target.value)}>
                    <option value="">Not put away yet</option>
                    {p.locations.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.name}
                      </option>
                    ))}
                  </Select>
                </Field>
              ) : null}
            </>
          )}
        </FormDialog>
      ) : null}

      {dialog?.kind === 'adjust' ? (
        <FormDialog title={`Adjust batch ${dialog.batch.batchNumber}`} submit="Adjust" action={action} onClose={close} to={`batches/${dialog.batch.id}/adjustments`} initial={{ change: '', reason: p.reasons[0]?.value ?? '', note: '' }}>
          {(data, set, errors) => (
            <>
              <Field label="Change" error={errors.change} hint={`On hand now ${dialog.batch.quantity}. −3 takes three off, 5 adds five. Damaged or expired stock is disposed of instead.`}>
                <Input type="number" value={data.change} onChange={(e) => set('change', e.target.value)} required autoFocus />
              </Field>
              <Field label="Why" error={errors.reason}>
                <Select value={data.reason} onChange={(e) => set('reason', e.target.value)}>
                  {p.reasons.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Note" error={errors.note}>
                <Input value={data.note} onChange={(e) => set('note', e.target.value)} />
              </Field>
            </>
          )}
        </FormDialog>
      ) : null}

      {dialog?.kind === 'dispose' ? (
        <FormDialog
          title={`Dispose of batch ${dialog.batch.batchNumber}`}
          submit="Ask for approval"
          action={action}
          onClose={close}
          to={`batches/${dialog.batch.id}/disposals`}
          initial={{ quantity: String(dialog.batch.quantity), reason: p.disposalReasons[0]?.value ?? '', note: '' }}
        >
          {(data, set, errors) => (
            <>
              <Notice tone="blue">Someone else approves it under Disposals; the stock leaves then.</Notice>
              <Field label="Quantity" error={errors.quantity}>
                <Input type="number" min={1} max={dialog.batch.quantity} value={data.quantity} onChange={(e) => set('quantity', e.target.value)} required />
              </Field>
              <Field label="Why" error={errors.reason}>
                <Select value={data.reason} onChange={(e) => set('reason', e.target.value)}>
                  {p.disposalReasons.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Note" error={errors.note}>
                <Input value={data.note} onChange={(e) => set('note', e.target.value)} />
              </Field>
            </>
          )}
        </FormDialog>
      ) : null}

      {dialog?.kind === 'hold' ? (
        <FormDialog title={`Hold back batch ${dialog.batch.batchNumber}`} submit="Hold back" action={action} onClose={close} to={`batches/${dialog.batch.id}/hold`} initial={{ status: 'quarantined', reason: '' }}>
          {(data, set, errors) => (
            <>
              <Field label="As" error={errors.status}>
                <Select value={data.status} onChange={(e) => set('status', e.target.value)}>
                  <option value="quarantined">Quarantined (checking it)</option>
                  <option value="recalled">Recalled (by the maker or the regulator)</option>
                </Select>
              </Field>
              <Field label="Why" error={errors.reason}>
                <Input value={data.reason} onChange={(e) => set('reason', e.target.value)} required autoFocus />
              </Field>
            </>
          )}
        </FormDialog>
      ) : null}

      {dialog?.kind === 'move' ? (
        <FormDialog
          title={`Move batch ${dialog.batch.batchNumber}`}
          submit="Move"
          action={action}
          onClose={close}
          to={`batches/${dialog.batch.id}/move`}
          initial={{ from_location_id: '', to_location_id: p.locations[0]?.id ?? '', quantity: '' }}
          clean={(d) => ({ ...d, from_location_id: d.from_location_id || null, to_location_id: d.to_location_id || null })}
        >
          {(data, set, errors) => (
            <>
              <Field label="From" error={errors.from_location_id}>
                <Select value={data.from_location_id} onChange={(e) => set('from_location_id', e.target.value)}>
                  <option value="">Not put away ({dialog.batch.notPutAway})</option>
                  {dialog.batch.locations.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name} ({l.quantity})
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="To" error={errors.to_location_id}>
                <Select value={data.to_location_id} onChange={(e) => set('to_location_id', e.target.value)}>
                  <option value="">Not put away</option>
                  {p.locations.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Quantity" error={errors.quantity}>
                <Input type="number" min={1} value={data.quantity} onChange={(e) => set('quantity', e.target.value)} required />
              </Field>
            </>
          )}
        </FormDialog>
      ) : null}
    </div>
  );
}

type Values = Record<string, string>;

/** A small form in a dialog, posted to `to`; closes when the pharmacy takes it. */
export function FormDialog<V extends Values>({
  title,
  submit,
  to,
  initial,
  action,
  onClose,
  clean,
  children,
}: {
  title: string;
  submit: string;
  to: string;
  initial: V;
  action: ReturnType<typeof useAction>;
  onClose: () => void;
  clean?: (data: V) => unknown;
  children: (data: V, set: (key: keyof V, value: string) => void, errors: Record<string, string>) => ReactNode;
}) {
  const [data, setData] = useState<V>(initial);
  const set = (key: keyof V, value: string) => setData((d) => ({ ...d, [key]: value }));

  const send = async (e: FormEvent) => {
    e.preventDefault();
    if (await action.run('POST', to, clean ? clean(data) : data)) onClose();
  };

  return (
    <Modal
      title={title}
      onClose={onClose}
      footer={
        <Button type="submit" form="form-dialog" disabled={action.busy}>
          {submit}
        </Button>
      }
    >
      <form id="form-dialog" onSubmit={send} className="space-y-3">
        {children(data, set, action.errors)}
      </form>
    </Modal>
  );
}
