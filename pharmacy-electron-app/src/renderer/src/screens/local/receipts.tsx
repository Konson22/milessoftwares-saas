import { PackageCheck, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

import { bothCurrencies, CurrencySelect, enteredInBase, moneyAt, type PricingOptions } from '@/components/pricing';
import { Button, Empty, Field, Input, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { dateTime, money } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

import type { ScreenProps } from '../types';

interface ReceiptRow {
  id: string;
  number: string;
  received_on: string;
  supplier: string | null;
  invoice_number: string | null;
  lines: number;
  total_minor: number;
  by: string;
}

/** Deliveries from suppliers: each puts its batches in stock. */
export function ReceiptsScreen({ page }: ScreenProps<{ receipts: ReceiptRow[]; filters: { search: string }; canReceive: boolean }>) {
  const p = page.props;
  const open = useUi((s) => s.open);
  const [search, setSearch] = useState(p.filters.search);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Goods received"
        description="Each delivery puts its batches in stock, with their expiry, cost and selling price."
        actions={
          p.canReceive ? (
            <Button onClick={() => open('receipts/create')}>
              <Plus size={16} /> Receive stock
            </Button>
          ) : null
        }
      />
      <form
        className="relative"
        onSubmit={(e) => {
          e.preventDefault();
          open(withQuery('receipts', { search }));
        }}
      >
        <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Number, invoice or supplier, then Enter" className="pl-9" />
      </form>
      <Panel>
        {p.receipts.length === 0 ? (
          <Empty icon={<PackageCheck size={28} />} title="Nothing received yet" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Number</th>
                <th className="px-4 py-2 font-medium">Received</th>
                <th className="px-4 py-2 font-medium">Supplier</th>
                <th className="px-4 py-2 font-medium">Invoice</th>
                <th className="px-4 py-2 text-right font-medium">Lines</th>
                <th className="px-4 py-2 text-right font-medium">Cost</th>
                <th className="px-4 py-2 font-medium">By</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {p.receipts.map((r) => (
                <tr key={r.id} onClick={() => open(`receipts/${r.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2.5 font-medium">{r.number}</td>
                  <td className="px-4 py-2.5">{r.received_on}</td>
                  <td className="px-4 py-2.5">{r.supplier ?? '—'}</td>
                  <td className="px-4 py-2.5">{r.invoice_number ?? '—'}</td>
                  <td className="px-4 py-2.5 text-right tabular">{r.lines}</td>
                  <td className="px-4 py-2.5 text-right tabular">{money(r.total_minor)}</td>
                  <td className="px-4 py-2.5">{r.by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}

interface MedicineOption {
  id: string;
  label: string;
  generic: string;
  barcode: string | null;
  unit: string;
  tracksExpiry: boolean;
  packs: { name: string; units: number }[];
  /** A unit's price now, in the pharmacy's currency ("35.00"). */
  sellPrice: string;
  last: { buyUnit: string | null; buyUnitSize: number; buyCurrency: string; buyPrice: string } | null;
}

interface Line {
  key: string;
  medicine: MedicineOption;
  batch_number: string;
  expires_on: string;
  /** "unit", "pack:<index>" or "other". */
  bought_by: string;
  buy_unit: string;
  buy_unit_size: string;
  buy_quantity: string;
  buy_price: string;
  buy_currency: string;
  sell_price: string;
  sell_currency: string;
}

/** How a line was bought: so many units a pack, and the pack's name. */
function packOf(line: Line): { name: string | null; size: number } {
  if (line.bought_by === 'unit') return { name: null, size: 1 };
  const index = line.bought_by.startsWith('pack:') ? Number(line.bought_by.slice(5)) : -1;
  const pack = line.medicine.packs[index];
  if (pack) return { name: pack.name, size: pack.units };
  return { name: line.buy_unit.trim() || 'Box', size: Math.max(0, Math.floor(Number(line.buy_unit_size) || 0)) };
}

function newLine(medicine: MedicineOption, options: PricingOptions): Line {
  const last = medicine.last;
  // Bought as last time: the same pack (one of the medicine's, or another size), currency and price.
  let boughtBy = 'unit';
  let other = { name: '', size: '' };
  if (last && last.buyUnitSize > 1) {
    const index = medicine.packs.findIndex((p) => p.units === last.buyUnitSize);
    if (index >= 0) boughtBy = `pack:${index}`;
    else {
      boughtBy = 'other';
      other = { name: last.buyUnit ?? 'Box', size: String(last.buyUnitSize) };
    }
  } else if (!last && medicine.packs.length) boughtBy = 'pack:0';
  const currency = last && (last.buyCurrency === options.currency || last.buyCurrency === options.secondCurrency) ? last.buyCurrency : options.currency;
  return {
    key: crypto.randomUUID(),
    medicine,
    batch_number: '',
    expires_on: '',
    bought_by: boughtBy,
    buy_unit: other.name,
    buy_unit_size: other.size,
    buy_quantity: '',
    buy_price: last?.buyPrice ?? '',
    buy_currency: currency,
    sell_price: medicine.sellPrice,
    sell_currency: options.currency,
  };
}

interface FormProps {
  suppliers: { id: string; name: string }[];
  medicines: MedicineOption[];
  pricing: PricingOptions;
  today: string;
}

/**
 * Receiving a delivery, line by line: what came, bought by the unit or the
 * box, at the invoice's price in either currency; a unit's cost follows, and
 * its selling price is set here.
 */
export function ReceiptFormScreen({ page, path, reload }: ScreenProps<FormProps>) {
  const p = page.props;
  const back = useUi((s) => s.back);
  const open = useUi((s) => s.open);
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({ supplier_id: '', invoice_number: '', received_on: p.today, note: '', exchange_rate: p.pricing.rate ? String(p.pricing.rate) : '' });
  const [lines, setLines] = useState<Line[]>([]);
  const [search, setSearch] = useState('');

  const rate = Number(data.exchange_rate.replace(/,/g, '')) || null;
  const m = moneyAt(p.pricing, rate);
  const second = p.pricing.secondCurrency;

  const found = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return [];
    return p.medicines.filter((x) => x.label.toLowerCase().includes(q) || x.generic.toLowerCase().includes(q) || x.barcode === search.trim()).slice(0, 12);
  }, [search, p.medicines]);

  const add = (medicine: MedicineOption) => {
    setLines([...lines, newLine(medicine, p.pricing)]);
    setSearch('');
  };
  const setLine = (key: string, patch: Partial<Line>) => setLines(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));

  const figures = (line: Line) => {
    const pack = packOf(line);
    const quantity = Math.max(0, Math.floor(Number(line.buy_quantity) || 0));
    const packCost = enteredInBase(m, line.buy_price, line.buy_currency);
    const sell = enteredInBase(m, line.sell_price, line.sell_currency);
    const unitCost = packCost !== null && pack.size > 0 ? packCost / pack.size : null;
    return {
      pack,
      units: quantity * pack.size,
      unitCost,
      cost: packCost === null ? 0 : packCost * quantity,
      sell,
      margin: sell && unitCost !== null ? Math.round(((sell - unitCost) / sell) * 100) : null,
    };
  };
  const total = lines.reduce((sum, l) => sum + figures(l).cost, 0);
  const needsRate = lines.some((l) => l.buy_currency !== p.pricing.currency || l.sell_currency !== p.pricing.currency);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    void run('POST', 'receipts', {
      ...data,
      supplier_id: data.supplier_id || null,
      lines: lines.map((l) => {
        const pack = packOf(l);
        return {
          medicine_id: l.medicine.id,
          batch_number: l.batch_number,
          expires_on: l.expires_on || null,
          buy_unit: pack.name,
          buy_unit_size: pack.size,
          buy_quantity: l.buy_quantity,
          buy_price: l.buy_price,
          buy_currency: l.buy_currency,
          sell_price: l.sell_price,
          sell_currency: l.sell_currency,
        };
      }),
    });
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <PageHeader title="Receive stock" description="Each line becomes a batch with its expiry, cost and selling price. It can be sold as soon as this is saved." />
      <Panel title="The delivery">
        <div className="grid grid-cols-5 gap-4 p-4">
          <Field label="Supplier" error={errors.supplier_id}>
            <Select value={data.supplier_id} onChange={(e) => setData({ ...data, supplier_id: e.target.value })}>
              <option value="">Not named</option>
              {p.suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Their invoice or delivery note" error={errors.invoice_number}>
            <Input value={data.invoice_number} onChange={(e) => setData({ ...data, invoice_number: e.target.value })} />
          </Field>
          <Field label="Received on" error={errors.received_on}>
            <Input type="date" max={p.today} value={data.received_on} onChange={(e) => setData({ ...data, received_on: e.target.value })} required />
          </Field>
          {second ? (
            <Field label={`1 ${second} in ${p.pricing.currency}`} hint={p.pricing.rate ? 'Today’s rate under Settings; change it for this delivery' : 'No rate under Settings yet'}>
              <Input inputMode="decimal" value={data.exchange_rate} onChange={(e) => setData({ ...data, exchange_rate: e.target.value })} placeholder="e.g. 4500" />
            </Field>
          ) : (
            <div />
          )}
          <Field label="Note" error={errors.note}>
            <Input value={data.note} onChange={(e) => setData({ ...data, note: e.target.value })} />
          </Field>
        </div>
      </Panel>

      <Panel title="What came" actions={<span className="text-sm font-semibold tabular">{bothCurrencies(m, total)}</span>}>
        <div className="space-y-3 p-4">
          {errors.lines ? <Notice tone="red">{errors.lines}</Notice> : null}
          {needsRate && !rate ? <Notice tone="amber">Enter the exchange rate above to buy or sell in {second}.</Notice> : null}
          {lines.map((line, i) => {
            const f = figures(line);
            const unit = line.medicine.unit;
            const e = (field: string) => errors[`lines.${i}.${field}`];
            return (
              <div key={line.key} className="space-y-3 border border-border p-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="text-sm">
                    <div className="font-medium">{line.medicine.label}</div>
                    <div className="text-xs text-muted-foreground">Sold by the {unit}</div>
                  </div>
                  <button
                    type="button"
                    className="p-1 text-muted-foreground hover:text-destructive"
                    onClick={() => setLines(lines.filter((l) => l.key !== line.key))}
                    aria-label="Remove"
                  >
                    <Trash2 size={15} />
                  </button>
                </div>

                <div className="grid grid-cols-[1fr_1fr_1.3fr_0.7fr_1.3fr] gap-3">
                  <Field label="Batch number" error={e('batch_number')}>
                    <Input value={line.batch_number} onChange={(ev) => setLine(line.key, { batch_number: ev.target.value })} required />
                  </Field>
                  <Field label="Expires" error={e('expires_on')}>
                    <Input type="date" value={line.expires_on} min={p.today} onChange={(ev) => setLine(line.key, { expires_on: ev.target.value })} required={line.medicine.tracksExpiry} />
                  </Field>
                  <Field label="Bought by" error={e('buy_unit_size')}>
                    <div className="flex gap-1">
                      <Select value={line.bought_by} onChange={(ev) => setLine(line.key, { bought_by: ev.target.value })}>
                        <option value="unit">The {unit}</option>
                        {line.medicine.packs.map((pk, index) => (
                          <option key={pk.name + index} value={`pack:${index}`}>
                            {pk.name} of {pk.units}
                          </option>
                        ))}
                        <option value="other">Another box size…</option>
                      </Select>
                    </div>
                  </Field>
                  <Field label={f.pack.size > 1 ? `How many (${f.pack.name})` : `How many ${unit}s`} error={e('buy_quantity')} hint={f.pack.size > 1 && f.units ? `${f.units} ${unit}s` : undefined}>
                    <Input type="number" min={1} value={line.buy_quantity} onChange={(ev) => setLine(line.key, { buy_quantity: ev.target.value })} required />
                  </Field>
                  <Field label={`Buying price a ${f.pack.size > 1 ? (f.pack.name ?? 'box').toLowerCase() : unit}`} error={e('buy_price') ?? e('buy_currency')}>
                    <div className="flex gap-1">
                      <Input inputMode="decimal" value={line.buy_price} onChange={(ev) => setLine(line.key, { buy_price: ev.target.value })} required />
                      <CurrencySelect options={p.pricing} value={line.buy_currency} onChange={(code) => setLine(line.key, { buy_currency: code })} />
                    </div>
                  </Field>
                </div>

                {line.bought_by === 'other' ? (
                  <div className="grid grid-cols-[1fr_1fr_3fr] gap-3">
                    <Field label="Called">
                      <Input value={line.buy_unit} onChange={(ev) => setLine(line.key, { buy_unit: ev.target.value })} placeholder="Box" />
                    </Field>
                    <Field label={`${unit}s in one`}>
                      <Input type="number" min={2} value={line.buy_unit_size} onChange={(ev) => setLine(line.key, { buy_unit_size: ev.target.value })} required />
                    </Field>
                    <div />
                  </div>
                ) : null}

                <div className="grid grid-cols-[1.3fr_1.3fr_2.4fr] items-end gap-3">
                  <div className="text-sm">
                    <div className="text-xs font-medium text-muted-foreground">Cost of one {unit}</div>
                    <div className="h-9 py-2 font-medium tabular">{f.unitCost === null ? '—' : bothCurrencies(m, f.unitCost)}</div>
                  </div>
                  <Field label={`Selling price a ${unit}`} error={e('sell_price') ?? e('sell_currency')}>
                    <div className="flex gap-1">
                      <Input inputMode="decimal" value={line.sell_price} onChange={(ev) => setLine(line.key, { sell_price: ev.target.value })} placeholder="The medicine’s price" />
                      <CurrencySelect options={p.pricing} value={line.sell_currency} onChange={(code) => setLine(line.key, { sell_currency: code })} />
                    </div>
                  </Field>
                  <div className="pb-2 text-sm">
                    {f.sell !== null ? (
                      <span className="tabular">
                        Sells at {bothCurrencies(m, f.sell)}
                        {f.margin !== null ? (
                          <span className={f.margin < 0 ? 'ml-2 font-medium text-destructive' : 'ml-2 text-emerald-700'}>
                            {f.margin < 0 ? 'Below cost' : `${f.margin}% margin`}
                          </span>
                        ) : null}
                      </span>
                    ) : null}
                    {f.cost ? <span className="ml-3 text-muted-foreground tabular">Line cost {money(Math.round(f.cost))}</span> : null}
                  </div>
                </div>
              </div>
            );
          })}
          <div className="relative">
            <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Add a medicine: name, generic name or barcode" className="pl-9" />
            {found.length > 0 ? (
              <div className="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto border border-border bg-card shadow-lg">
                {found.map((x) => (
                  <button key={x.id} type="button" onClick={() => add(x)} className="block w-full px-3 py-2 text-left text-sm hover:bg-accent">
                    {x.label} <span className="text-xs text-muted-foreground">{x.generic}</span>
                  </button>
                ))}
              </div>
            ) : search.trim() ? (
              <p className="mt-1 text-xs text-muted-foreground">
                No medicine matches.{' '}
                <button type="button" className="underline" onClick={() => open('medicines/create')}>
                  Add it under Medicines
                </button>{' '}
                first.
              </p>
            ) : null}
          </div>
        </div>
      </Panel>

      <div className="flex gap-2">
        <Button type="submit" disabled={busy || lines.length === 0}>
          Receive into stock
        </Button>
        <Button variant="secondary" onClick={back}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

interface ShowProps {
  receipt: { id: string; number: string; received_on: string; supplier: string | null; invoice_number: string | null; note: string | null; total_minor: number; by: string; created_at: string };
  lines: {
    id: string;
    medicine: string;
    unit: string;
    batch_number: string;
    expires_on: string | null;
    quantity: number;
    bought: string | null;
    boughtAs: string | null;
    unit_cost_minor: number;
    sell_price_minor: number | null;
    total_minor: number;
  }[];
}

export function ReceiptScreen({ page }: ScreenProps<ShowProps>) {
  const { receipt, lines } = page.props;
  return (
    <div className="space-y-4">
      <PageHeader
        title={`Goods received ${receipt.number}`}
        description={`${receipt.received_on}${receipt.supplier ? ` · from ${receipt.supplier}` : ''}${receipt.invoice_number ? ` · invoice ${receipt.invoice_number}` : ''} · by ${receipt.by}, ${dateTime(receipt.created_at)}`}
      />
      {receipt.note ? <Notice tone="blue">{receipt.note}</Notice> : null}
      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-muted text-left text-xs text-muted-foreground">
            <tr>
              <th className="px-4 py-2 font-medium">Medicine</th>
              <th className="px-4 py-2 font-medium">Batch</th>
              <th className="px-4 py-2 font-medium">Expires</th>
              <th className="px-4 py-2 text-right font-medium">Quantity</th>
              <th className="px-4 py-2 font-medium">Bought as</th>
              <th className="px-4 py-2 text-right font-medium">Unit cost</th>
              <th className="px-4 py-2 text-right font-medium">Sells at</th>
              <th className="px-4 py-2 text-right font-medium">Cost</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {lines.map((l) => (
              <tr key={l.id}>
                <td className="px-4 py-2.5 font-medium">{l.medicine}</td>
                <td className="px-4 py-2.5">{l.batch_number}</td>
                <td className="px-4 py-2.5">{l.expires_on ?? '—'}</td>
                <td className="px-4 py-2.5 text-right tabular">
                  {l.quantity} {l.unit}
                  {l.bought ? <div className="text-xs text-muted-foreground">{l.bought}</div> : null}
                </td>
                <td className="px-4 py-2.5 text-xs text-muted-foreground">{l.boughtAs ?? '—'}</td>
                <td className="px-4 py-2.5 text-right tabular">{money(l.unit_cost_minor)}</td>
                <td className="px-4 py-2.5 text-right tabular">{l.sell_price_minor === null ? '—' : money(l.sell_price_minor)}</td>
                <td className="px-4 py-2.5 text-right tabular">{money(l.total_minor)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-border font-semibold">
              <td className="px-4 py-2.5" colSpan={7}>
                Total
              </td>
              <td className="px-4 py-2.5 text-right tabular">{money(receipt.total_minor)}</td>
            </tr>
          </tfoot>
        </table>
      </Panel>
    </div>
  );
}
