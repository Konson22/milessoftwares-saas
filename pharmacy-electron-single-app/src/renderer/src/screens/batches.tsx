import { Layers, PackagePlus, Search } from 'lucide-react';
import { useState } from 'react';

import { Badge, Button, cn, Empty, Input, PageHeader, Panel } from '@/components/ui';
import { inSecond, money } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

interface BatchRow {
  id: string;
  medicineId: string;
  medicine: string;
  unit: string;
  batchNumber: string;
  expiresOn: string | null;
  expired: boolean;
  expiringSoon: boolean;
  quantity: number;
  status: 'available' | 'quarantined' | 'recalled';
  unitCostMinor: number;
  sellPriceMinor: number;
  ownPrice: boolean;
  marginPercent: number | null;
  boughtAs: string | null;
  receiptId: string | null;
  receiptNumber: string | null;
  supplier: string | null;
}

interface Props {
  batches: BatchRow[];
  filters: { search: string; filter: string };
  expiringDays: number;
  canReceive: boolean;
}

const FILTERS = [
  ['stock', 'In stock'],
  ['expiring', 'Expiring soon'],
  ['expired', 'Expired'],
  ['held', 'Held back'],
  ['all', 'All, with used up'],
] as const;

/** Every batch on the shelf: its expiry, what it cost and what it sells for. */
export function BatchesScreen({ page }: ScreenProps<Props>) {
  const p = page.props;
  const open = useUi((s) => s.open);
  const [search, setSearch] = useState(p.filters.search);
  const go = (next: Partial<Props['filters']>) => open(withQuery('batches', { ...p.filters, search, ...next }));

  return (
    <div className="space-y-4">
      <PageHeader
        title="Batches"
        description={`Soonest to expire first, as they sell · expiring soon is within ${p.expiringDays} days`}
        actions={
          p.canReceive ? (
            <Button onClick={() => open('receipts/create')}>
              <PackagePlus size={16} /> Receive stock
            </Button>
          ) : null
        }
      />
      <div className="flex items-center gap-2">
        <div className="flex border border-input bg-card">
          {FILTERS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => go({ filter: value })}
              className={cn('px-3 py-1.5 text-sm', p.filters.filter === value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
            >
              {label}
            </button>
          ))}
        </div>
        <form
          className="relative flex-1"
          onSubmit={(e) => {
            e.preventDefault();
            go({ search });
          }}
        >
          <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Medicine or batch number, then Enter" className="pl-9" />
        </form>
      </div>
      <Panel>
        {p.batches.length === 0 ? (
          <Empty icon={<Layers size={28} />} title="No batches here" />
        ) : (
          <table className="w-full text-sm [&_td:not(:first-child)]:whitespace-nowrap">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Medicine</th>
                <th className="px-4 py-2 font-medium">Batch</th>
                <th className="px-4 py-2 font-medium">Expires</th>
                <th className="px-4 py-2 text-right font-medium">On hand</th>
                <th className="px-4 py-2 text-right font-medium">Unit cost</th>
                <th className="px-4 py-2 text-right font-medium">Sells at</th>
                <th className="px-4 py-2 text-right font-medium">Margin</th>
                <th className="px-4 py-2 font-medium">From</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.batches.map((b) => (
                <tr key={b.id} onClick={() => open(`stock/${b.medicineId}`)} className={cn('cursor-pointer hover:bg-accent', b.quantity === 0 && 'opacity-50')}>
                  <td className="px-4 py-2.5 font-medium">{b.medicine}</td>
                  <td className="px-4 py-2.5">
                    <span className="flex items-center gap-1.5">
                      {b.batchNumber}
                      {b.status !== 'available' ? <Badge tone="amber">{b.status === 'recalled' ? 'Recalled' : 'Quarantined'}</Badge> : null}
                    </span>
                  </td>
                  <td className={cn('px-4 py-2.5', b.expired && 'font-medium text-red-700', b.expiringSoon && 'font-medium text-amber-700')}>
                    {b.expiresOn ?? '—'} {b.expired ? <Badge tone="red">Expired</Badge> : null}
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">
                    {b.quantity} {b.unit}
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">
                    {money(b.unitCostMinor)}
                    {b.boughtAs ? <div className="text-xs text-muted-foreground">{b.boughtAs}</div> : null}
                  </td>
                  <td className="px-4 py-2.5 text-right tabular">
                    <span className="font-medium">{money(b.sellPriceMinor)}</span>
                    <div className="text-xs text-muted-foreground">{inSecond(b.sellPriceMinor) ?? (b.ownPrice ? 'this batch' : 'the medicine’s')}</div>
                  </td>
                  <td className={cn('px-4 py-2.5 text-right tabular', b.marginPercent !== null && b.marginPercent < 0 && 'font-medium text-destructive')}>
                    {b.marginPercent === null ? '—' : `${b.marginPercent}%`}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-muted-foreground">{[b.receiptNumber, b.supplier].filter(Boolean).join(' · ') || 'Added by hand'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}
