import { useState } from 'react';
import { View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useAction } from '@/hooks/use-page';
import { plural } from '@/lib/format';

import { Chips, ListRow, Panel, Scroll, Toggle } from '../kit';
import type { ScreenProps } from '../types';

interface Table {
  id: string;
  name: string;
  area: string | null;
  seats: number;
  active: boolean;
  inUse: boolean;
}

interface Props {
  tables: Table[];
  areas: string[];
}

/** The branch's tables, by area: added, renamed, moved between areas, taken out of use. */
export function TablesScreen({ page, path, reload }: ScreenProps<Props>) {
  const { tables, areas } = page.props;
  const { run, busy, errors, setErrors } = useAction(path, reload);
  const [editing, setEditing] = useState<Table | 'new' | null>(null);
  const [name, setName] = useState('');
  const [area, setArea] = useState('');
  const [seats, setSeats] = useState('');
  const [active, setActive] = useState(true);
  const byArea = [...new Set(tables.map((t) => t.area ?? ''))];

  const open = (table: Table | 'new') => {
    setErrors({});
    setEditing(table);
    setName(table === 'new' ? '' : table.name);
    setArea(table === 'new' ? (areas[0] ?? '') : (table.area ?? ''));
    setSeats(table === 'new' ? '4' : String(table.seats));
    setActive(table === 'new' ? true : table.active);
  };

  const save = async () => {
    const data = { name: name.trim(), area: area.trim() || null, seats: seats.trim() || null, active };
    const ok = editing === 'new' ? await run('POST', 'tables', data) : editing ? await run('PUT', `tables/${editing.id}`, data) : false;
    if (ok) setEditing(null);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <Button title="Add a table" icon="add" size="lg" onPress={() => open('new')} />
        {tables.length === 0 ? (
          <EmptyState icon="apps-outline" title="No tables yet" message="Add the tables guests sit at; takeaways need none." />
        ) : (
          byArea.map((group) => (
            <Panel key={group} title={`${group || 'No area'} · ${plural(tables.filter((t) => (t.area ?? '') === group).length, 'table')}`}>
              {tables
                .filter((t) => (t.area ?? '') === group)
                .map((table) => (
                  <ListRow
                    key={table.id}
                    icon="apps-outline"
                    title={table.name}
                    subtitle={`${plural(table.seats, 'seat')}`}
                    muted={!table.active}
                    right={table.inUse ? <Badge label="being served" tone="blue" /> : !table.active ? <Badge label="not in use" tone="neutral" /> : null}
                    onPress={() => open(table)}
                  />
                ))}
            </Panel>
          ))
        )}
      </Scroll>

      <Sheet visible={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'New table' : `Table ${editing?.name ?? ''}`}>
        <Field label="Name" value={name} onChangeText={setName} maxLength={32} placeholder="T1, Window 2…" error={errors.name} autoFocus={editing === 'new'} />
        <Field label="Area" value={area} onChangeText={setArea} maxLength={32} placeholder="Inside, Terrace…" hint="Optional." error={errors.area} />
        {areas.length > 0 ? (
          <View>
            <Chips scroll options={areas.map((a) => ({ value: a, label: a }))} value={area} onChange={setArea} />
          </View>
        ) : null}
        <Field label="Seats" value={seats} onChangeText={setSeats} keyboardType="number-pad" error={errors.seats} />
        <Toggle label="In use" hint="Off: it is no longer on the floor." value={active} onChange={setActive} />
        {errors.active ? <Badge label={errors.active} tone="red" /> : null}
        <Button title="Save" icon="checkmark" size="lg" loading={busy} onPress={save} />
      </Sheet>
    </>
  );
}
