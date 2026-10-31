import { router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { Badge, Card, EmptyState } from '@/components/ui/misc';
import { fullDate, money } from '@/lib/format';

import { Panel, PanelRow } from '../forms';
import { FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';
import { invoiceBadge } from './customers';

interface Invoice {
  id: string;
  number: string;
  customer: string;
  sold_at: string;
  due_on: string | null;
  net_minor: number;
  owing_minor: number;
  status: 'paid' | 'part_paid' | 'unpaid';
  overdue: boolean;
}

interface Props {
  invoices: Invoice[];
  filters: { filter: string; search: string };
  owedMinor: number;
  overdueMinor: number;
  overdueCount: number;
}

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'owing', label: 'Owing' },
  { value: 'overdue', label: 'Overdue' },
];

/** Sales on account, from the counter or from quotations, and what each still owes. */
export function InvoicesScreen({ page, loading, reload, setQuery }: ScreenProps<Props>) {
  const p = page.props;
  const [search, setSearch] = useState(p.filters.search);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <View className="flex-row flex-wrap gap-3">
        <Card className="min-w-[45%] flex-1 gap-0.5">
          <Text className="text-xs text-slate-500">Owed</Text>
          <Text className="text-lg font-bold text-slate-900">{money(p.owedMinor)}</Text>
        </Card>
        <Card className="min-w-[45%] flex-1 gap-0.5">
          <Text className="text-xs text-slate-500">Overdue ({p.overdueCount})</Text>
          <Text className={`text-lg font-bold ${p.overdueMinor ? 'text-red-700' : 'text-slate-900'}`}>{money(p.overdueMinor)}</Text>
        </Card>
      </View>
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ search: q, filter: p.filters.filter || null })} placeholder="Number or customer" />
      <FilterChips options={FILTERS} value={p.filters.filter} onChange={(filter) => setQuery({ search, filter: filter || null })} />
      {p.invoices.length === 0 ? (
        <EmptyState icon="reader-outline" title="No invoices" message="Sell on account at the counter, or invoice a quotation." />
      ) : (
        <Panel>
          {p.invoices.map((inv) => {
            const badge = invoiceBadge(inv);
            return (
              <PanelRow
                key={inv.id}
                title={`${inv.number} · ${inv.customer}`}
                subtitle={[
                  `${fullDate(inv.sold_at.slice(0, 10))}${inv.due_on ? ` · due ${fullDate(inv.due_on)}` : ''}`,
                  inv.status === 'part_paid' ? `${money(inv.owing_minor)} still owed` : null,
                ]
                  .filter(Boolean)
                  .join('\n')}
                right={money(inv.net_minor)}
                onPress={() => router.push({ pathname: '/receipt/[id]', params: { id: inv.id } })}>
                <View className="flex-row">
                  <Badge label={badge.label} tone={badge.tone} />
                </View>
              </PanelRow>
            );
          })}
        </Panel>
      )}
    </PageScroll>
  );
}
