import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, currencyCode, dateTime, money } from '@/lib/format';
import { withQuery } from '@/lib/pages';

import { Chips, Detail, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface Row {
  id: string;
  name: string;
  unit: string;
  onHand: number;
  averageCostMinor: number;
  reorderLevel: number;
  low: boolean;
}

interface ListProps {
  rows: Row[];
  filters: { search: string; filter: 'all' | 'low' };
}

/** Drinks in stock at this branch (dishes keep none), those running low first to see. */
export function StockScreen({ page, reload, go }: ScreenProps<ListProps>) {
  const { rows, filters } = page.props;

  return (
    <Scroll onRefresh={reload}>
      <Chips
        options={[
          { value: 'all', label: 'Every drink' },
          { value: 'low', label: 'Running low' },
        ]}
        value={filters.filter}
        onChange={(filter) => go(withQuery('stock', { search: filters.search, filter: filter === 'low' ? 'low' : null }))}
      />
      <Panel>
        {rows.length === 0 ? (
          <EmptyState icon="beer-outline" title={filters.filter === 'low' ? 'Nothing running low' : 'No drinks on the menu'} />
        ) : (
          rows.map((row) => (
            <ListRow
              key={row.id}
              title={row.name}
              subtitle={`Reorder at ${row.reorderLevel} · ${money(row.averageCostMinor)} each`}
              right={
                <View className="items-end">
                  <Text className={`text-lg font-bold ${row.low ? 'text-amber-700' : 'text-slate-900'}`}>{row.onHand}</Text>
                  <Text className="text-xs text-slate-500">{row.unit}</Text>
                </View>
              }
              onPress={() => openPage(`stock/${row.id}`)}
            />
          ))
        )}
      </Panel>
    </Scroll>
  );
}

interface ShowProps {
  item: { id: string; name: string; unit: string; reorderLevel: number; onHand: number; averageCostMinor: number };
  movements: { id: string; at: string; type: string; quantity: number; reason: string | null; note: string | null; by: string }[];
  reasons: { value: string; label: string }[];
  canAdjust: boolean;
}

const TYPES: Record<string, string> = {
  opening: 'Added',
  count: 'Counted',
  write_off: 'Written off',
  sale: 'Sold',
  bill_void: 'Bill voided',
};

/** One drink: what is on hand, how it moved, and (managers) stock added or counted. */
export function StockItemScreen({ page, path, reload }: ScreenProps<ShowProps>) {
  const { item, movements, reasons, canAdjust } = page.props;
  const { run, busy, errors, setErrors } = useAction(path, reload);
  const [sheet, setSheet] = useState<'add' | 'count' | null>(null);
  const [quantity, setQuantity] = useState('');
  const [cost, setCost] = useState('');
  const [change, setChange] = useState('');
  const [reason, setReason] = useState('count');
  const [note, setNote] = useState('');

  const open = (which: 'add' | 'count') => {
    setErrors({});
    setQuantity('');
    setCost('');
    setChange('');
    setReason('count');
    setNote('');
    setSheet(which);
  };

  const submit = async () => {
    const ok =
      sheet === 'add'
        ? await run('POST', `stock/${item.id}/opening`, { quantity: quantity.trim() || null, unit_cost: amountInput(cost.trim()), note: note.trim() || null })
        : await run('POST', `stock/${item.id}/adjustments`, { change: change.trim() || null, reason, note: note.trim() || null });
    if (ok) setSheet(null);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <Text className="text-2xl font-bold text-slate-900">{item.name}</Text>
        <Panel>
          <View className="px-4 py-2">
            <Detail label="On hand" value={`${item.onHand} ${item.unit}`} strong />
            <Detail label="Average cost" value={money(item.averageCostMinor)} />
            <Detail label="Reorder at" value={String(item.reorderLevel)} />
          </View>
        </Panel>
        {canAdjust ? (
          <View className="flex-row gap-2">
            <Button title="Add stock" icon="add" className="flex-1" size="lg" onPress={() => open('add')} />
            <Button title="Count or write off" icon="create-outline" variant="outline" className="flex-1" size="lg" onPress={() => open('count')} />
          </View>
        ) : null}
        <Panel title="Movements">
          {movements.length === 0 ? (
            <Text className="px-4 py-6 text-center text-base text-slate-500">None yet.</Text>
          ) : (
            movements.map((m) => (
              <ListRow
                key={m.id}
                title={`${TYPES[m.type] ?? m.type}${m.reason && m.reason !== TYPES[m.type] ? ` · ${m.reason}` : ''}`}
                subtitle={`${dateTime(m.at)} · ${m.by}${m.note ? ` · ${m.note}` : ''}`}
                right={<Badge label={m.quantity > 0 ? `+${m.quantity}` : String(m.quantity)} tone={m.quantity > 0 ? 'green' : 'amber'} />}
              />
            ))
          )}
        </Panel>
      </Scroll>

      <Sheet visible={sheet !== null} onClose={() => setSheet(null)} title={sheet === 'add' ? `Add ${item.name}` : `Count ${item.name}`}>
        {sheet === 'add' ? (
          <>
            <Field label={`How many (${item.unit})`} value={quantity} onChangeText={setQuantity} keyboardType="number-pad" error={errors.quantity} autoFocus />
            <Field label="Cost of one" prefix={currencyCode()} value={cost} onChangeText={setCost} keyboardType="decimal-pad" error={errors.unit_cost} />
          </>
        ) : (
          <>
            <Text className="text-sm font-medium text-slate-700">Why</Text>
            <Chips options={reasons} value={reason} onChange={setReason} />
            {errors.reason ? <Text className="text-sm text-red-600">{errors.reason}</Text> : null}
            <Field
              label="More or fewer"
              value={change}
              onChangeText={setChange}
              keyboardType="numbers-and-punctuation"
              placeholder="-3"
              hint={`${item.onHand} on hand now. Fewer is a minus: -3.`}
              error={errors.change}
            />
          </>
        )}
        <Field label="Note" value={note} onChangeText={setNote} maxLength={255} hint="Optional." error={errors.note} />
        <Button title="Save" icon="checkmark" size="lg" loading={busy} onPress={submit} />
      </Sheet>
    </>
  );
}
