import { useState } from 'react';
import { Text } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useAccess, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api/client';
import { allowed, navItem } from '@/lib/navigation';
import { getPage } from '@/lib/pages';

import { Chips } from './kit';

interface FreeTable {
  id: string;
  name: string;
  seats: number;
}

const TAKEAWAY = 'takeaway';

/**
 * "New order", for whoever takes orders: a takeaway or a free table (the
 * floor's, read when the sheet opens), then the order itself opens.
 */
export function NewOrderButton({ from, reload }: { from: string; reload: () => Promise<void> | void }) {
  const shared = useAccess((s) => s.shared);
  const { run, busy, errors } = useAction(from, reload);
  const [open, setOpen] = useState(false);
  const [tables, setTables] = useState<FreeTable[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [choice, setChoice] = useState<string>(TAKEAWAY);
  const [guests, setGuests] = useState('');
  const [name, setName] = useState('');
  const orders = navItem('orders');

  if (!orders || !allowed(orders, shared)) return null;

  const start = async () => {
    setOpen(true);
    setChoice(TAKEAWAY);
    setGuests('');
    setName('');
    setTables(null);
    setLoadError(null);
    try {
      const floor = await getPage<{ tables: (FreeTable & { order: unknown })[] }>('floor');
      setTables(floor.props.tables.filter((table) => !table.order));
    } catch (e) {
      setLoadError(errorMessage(e));
      setTables([]);
    }
  };

  const table = tables?.find((t) => t.id === choice) ?? null;

  const submit = async () => {
    const data =
      choice === TAKEAWAY
        ? { service: 'takeaway', customer_name: name.trim() || null }
        : { service: 'dine_in', dining_table_id: choice, guests: guests.trim() || null };
    if (await run('POST', 'orders', data)) setOpen(false);
  };

  return (
    <>
      <Button title="New order" icon="add" variant="accent" size="lg" onPress={start} />

      <Sheet visible={open} onClose={() => setOpen(false)} title="New order">
        {loadError ? <Banner tone="warning">{`The tables could not be read (${loadError}). A takeaway still opens.`}</Banner> : null}
        <Text className="text-sm font-medium text-slate-700">For</Text>
        <Chips
          options={[{ value: TAKEAWAY, label: 'Takeaway' }, ...(tables ?? []).map((t) => ({ value: t.id, label: `Table ${t.name}` }))]}
          value={choice}
          onChange={setChoice}
        />
        {tables !== null && tables.length === 0 && !loadError ? <Text className="text-sm text-slate-500">No table is free just now.</Text> : null}
        {choice === TAKEAWAY ? (
          <Field
            label="Name for the takeaway"
            hint="Optional: who it is for, to call out when it is ready."
            error={errors.customer_name}
            value={name}
            onChangeText={setName}
            maxLength={255}
            returnKeyType="go"
            onSubmitEditing={submit}
          />
        ) : (
          <Field
            label="Guests"
            hint={table ? `The table seats ${table.seats}. Optional.` : undefined}
            error={errors.guests ?? errors.dining_table_id}
            value={guests}
            onChangeText={setGuests}
            keyboardType="number-pad"
            returnKeyType="go"
            onSubmitEditing={submit}
          />
        )}
        <Button title="Open order" icon="restaurant-outline" size="lg" loading={busy} onPress={submit} />
      </Sheet>
    </>
  );
}
