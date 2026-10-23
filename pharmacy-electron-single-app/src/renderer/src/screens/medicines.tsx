import { Plus, Search, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Badge, Button, cn, Empty, Field, Input, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { money } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

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
export function MedicinesScreen({ page }: ScreenProps<ListProps>) {
  const p = page.props;
  const open = useUi((s) => s.open);
  const [search, setSearch] = useState(p.filters.search);

  const filter = (next: Partial<ListProps['filters']>) => open(withQuery('medicines', { ...p.filters, search, ...next }));

  return (
    <div className="space-y-4">
      <PageHeader
        title="Medicines"
        description={`${p.medicines.length} medicine(s)`}
        actions={
          p.canManage ? (
            <Button onClick={() => open('medicines/create')}>
              <Plus size={16} /> Add medicine
            </Button>
          ) : null
        }
      />
      <div className="flex items-center gap-2">
        <form
          className="relative flex-1"
          onSubmit={(e) => {
            e.preventDefault();
            filter({ search });
          }}
        >
          <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, generic name or barcode, then Enter" className="pl-9" />
        </form>
        <Select value={p.filters.category} onChange={(e) => filter({ category: e.target.value })} className="w-48">
          <option value="">Every category</option>
          {p.categories.map((c) => (
            <option key={c}>{c}</option>
          ))}
        </Select>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={p.filters.inactive} onChange={(e) => filter({ inactive: e.target.checked })} /> Inactive too
        </label>
      </div>

      <Panel>
        {p.medicines.length === 0 ? (
          <Empty title="No medicines found" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Medicine</th>
                <th className="px-4 py-2 font-medium">Category</th>
                <th className="px-4 py-2 font-medium">Storage</th>
                <th className="px-4 py-2 text-right font-medium">Last cost</th>
                <th className="px-4 py-2 text-right font-medium">Price</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.medicines.map((m) => (
                <tr
                  key={m.id}
                  onClick={p.canManage ? () => open(`medicines/${m.id}/edit`) : undefined}
                  className={cn(p.canManage && 'cursor-pointer hover:bg-accent', !m.active && 'opacity-50')}
                >
                  <td className="px-4 py-2.5">
                    <div className="flex items-center gap-2 font-medium">
                      {m.name} <span className="font-normal text-muted-foreground">{m.strength}</span>
                      {m.prescriptionOnly ? <Badge tone="blue">Rx</Badge> : null}
                      {m.controlled ? <Badge tone="red">Controlled</Badge> : null}
                      {!m.active ? <Badge>Inactive</Badge> : null}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {m.genericName} · {m.form} · by the {m.unit}
                    </div>
                  </td>
                  <td className="px-4 py-2.5">{m.category ?? '—'}</td>
                  <td className="px-4 py-2.5 text-xs">{m.storageLabel}</td>
                  <td className="px-4 py-2.5 text-right tabular">{m.lastUnitCostMinor === null ? '—' : money(m.lastUnitCostMinor)}</td>
                  <td className="px-4 py-2.5 text-right font-medium tabular">{money(m.sellPriceMinor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
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
  lastUnitCostMinor: number | null;
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
export function MedicineFormScreen({ page, path, reload }: ScreenProps<FormProps>) {
  const { medicine, forms, storageConditions, categories } = page.props;
  const back = useUi((s) => s.back);
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
    reorder_level: medicine?.reorderLevel ?? 0,
    tracks_expiry: medicine?.tracksExpiry ?? true,
    active: medicine?.active ?? true,
    packs: (medicine?.packs ?? []).map((p) => ({ name: p.name, units: p.units, sell_price: p.sellPrice })),
  });
  const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    void run(medicine ? 'PUT' : 'POST', medicine ? `medicines/${medicine.id}` : 'medicines', data);
  };

  return (
    <form onSubmit={submit} className="max-w-3xl space-y-4">
      <PageHeader title={medicine ? `Edit ${medicine.name}` : 'Add a medicine'} />
      <Panel title="The medicine">
        <div className="grid grid-cols-2 gap-4 p-4">
          <Field label="Brand name" error={errors.name}>
            <Input value={data.name} onChange={(e) => set('name', e.target.value)} required autoFocus />
          </Field>
          <Field label="Generic name" error={errors.generic_name}>
            <Input value={data.generic_name} onChange={(e) => set('generic_name', e.target.value)} required />
          </Field>
          <Field label="Form" error={errors.form}>
            <Select value={data.form} onChange={(e) => set('form', e.target.value)}>
              {forms.map((f) => (
                <option key={f.value} value={f.value}>
                  {f.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Strength" error={errors.strength}>
            <Input value={data.strength} onChange={(e) => set('strength', e.target.value)} placeholder="500 mg" />
          </Field>
          <Field label="Sold by the" error={errors.unit} hint="tablet, capsule, bottle…">
            <Input value={data.unit} onChange={(e) => set('unit', e.target.value)} required />
          </Field>
          <Field label="Barcode" error={errors.barcode}>
            <Input value={data.barcode} onChange={(e) => set('barcode', e.target.value)} />
          </Field>
          <Field label="Category" error={errors.category}>
            <Input value={data.category} onChange={(e) => set('category', e.target.value)} list="medicine-categories" />
            <datalist id="medicine-categories">
              {categories.map((c) => (
                <option key={c} value={c} />
              ))}
            </datalist>
          </Field>
          <Field label="Storage" error={errors.storage}>
            <Select value={data.storage} onChange={(e) => set('storage', e.target.value)}>
              {storageConditions.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </Field>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.prescription_only || data.controlled} disabled={data.controlled} onChange={(e) => set('prescription_only', e.target.checked)} />
            Prescription only
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.controlled} onChange={(e) => set('controlled', e.target.checked)} /> Controlled (in the register)
          </label>
        </div>
      </Panel>

      <Panel title="Price and stock">
        <div className="grid grid-cols-3 gap-4 p-4">
          <Field label={`Price a ${data.unit || 'unit'}`} error={errors.sell_price}>
            <Input value={data.sell_price} onChange={(e) => set('sell_price', e.target.value)} inputMode="decimal" required />
          </Field>
          <Field label="Reorder at" error={errors.reorder_level} hint="Low on stock at or below this">
            <Input type="number" min={0} value={data.reorder_level} onChange={(e) => set('reorder_level', Number(e.target.value))} />
          </Field>
          <label className="flex items-center gap-2 self-end pb-2 text-sm">
            <input type="checkbox" checked={data.active} onChange={(e) => set('active', e.target.checked)} /> Active (sold and bought)
          </label>
          <label className="col-span-3 flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.tracks_expiry} onChange={(e) => set('tracks_expiry', e.target.checked)} /> Has an expiry date (asked for each batch)
          </label>
        </div>
        <div className="space-y-2 border-t border-border p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium">Packs</span>
            <Button size="sm" variant="secondary" onClick={() => set('packs', [...data.packs, { name: '', units: 10, sell_price: '' }])} disabled={data.packs.length >= 10}>
              <Plus size={14} /> Add pack
            </Button>
          </div>
          {data.packs.map((pack, i) => (
            <div key={i} className="grid grid-cols-[1fr_120px_160px_auto] items-start gap-2">
              <Field label="Name" error={errors[`packs.${i}.name`]}>
                <Input value={pack.name} onChange={(e) => set('packs', data.packs.map((p, j) => (j === i ? { ...p, name: e.target.value } : p)))} placeholder="Strip, box…" />
              </Field>
              <Field label={`${data.unit || 'Unit'}s in it`} error={errors[`packs.${i}.units`]}>
                <Input type="number" min={2} value={pack.units} onChange={(e) => set('packs', data.packs.map((p, j) => (j === i ? { ...p, units: Number(e.target.value) } : p)))} />
              </Field>
              <Field label="Pack price" error={errors[`packs.${i}.sell_price`]} hint="Empty: units × price">
                <Input value={pack.sell_price} onChange={(e) => set('packs', data.packs.map((p, j) => (j === i ? { ...p, sell_price: e.target.value } : p)))} inputMode="decimal" />
              </Field>
              <button type="button" className="mt-6 p-2 text-muted-foreground hover:text-destructive" onClick={() => set('packs', data.packs.filter((_, j) => j !== i))}>
                <Trash2 size={15} />
              </button>
            </div>
          ))}
        </div>
      </Panel>

      <div className="flex gap-2">
        <Button type="submit" disabled={busy}>
          {medicine ? 'Save' : 'Add medicine'}
        </Button>
        <Button variant="secondary" onClick={back}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
