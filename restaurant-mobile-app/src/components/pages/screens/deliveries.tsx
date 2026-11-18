import { useMemo, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button, IconButton } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, currencyCode, money, toMinor } from '@/lib/format';
import { colors } from '@/lib/theme';

import { Chips, Detail, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface ListProps {
  deliveries: {
    id: string;
    number: string;
    supplierName: string;
    supplierReference: string | null;
    receivedOn: string;
    receivedBy: string;
    drinks: number;
    totalMinor: number;
  }[];
  canReceive: boolean;
}

/** Drinks suppliers delivered to this branch, newest first. */
export function DeliveriesScreen({ page, reload }: ScreenProps<ListProps>) {
  const { deliveries, canReceive } = page.props;

  return (
    <Scroll onRefresh={reload}>
      {canReceive ? <Button title="Receive a delivery" icon="add" size="lg" onPress={() => openPage('stock/deliveries/create')} /> : null}
      <Panel>
        {deliveries.length === 0 ? (
          <EmptyState icon="cube-outline" title="No deliveries yet" message="When a supplier brings drinks, receive them here: stock goes up at what they cost." />
        ) : (
          deliveries.map((d) => (
            <ListRow
              key={d.id}
              title={d.supplierName}
              subtitle={`${d.number} · ${d.receivedOn} · ${d.drinks} ${d.drinks === 1 ? 'drink' : 'drinks'}`}
              right={<Text className="text-base font-bold text-slate-900">{money(d.totalMinor)}</Text>}
              onPress={() => openPage(`stock/deliveries/${d.id}`)}
            />
          ))
        )}
      </Panel>
    </Scroll>
  );
}

interface FormProps {
  drinks: { id: string; name: string; unit: string; onHand: number; averageCostMinor: number }[];
  suppliers: string[];
}

type Line = { key: number; itemId: string; quantity: string; cost: string };

/** Receiving a delivery: the supplier, then each drink with how many came and what one cost. */
export function DeliveryFormScreen({ page, path, reload }: ScreenProps<FormProps>) {
  const { drinks, suppliers } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [supplier, setSupplier] = useState('');
  const [reference, setReference] = useState('');
  const [note, setNote] = useState('');
  const [lines, setLines] = useState<Line[]>([]);
  const [picking, setPicking] = useState(false);
  const [nextKey, setNextKey] = useState(0);
  const byId = useMemo(() => new Map(drinks.map((d) => [d.id, d])), [drinks]);

  const total = lines.reduce((sum, l) => sum + (Number.parseInt(l.quantity, 10) || 0) * (toMinor(l.cost) ?? 0), 0);
  const typed = supplier.trim().toLowerCase();
  const matches = typed === '' ? suppliers.slice(0, 6) : suppliers.filter((s) => s.toLowerCase().includes(typed) && s.toLowerCase() !== typed).slice(0, 6);
  const lineError = Object.entries(errors).find(([key]) => key.startsWith('lines'))?.[1];

  const add = (itemId: string) => {
    const drink = byId.get(itemId);
    // What one has cost so far is the likely price; it can be changed.
    const cost = drink && drink.averageCostMinor > 0 ? money(drink.averageCostMinor, false).replace(/,/g, '') : '';
    setLines([...lines, { key: nextKey, itemId, quantity: '', cost }]);
    setNextKey(nextKey + 1);
    setPicking(false);
  };
  const change = (key: number, patch: Partial<Line>) => setLines(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));

  const submit = () =>
    run('POST', 'stock/deliveries', {
      supplier_name: supplier.trim(),
      supplier_reference: reference.trim() || null,
      note: note.trim() || null,
      lines: lines.map((l) => ({ item_id: l.itemId, quantity: l.quantity.trim() || null, unit_cost: amountInput(l.cost.trim()) })),
    });

  return (
    <>
      <Scroll>
        <Panel title="Supplier">
          <View className="gap-3 p-4">
            <Field label="Supplier" value={supplier} onChangeText={setSupplier} maxLength={255} error={errors.supplier_name} autoFocus />
            {matches.length > 0 ? <Chips scroll options={matches.map((s) => ({ value: s, label: s }))} value="" onChange={setSupplier} /> : null}
            <Field label="Their invoice or delivery note" value={reference} onChangeText={setReference} maxLength={128} hint="Optional." error={errors.supplier_reference} />
            <Field label="Note" value={note} onChangeText={setNote} maxLength={255} hint="Optional." />
          </View>
        </Panel>

        <Panel title="Drinks delivered" right={<Text className="text-base font-bold text-brand-900">{money(total)}</Text>}>
          {lines.length === 0 ? <Text className="px-4 py-6 text-center text-base text-slate-500">Add each drink that came in.</Text> : null}
          {lines.map((line) => {
            const drink = byId.get(line.itemId);
            return (
              <View key={line.key} className="gap-2 border-b border-slate-100 px-4 py-3">
                <View className="flex-row items-center gap-2">
                  <Text className="flex-1 text-base font-semibold text-slate-900">{drink?.name ?? ''}</Text>
                  <IconButton icon="trash-outline" label={`Take off ${drink?.name ?? 'this drink'}`} onPress={() => setLines(lines.filter((l) => l.key !== line.key))} />
                </View>
                <View className="flex-row gap-2">
                  <Field className="flex-1" label={`How many (${drink?.unit ?? ''})`} value={line.quantity} onChangeText={(quantity) => change(line.key, { quantity })} keyboardType="number-pad" />
                  <Field className="flex-1" label="Cost of one" prefix={currencyCode()} value={line.cost} onChangeText={(cost) => change(line.key, { cost })} keyboardType="decimal-pad" />
                </View>
              </View>
            );
          })}
          <View className="p-4">
            <Button title="Add a drink" icon="add-circle-outline" variant="secondary" disabled={lines.length >= drinks.length} onPress={() => setPicking(true)} />
          </View>
        </Panel>

        {lineError ? <Banner tone="error">{lineError}</Banner> : null}
        <Button title={`Receive ${money(total)}`} icon="checkmark-circle-outline" size="lg" loading={busy} disabled={lines.length === 0 || supplier.trim() === ''} onPress={() => void submit()} />
      </Scroll>

      <Sheet visible={picking} onClose={() => setPicking(false)} title="Which drink came in?">
        {drinks
          .filter((d) => !lines.some((l) => l.itemId === d.id))
          .map((d) => (
            <Pressable key={d.id} accessibilityRole="button" onPress={() => add(d.id)} className="flex-row items-center justify-between border-b border-slate-100 py-3 active:bg-brand-50">
              <Text className="text-base font-medium text-slate-900">{d.name}</Text>
              <Text className="text-sm text-slate-500">
                {d.onHand} {d.unit} on hand
              </Text>
            </Pressable>
          ))}
      </Sheet>
    </>
  );
}

interface ShowProps {
  delivery: {
    id: string;
    number: string;
    supplierName: string;
    supplierReference: string | null;
    receivedOn: string;
    receivedBy: string;
    note: string | null;
    totalMinor: number;
    lines: { id: string; itemId: string; name: string; unit: string; quantity: number; unitCostMinor: number; totalMinor: number }[];
  };
}

/** One delivery note: the drinks it brought and what is owed for them. */
export function DeliveryScreen({ page, reload }: ScreenProps<ShowProps>) {
  const { delivery } = page.props;

  return (
    <Scroll onRefresh={reload}>
      <Text className="text-2xl font-bold text-slate-900">{delivery.supplierName}</Text>
      <Panel>
        <View className="px-4 py-2">
          <Detail label="Delivery" value={delivery.number} />
          {delivery.supplierReference ? <Detail label="Their reference" value={delivery.supplierReference} /> : null}
          <Detail label="Received" value={`${delivery.receivedOn} · ${delivery.receivedBy}`} />
          {delivery.note ? <Detail label="Note" value={delivery.note} /> : null}
          <Detail label="Owed to the supplier" value={money(delivery.totalMinor)} strong />
        </View>
      </Panel>
      <Panel title="Drinks">
        {delivery.lines.map((line) => (
          <ListRow
            key={line.id}
            title={line.name}
            subtitle={`${line.quantity} ${line.unit} × ${money(line.unitCostMinor)}`}
            right={<Text className="text-base font-semibold text-slate-900">{money(line.totalMinor)}</Text>}
            onPress={() => openPage(`stock/${line.itemId}`)}
          />
        ))}
      </Panel>
      <Text className="text-center text-xs" style={{ color: colors.faint }}>
        Finance records what is owed to {delivery.supplierName}.
      </Text>
    </Scroll>
  );
}
