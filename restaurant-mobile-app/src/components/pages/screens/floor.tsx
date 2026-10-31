import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { openPage, useAction } from '@/hooks/use-page';
import { money, since } from '@/lib/format';
import { colors } from '@/lib/theme';

import { Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

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

/**
 * The branch's tables, free or being served, and the takeaways still open:
 * a free table opens an order, a busy one goes to its order. Online it is
 * loaded again every few seconds, as other waiters seat tables too.
 */
export function FloorScreen({ page, path, reload }: ScreenProps<Props>) {
  const { tables, takeaways } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [seating, setSeating] = useState<Props['tables'][number] | null>(null);
  const [guests, setGuests] = useState('');
  const [takeaway, setTakeaway] = useState<string | null>(null);
  const areas = [...new Set(tables.map((table) => table.area ?? ''))];
  const busyCount = tables.filter((table) => table.order).length;
  const readyCount = tables.filter((table) => table.order?.hasReady).length + takeaways.filter((order) => order.hasReady).length;

  const seat = async () => {
    if (!seating) return;
    const ok = await run('POST', 'orders', { service: 'dine_in', dining_table_id: seating.id, guests: guests.trim() || null });
    if (ok) setSeating(null);
  };

  const startTakeaway = async () => {
    if (takeaway === null) return;
    if (await run('POST', 'orders', { service: 'takeaway', customer_name: takeaway.trim() || null })) setTakeaway(null);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <Button title="New takeaway" icon="bag-handle-outline" variant="accent" size="lg" onPress={() => setTakeaway('')} />

        {tables.length > 0 ? (
          <View className="flex-row flex-wrap items-center gap-x-4 gap-y-1">
            <Legend swatch="border-slate-300 bg-white" label={`${tables.length - busyCount} free`} />
            <Legend swatch="border-brand-400 bg-brand-50" label={`${busyCount} being served`} />
            {readyCount > 0 ? <Legend swatch="border-emerald-500 bg-emerald-100" label={`${readyCount} ready to take out`} /> : null}
          </View>
        ) : null}

        {tables.length === 0 ? (
          <Panel>
            <EmptyState icon="apps-outline" title="No tables yet" message="A manager adds them under Tables, in the menu." />
          </Panel>
        ) : (
          areas.map((area) => (
            <View key={area} className="gap-2">
              {areas.length > 1 || area ? <Text className="text-xs font-bold uppercase tracking-wider text-slate-500">{area || 'Other'}</Text> : null}
              <View className="flex-row flex-wrap gap-2">
                {tables
                  .filter((table) => (table.area ?? '') === area)
                  .map((table) => (
                    <Pressable
                      key={table.id}
                      accessibilityRole="button"
                      accessibilityLabel={table.order ? `Table ${table.name}, ${money(table.order.totalMinor)}` : `Table ${table.name}, free`}
                      disabled={busy}
                      onPress={() => {
                        if (table.order) return openPage(`orders/${table.order.id}`);
                        setGuests('');
                        setSeating(table);
                      }}
                      className={`min-h-28 w-[31.5%] min-w-28 flex-grow justify-between border border-l-4 p-3 ${
                        table.order?.hasReady
                          ? 'border-emerald-300 border-l-emerald-500 bg-emerald-50 active:bg-emerald-100'
                          : table.order
                            ? 'border-brand-200 border-l-brand-500 bg-brand-50 active:bg-brand-100'
                            : 'border-slate-200 border-l-slate-200 bg-white active:bg-slate-50'
                      }`}>
                      <View className="flex-row items-start justify-between gap-1">
                        <Text className="text-xl font-bold text-slate-900">{table.name}</Text>
                        {table.order?.hasReady ? <Badge label="Ready" tone="green" /> : null}
                      </View>
                      {table.order ? (
                        <View>
                          <Text className="text-base font-semibold text-brand-900">{money(table.order.totalMinor)}</Text>
                          <Text className="text-xs text-slate-500" numberOfLines={2}>
                            {table.order.guests ? `${table.order.guests} guests · ` : ''}
                            {since(table.order.openedAt)} · {table.order.openedBy}
                          </Text>
                        </View>
                      ) : (
                        <View className="flex-row items-center gap-1">
                          <Ionicons name="people-outline" size={13} color={colors.muted} />
                          <Text className="text-xs text-slate-500">{table.seats} seats · free</Text>
                        </View>
                      )}
                    </Pressable>
                  ))}
              </View>
            </View>
          ))
        )}

        {takeaways.length > 0 ? (
          <Panel title={`Takeaways (${takeaways.length})`}>
            {takeaways.map((order) => (
              <Pressable
                key={order.id}
                accessibilityRole="button"
                onPress={() => openPage(`orders/${order.id}`)}
                className="min-h-14 flex-row items-center gap-3 border-b border-slate-100 px-4 py-2.5 active:bg-brand-50">
                <Ionicons name="bag-handle-outline" size={20} color={colors.muted} />
                <View className="flex-1">
                  <Text className="text-base font-semibold text-slate-900">{order.customerName ?? order.number}</Text>
                  <Text className="text-sm text-slate-500">
                    {order.number} · {since(order.openedAt)} · {order.openedBy}
                  </Text>
                </View>
                {order.hasReady ? <Badge label="Ready" tone="green" /> : null}
                <Text className="text-base font-semibold text-slate-900">{money(order.totalMinor)}</Text>
              </Pressable>
            ))}
          </Panel>
        ) : null}
      </Scroll>

      <Sheet visible={seating !== null} onClose={() => setSeating(null)} title={`Seat table ${seating?.name ?? ''}`}>
        <Field
          label="Guests"
          hint={seating ? `The table seats ${seating.seats}. Optional.` : undefined}
          error={errors.guests ?? errors.dining_table_id}
          value={guests}
          onChangeText={setGuests}
          keyboardType="number-pad"
          autoFocus
          returnKeyType="go"
          onSubmitEditing={seat}
        />
        <Button title="Open order" icon="restaurant-outline" size="lg" loading={busy} onPress={seat} />
      </Sheet>

      <Sheet visible={takeaway !== null} onClose={() => setTakeaway(null)} title="New takeaway">
        <Field
          label="Name for the takeaway"
          hint="Optional: who it is for, to call out when it is ready."
          error={errors.customer_name}
          value={takeaway ?? ''}
          onChangeText={setTakeaway}
          autoFocus
          maxLength={255}
          returnKeyType="go"
          onSubmitEditing={startTakeaway}
        />
        <Button title="Open takeaway" icon="bag-handle-outline" size="lg" loading={busy} onPress={startTakeaway} />
      </Sheet>
    </>
  );
}

function Legend({ swatch, label }: { swatch: string; label: string }) {
  return (
    <View className="flex-row items-center gap-1.5">
      <View className={`h-3 w-3 border ${swatch}`} />
      <Text className="text-sm text-slate-600">{label}</Text>
    </View>
  );
}
