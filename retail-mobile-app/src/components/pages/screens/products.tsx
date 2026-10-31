import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { money } from '@/lib/format';
import { colors } from '@/lib/theme';

import { ChoiceRow, Panel, PanelRow, Toggle } from '../forms';
import { FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

interface Product {
  id: string;
  name: string;
  unit: string;
  barcode: string | null;
  category: string | null;
  tracksExpiry: boolean;
  sellPriceMinor: number;
  lastUnitCostMinor: number | null;
  active: boolean;
}

interface ListProps {
  products: Product[];
  filters: { search: string; category: string; inactive: boolean };
  categories: string[];
  canManage: boolean;
}

/** The shop's products. */
export function ProductsScreen({ page, loading, reload, setQuery }: ScreenProps<ListProps>) {
  const p = page.props;
  const [search, setSearch] = useState(p.filters.search);
  const keep = { search, category: p.filters.category, inactive: p.filters.inactive ? '1' : null };

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">{p.products.length} product(s)</Text>
      {p.canManage && <Button title="Add product" icon="add" square onPress={() => openPage('products/create')} />}
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ ...keep, search: q })} placeholder="Name, category or barcode" />
      {p.categories.length > 0 && (
        <FilterChips
          options={[{ value: '', label: 'Every category' }, ...p.categories.map((c) => ({ value: c, label: c }))]}
          value={p.filters.category}
          onChange={(category) => setQuery({ ...keep, category })}
        />
      )}
      <Toggle label="Show inactive too" value={p.filters.inactive} onChange={(on) => setQuery({ ...keep, inactive: on ? '1' : null })} />
      {p.products.length === 0 ? (
        <EmptyState icon="pricetags-outline" title="No products found" />
      ) : (
        <Panel>
          {p.products.map((m) => (
            <PanelRow
              key={m.id}
              dim={!m.active}
              title={m.name}
              subtitle={[
                [`by the ${m.unit}`, m.barcode, m.tracksExpiry ? 'has an expiry' : null].filter(Boolean).join(' · '),
                [m.category, m.lastUnitCostMinor === null ? null : `last cost ${money(m.lastUnitCostMinor)}`].filter(Boolean).join(' · '),
              ]
                .filter(Boolean)
                .join('\n')}
              right={money(m.sellPriceMinor)}
              rightSub={m.active ? null : 'Inactive'}
              onPress={p.canManage ? () => openPage(`products/${m.id}/edit`) : undefined}
            />
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}

interface FormProduct {
  id: string;
  name: string;
  unit: string;
  barcode: string | null;
  category: string | null;
  sellPrice: string;
  reorderLevel: number;
  tracksExpiry: boolean;
  active: boolean;
  packs: { name: string; units: number; sellPrice: string }[];
}

interface FormProps {
  product: FormProduct | null;
  categories: string[];
}

/** Adding or changing a product. */
export function ProductFormScreen({ page, path, reload, loading }: ScreenProps<FormProps>) {
  const { product, categories } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    name: product?.name ?? '',
    unit: product?.unit ?? 'piece',
    barcode: product?.barcode ?? '',
    category: product?.category ?? '',
    sell_price: product?.sellPrice ?? '',
    reorder_level: String(product?.reorderLevel ?? 0),
    tracks_expiry: product?.tracksExpiry ?? false,
    active: product?.active ?? true,
    packs: (product?.packs ?? []).map((p) => ({ name: p.name, units: String(p.units), sell_price: p.sellPrice })),
  });
  const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));
  const setPack = (i: number, patch: Partial<(typeof data.packs)[number]>) => set('packs', data.packs.map((p, j) => (j === i ? { ...p, ...patch } : p)));
  const unit = data.unit || 'unit';

  const submit = () =>
    void run(product ? 'PUT' : 'POST', product ? `products/${product.id}` : 'products', {
      ...data,
      reorder_level: Number(data.reorder_level) || 0,
      packs: data.packs.map((p) => ({ ...p, units: Number(p.units) || 0 })),
    });

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
        {product ? `Edit ${product.name}` : 'Add a product'}
      </Text>

      <Panel title="The product">
        <View className="gap-4 p-4">
          <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} autoCapitalize="words" />
          <Field label="Sold by the" value={data.unit} onChangeText={(t) => set('unit', t)} error={errors.unit} hint="piece, bottle, kg…" autoCapitalize="none" />
          <Field label="Barcode" value={data.barcode} onChangeText={(t) => set('barcode', t)} error={errors.barcode} autoCapitalize="none" />
          <Field label="Category" value={data.category} onChangeText={(t) => set('category', t)} error={errors.category} />
          {categories.length > 0 && (
            <ChoiceRow options={categories.map((c) => ({ value: c, label: c }))} value={data.category} onChange={(v) => set('category', v)} />
          )}
        </View>
      </Panel>

      <Panel title="Price and stock">
        <View className="gap-4 p-4">
          <Field label={`Price a ${unit}`} value={data.sell_price} onChangeText={(t) => set('sell_price', t)} error={errors.sell_price} keyboardType="decimal-pad" />
          <Field
            label="Reorder at"
            value={data.reorder_level}
            onChangeText={(t) => set('reorder_level', t)}
            error={errors.reorder_level}
            hint="Low on stock at or below this"
            keyboardType="number-pad"
          />
          <Toggle label="Active" hint="Sold and bought" value={data.active} onChange={(on) => set('active', on)} />
          <Toggle label="Has an expiry date" hint="Food, drink, medicines: asked for each delivery" value={data.tracks_expiry} onChange={(on) => set('tracks_expiry', on)} />
        </View>
      </Panel>

      <Panel
        title="Packs"
        right={
          data.packs.length < 10 ? (
            <Pressable
              accessibilityRole="button"
              onPress={() => set('packs', [...data.packs, { name: '', units: '10', sell_price: '' }])}
              className="min-h-9 flex-row items-center gap-1 border border-brand-200 px-3 active:bg-brand-50">
              <Ionicons name="add" size={16} color={colors.brand[700]} />
              <Text className="text-sm font-semibold text-brand-700">Add pack</Text>
            </Pressable>
          ) : null
        }>
        <View className="gap-4 p-4">
          {data.packs.length === 0 && <Text className="text-sm text-slate-500">Sold by the {unit} only. A box, crate or carton can be added as a pack.</Text>}
          {data.packs.map((pack, i) => (
            <View key={i} className="gap-3 border border-slate-200 p-3">
              <View className="flex-row items-center justify-between">
                <Text className="font-semibold text-slate-700">Pack {i + 1}</Text>
                <Pressable accessibilityRole="button" accessibilityLabel="Remove pack" hitSlop={8} onPress={() => set('packs', data.packs.filter((_, j) => j !== i))}>
                  <Ionicons name="trash-outline" size={20} color={colors.dangerDark} />
                </Pressable>
              </View>
              <Field label="Name" value={pack.name} onChangeText={(t) => setPack(i, { name: t })} error={errors[`packs.${i}.name`]} placeholder="Box, crate…" />
              <Field
                label={`${unit}s in it`}
                value={pack.units}
                onChangeText={(t) => setPack(i, { units: t })}
                error={errors[`packs.${i}.units`]}
                keyboardType="number-pad"
              />
              <Field
                label="Pack price"
                value={pack.sell_price}
                onChangeText={(t) => setPack(i, { sell_price: t })}
                error={errors[`packs.${i}.sell_price`]}
                hint="Empty: units × price"
                keyboardType="decimal-pad"
              />
            </View>
          ))}
        </View>
      </Panel>

      <Button title={product ? 'Save' : 'Add product'} icon="checkmark" square size="lg" loading={busy} onPress={submit} />
      <Button title="Cancel" variant="outline" square onPress={() => router.back()} />
    </PageScroll>
  );
}
