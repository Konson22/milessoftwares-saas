import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Badge, EmptyState } from '@/components/ui/misc';
import { openPage } from '@/hooks/use-page';
import { fullDate, inSecond, money } from '@/lib/format';

import { Panel, PanelRow } from '../forms';
import { FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

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
  { value: 'stock', label: 'In stock' },
  { value: 'expiring', label: 'Expiring soon' },
  { value: 'expired', label: 'Expired' },
  { value: 'held', label: 'Held back' },
  { value: 'all', label: 'All, with used up' },
];

/** Every batch on the shelf: its expiry, what it cost and what it sells for. */
export function BatchesScreen({ page, loading, reload, setQuery }: ScreenProps<Props>) {
  const p = page.props;
  const [search, setSearch] = useState(p.filters.search);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">Soonest to expire first, as they sell · expiring soon is within {p.expiringDays} days</Text>
      {p.canReceive && <Button title="Receive stock" icon="download-outline" square onPress={() => openPage('receipts/create')} />}
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ search: q })} placeholder="Medicine or batch number" />
      <FilterChips options={FILTERS} value={p.filters.filter || 'stock'} onChange={(filter) => setQuery({ filter, search })} />
      {p.batches.length === 0 ? (
        <EmptyState icon="layers-outline" title="No batches here" />
      ) : (
        <Panel>
          {p.batches.map((b) => (
            <PanelRow
              key={b.id}
              dim={b.quantity === 0}
              title={b.medicine}
              subtitle={[
                `Batch ${b.batchNumber} · ${b.expiresOn ? `expires ${fullDate(b.expiresOn)}` : 'no expiry'}`,
                `Cost ${money(b.unitCostMinor)}${b.boughtAs ? ` (${b.boughtAs})` : ''} · sells at ${money(b.sellPriceMinor)}${
                  inSecond(b.sellPriceMinor) ? ` ${inSecond(b.sellPriceMinor)}` : b.ownPrice ? ' (this batch)' : ''
                }`,
                [b.receiptNumber, b.supplier].filter(Boolean).join(' · ') || 'Added by hand',
              ].join('\n')}
              right={`${b.quantity} ${b.unit}`}
              rightSub={b.marginPercent === null ? null : `${b.marginPercent}% margin`}
              tone={b.expired ? 'red' : b.expiringSoon ? 'amber' : undefined}
              onPress={() => openPage(`stock/${b.medicineId}`)}>
              {(b.expired || b.status !== 'available') && (
                <View className="flex-row gap-1">
                  {b.expired && <Badge label="Expired" tone="red" />}
                  {b.status !== 'available' && <Badge label={b.status === 'recalled' ? 'Recalled' : 'Quarantined'} tone="amber" />}
                </View>
              )}
            </PanelRow>
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}
