import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Text, TextInput, View } from 'react-native';

import { Button, IconButton } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, currencyCode, money } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { colors, webNoOutline } from '@/lib/theme';

import { Chips, ListRow, Panel, Scroll, Toggle } from '../kit';
import type { ScreenProps } from '../types';

interface Item {
  id: string;
  kind: 'dish' | 'drink';
  name: string;
  section: string | null;
  unit: string;
  barcode: string | null;
  priceMinor: number;
  reorderLevel: number;
  active: boolean;
}

interface ListProps {
  items: Item[];
  filters: { search: string; section: string; inactive: boolean };
  sections: string[];
  canManage: boolean;
}

/** The menu: dishes and drinks, by section, at one price for every branch. */
export function MenuScreen({ page, reload, go }: ScreenProps<ListProps>) {
  const { items, filters, sections, canManage } = page.props;
  const [search, setSearch] = useState(filters.search);
  const filter = (next: Partial<ListProps['filters']>) => go(withQuery('menu', { ...filters, search, ...next }));
  const bySection = [...new Set(items.map((item) => item.section ?? ''))];

  return (
    <Scroll onRefresh={reload}>
      {canManage ? <Button title="Add a dish or drink" icon="add" size="lg" onPress={() => openPage('menu/create')} /> : null}

      <View className="min-h-12 flex-row items-center border border-slate-300 bg-white px-3">
        <Ionicons name="search" size={18} color={colors.muted} />
        <TextInput
          value={search}
          onChangeText={setSearch}
          onSubmitEditing={() => filter({ search })}
          returnKeyType="search"
          placeholder="Name, section or barcode"
          placeholderTextColor={colors.faint}
          accessibilityLabel="Search the menu"
          className="ml-2 flex-1 py-3 text-base text-slate-900"
          style={webNoOutline}
        />
        {search ? (
          <IconButton
            icon="close"
            label="Clear"
            onPress={() => {
              setSearch('');
              filter({ search: '' });
            }}
          />
        ) : null}
      </View>
      {sections.length > 0 ? (
        <Chips scroll options={[{ value: '', label: 'All' }, ...sections.map((s) => ({ value: s, label: s }))]} value={filters.section} onChange={(section) => filter({ section })} />
      ) : null}
      <Toggle label="Show items taken off the menu" value={filters.inactive} onChange={(inactive) => filter({ inactive })} />

      {items.length === 0 ? (
        <EmptyState icon="restaurant-outline" title="Nothing on the menu here" message={canManage ? 'Add the dishes and drinks you serve.' : undefined} />
      ) : (
        bySection.map((section) => (
          <Panel key={section} title={section || 'Other'}>
            {items
              .filter((item) => (item.section ?? '') === section)
              .map((item) => (
                <ListRow
                  key={item.id}
                  icon={item.kind === 'drink' ? 'wine-outline' : 'restaurant-outline'}
                  title={item.name}
                  subtitle={`${item.kind === 'drink' ? 'Drink' : 'Dish'} · per ${item.unit}${item.barcode ? ` · ${item.barcode}` : ''}`}
                  muted={!item.active}
                  right={
                    <>
                      {!item.active ? <Badge label="off" tone="neutral" /> : null}
                      <Text className="text-base font-semibold text-slate-900">{money(item.priceMinor)}</Text>
                    </>
                  }
                  onPress={canManage ? () => openPage(`menu/${item.id}/edit`) : undefined}
                />
              ))}
          </Panel>
        ))
      )}
    </Scroll>
  );
}

interface FormProps {
  item: (Item & { price: string; hasStock: boolean }) | null;
  sections: string[];
}

/** A dish or drink, added or changed. A drink that has been stocked stays a drink. */
export function MenuFormScreen({ page, path, reload }: ScreenProps<FormProps>) {
  const { item, sections } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [kind, setKind] = useState<'dish' | 'drink'>(item?.kind ?? 'dish');
  const [name, setName] = useState(item?.name ?? '');
  const [section, setSection] = useState(item?.section ?? '');
  const [unit, setUnit] = useState(item?.unit ?? '');
  const [barcode, setBarcode] = useState(item?.barcode ?? '');
  const [price, setPrice] = useState(item?.price ?? '');
  const [reorder, setReorder] = useState(item ? String(item.reorderLevel) : '');
  const [active, setActive] = useState(item?.active ?? true);
  const drink = kind === 'drink';

  const save = () => {
    const data = {
      kind,
      name: name.trim(),
      section: section.trim() || null,
      unit: unit.trim() || null,
      barcode: drink ? barcode.trim() || null : null,
      price: amountInput(price.trim()),
      reorder_level: drink && reorder.trim() !== '' ? reorder.trim() : null,
      active,
    };
    return item ? run('PUT', `menu/${item.id}`, data) : run('POST', 'menu', data);
  };

  return (
    <Scroll>
      <Panel title={item ? `Change ${item.name}` : 'New on the menu'}>
        <View className="gap-4 p-4">
          <View className="gap-1.5">
            <Text className="text-sm font-medium text-slate-700">It is a</Text>
            <Chips
              options={[
                { value: 'dish', label: 'Dish: made to order' },
                { value: 'drink', label: 'Drink: kept in stock' },
              ]}
              value={kind}
              onChange={(next) => (item?.hasStock && next === 'dish' ? undefined : setKind(next))}
            />
            {item?.hasStock ? <Text className="text-sm text-slate-500">It has been stocked, so it stays a drink.</Text> : null}
            {errors.kind ? <Text className="text-sm text-red-600">{errors.kind}</Text> : null}
          </View>
          <Field label="Name" value={name} onChangeText={setName} maxLength={255} error={errors.name} autoFocus={!item} />
          <Field label="Price" prefix={currencyCode()} value={price} onChangeText={setPrice} keyboardType="decimal-pad" error={errors.price} />
          <Field label="Section" value={section} onChangeText={setSection} maxLength={64} placeholder="Starters, Mains, Soft drinks…" error={errors.section} />
          {sections.length > 0 ? <Chips scroll options={sections.map((s) => ({ value: s, label: s }))} value={section} onChange={setSection} /> : null}
          <Field
            label="Sold by the"
            value={unit}
            onChangeText={setUnit}
            maxLength={32}
            placeholder={drink ? 'bottle' : 'plate'}
            hint="Optional."
            error={errors.unit}
          />
          {drink ? (
            <>
              <Field
                label="Reorder at"
                value={reorder}
                onChangeText={setReorder}
                keyboardType="number-pad"
                placeholder="0"
                hint="It shows as running low at this many or fewer."
                error={errors.reorder_level}
              />
              <Field label="Barcode" value={barcode} onChangeText={setBarcode} maxLength={64} hint="Optional." error={errors.barcode} />
            </>
          ) : null}
          <Toggle label="On the menu" hint="Off: it can no longer be ordered." value={active} onChange={setActive} />
          <Button title={item ? 'Save' : 'Add to the menu'} icon="checkmark" size="lg" loading={busy} onPress={save} />
        </View>
      </Panel>
    </Scroll>
  );
}
