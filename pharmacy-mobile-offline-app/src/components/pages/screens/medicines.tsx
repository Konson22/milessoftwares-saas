import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { EmptyState, RxBadges } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { money } from '@/lib/format';
import { colors } from '@/lib/theme';

import { Choice, ChoiceRow, Panel, PanelRow, Toggle } from '../forms';
import { FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

interface Medicine {
  id: string;
  name: string;
  genericName: string;
  form: string;
  strength: string | null;
  unit: string;
  category: string | null;
  storageLabel: string;
  prescriptionOnly: boolean;
  controlled: boolean;
  sellPriceMinor: number;
  lastUnitCostMinor: number | null;
  active: boolean;
}

interface ListProps {
  medicines: Medicine[];
  filters: { search: string; category: string; inactive: boolean };
  categories: string[];
  canManage: boolean;
}

/** The pharmacy's medicines. */
export function MedicinesScreen({ page, loading, reload, setQuery }: ScreenProps<ListProps>) {
  const p = page.props;
  const [search, setSearch] = useState(p.filters.search);
  const keep = { search, category: p.filters.category, inactive: p.filters.inactive ? '1' : null };

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">{p.medicines.length} medicine(s)</Text>
      {p.canManage && <Button title="Add medicine" icon="add" square onPress={() => openPage('medicines/create')} />}
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ ...keep, search: q })} placeholder="Name, generic name or barcode" />
      {p.categories.length > 0 && (
        <FilterChips
          options={[{ value: '', label: 'Every category' }, ...p.categories.map((c) => ({ value: c, label: c }))]}
          value={p.filters.category}
          onChange={(category) => setQuery({ ...keep, category })}
        />
      )}
      <Toggle label="Show inactive too" value={p.filters.inactive} onChange={(on) => setQuery({ ...keep, inactive: on ? '1' : null })} />
      {p.medicines.length === 0 ? (
        <EmptyState icon="medkit-outline" title="No medicines found" />
      ) : (
        <Panel>
          {p.medicines.map((m) => (
            <PanelRow
              key={m.id}
              dim={!m.active}
              title={[m.name, m.strength].filter(Boolean).join(' ')}
              subtitle={[
                `${m.genericName} · ${m.form} · by the ${m.unit}`,
                [m.category, m.storageLabel, m.lastUnitCostMinor === null ? null : `last cost ${money(m.lastUnitCostMinor)}`].filter(Boolean).join(' · '),
              ].join('\n')}
              right={money(m.sellPriceMinor)}
              rightSub={m.active ? null : 'Inactive'}
              onPress={p.canManage ? () => openPage(`medicines/${m.id}/edit`) : undefined}>
              {(m.prescriptionOnly || m.controlled) && (
                <View className="flex-row gap-1">
                  <RxBadges rx={m.prescriptionOnly} cd={m.controlled} />
                </View>
              )}
            </PanelRow>
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}

interface FormMedicine {
  id: string;
  name: string;
  genericName: string;
  form: string;
  strength: string | null;
  unit: string;
  barcode: string | null;
  category: string | null;
  storage: string;
  prescriptionOnly: boolean;
  controlled: boolean;
  sellPrice: string;
  reorderLevel: number;
  tracksExpiry: boolean;
  active: boolean;
  packs: { name: string; units: number; sellPrice: string }[];
}

interface FormProps {
  medicine: FormMedicine | null;
  forms: { value: string; label: string }[];
  storageConditions: { value: string; label: string }[];
  categories: string[];
}

/** Adding or changing a medicine. */
export function MedicineFormScreen({ page, path, reload, loading }: ScreenProps<FormProps>) {
  const { medicine, forms, storageConditions, categories } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    name: medicine?.name ?? '',
    generic_name: medicine?.genericName ?? '',
    form: medicine?.form ?? forms[0]?.value ?? '',
    strength: medicine?.strength ?? '',
    unit: medicine?.unit ?? 'tablet',
    barcode: medicine?.barcode ?? '',
    category: medicine?.category ?? '',
    storage: medicine?.storage ?? storageConditions[0]?.value ?? '',
    prescription_only: medicine?.prescriptionOnly ?? false,
    controlled: medicine?.controlled ?? false,
    sell_price: medicine?.sellPrice ?? '',
    reorder_level: String(medicine?.reorderLevel ?? 0),
    tracks_expiry: medicine?.tracksExpiry ?? true,
    active: medicine?.active ?? true,
    packs: (medicine?.packs ?? []).map((p) => ({ name: p.name, units: String(p.units), sell_price: p.sellPrice })),
  });
  const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));
  const setPack = (i: number, patch: Partial<(typeof data.packs)[number]>) => set('packs', data.packs.map((p, j) => (j === i ? { ...p, ...patch } : p)));
  const unit = data.unit || 'unit';

  const submit = () =>
    void run(medicine ? 'PUT' : 'POST', medicine ? `medicines/${medicine.id}` : 'medicines', {
      ...data,
      reorder_level: Number(data.reorder_level) || 0,
      packs: data.packs.map((p) => ({ ...p, units: Number(p.units) || 0 })),
    });

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
        {medicine ? `Edit ${medicine.name}` : 'Add a medicine'}
      </Text>

      <Panel title="The medicine">
        <View className="gap-4 p-4">
          <Field label="Brand name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} autoCapitalize="words" />
          <Field label="Generic name" value={data.generic_name} onChangeText={(t) => set('generic_name', t)} error={errors.generic_name} />
          <Choice label="Form" options={forms} value={data.form} onChange={(v) => set('form', v)} error={errors.form} />
          <Field label="Strength" value={data.strength} onChangeText={(t) => set('strength', t)} error={errors.strength} placeholder="500 mg" />
          <Field label="Sold by the" value={data.unit} onChangeText={(t) => set('unit', t)} error={errors.unit} hint="tablet, capsule, bottle…" autoCapitalize="none" />
          <Field label="Barcode" value={data.barcode} onChangeText={(t) => set('barcode', t)} error={errors.barcode} autoCapitalize="none" />
          <Field label="Category" value={data.category} onChangeText={(t) => set('category', t)} error={errors.category} />
          {categories.length > 0 && (
            <ChoiceRow options={categories.map((c) => ({ value: c, label: c }))} value={data.category} onChange={(v) => set('category', v)} />
          )}
          <Choice label="Storage" options={storageConditions} value={data.storage} onChange={(v) => set('storage', v)} error={errors.storage} />
          <Toggle
            label="Prescription only"
            value={data.prescription_only || data.controlled}
            disabled={data.controlled}
            onChange={(on) => set('prescription_only', on)}
          />
          <Toggle label="Controlled (in the register)" value={data.controlled} onChange={(on) => set('controlled', on)} />
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
          <Toggle label="Has an expiry date" hint="Asked for each batch" value={data.tracks_expiry} onChange={(on) => set('tracks_expiry', on)} />
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
          {data.packs.length === 0 && <Text className="text-sm text-slate-500">Sold by the {unit} only. A strip or a box can be added as a pack.</Text>}
          {data.packs.map((pack, i) => (
            <View key={i} className="gap-3 border border-slate-200 p-3">
              <View className="flex-row items-center justify-between">
                <Text className="font-semibold text-slate-700">Pack {i + 1}</Text>
                <Pressable accessibilityRole="button" accessibilityLabel="Remove pack" hitSlop={8} onPress={() => set('packs', data.packs.filter((_, j) => j !== i))}>
                  <Ionicons name="trash-outline" size={20} color={colors.dangerDark} />
                </Pressable>
              </View>
              <Field label="Name" value={pack.name} onChangeText={(t) => setPack(i, { name: t })} error={errors[`packs.${i}.name`]} placeholder="Strip, box…" />
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

      <Button title={medicine ? 'Save' : 'Add medicine'} icon="checkmark" square size="lg" loading={busy} onPress={submit} />
      <Button title="Cancel" variant="outline" square onPress={() => router.back()} />
    </PageScroll>
  );
}
