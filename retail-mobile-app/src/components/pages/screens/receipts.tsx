import { Ionicons } from '@expo/vector-icons';
import { randomUUID } from 'expo-crypto';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, Card, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { dateTime, fullDate, money } from '@/lib/format';
import { colors } from '@/lib/theme';

import { bothCurrencies, Choice, ChoiceRow, DateField, enteredInBase, moneyAt, Panel, PanelRow, PriceField, type PricingOptions } from '../forms';
import { Details, PageScroll, SearchBox } from '../kit';
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
export function ReceiptsScreen({ page, loading, reload, setQuery }: ScreenProps<{ receipts: ReceiptRow[]; filters: { search: string }; canReceive: boolean }>) {
  const p = page.props;
  const [search, setSearch] = useState(p.filters.search);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">Each delivery puts its batches in stock, with their expiry, cost and selling price.</Text>
      {p.canReceive && <Button title="Receive stock" icon="download-outline" square onPress={() => openPage('receipts/create')} />}
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ search: q })} placeholder="Number, invoice or supplier" />
      {p.receipts.length === 0 ? (
        <EmptyState icon="cube-outline" title="Nothing received yet" />
      ) : (
        <Panel>
          {p.receipts.map((r) => (
            <PanelRow
              key={r.id}
              title={r.number}
              subtitle={[
                [fullDate(r.received_on), r.supplier].filter(Boolean).join(' · '),
                [r.invoice_number ? `Invoice ${r.invoice_number}` : null, `${r.lines} line(s)`, `by ${r.by}`].filter(Boolean).join(' · '),
              ].join('\n')}
              right={money(r.total_minor)}
              onPress={() => openPage(`receipts/${r.id}`)}
            />
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}

interface ProductOption {
  id: string;
  label: string;
  barcode: string | null;
  unit: string;
  tracksExpiry: boolean;
  packs: { name: string; units: number }[];
  /** A unit's price now, in the shop's currency ("35.00"). */
  sellPrice: string;
  last: { buyUnit: string | null; buyUnitSize: number; buyCurrency: string; buyPrice: string } | null;
}

interface Line {
  key: string;
  product: ProductOption;
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
  const pack = line.product.packs[index];
  if (pack) return { name: pack.name, size: pack.units };
  return { name: line.buy_unit.trim() || 'Box', size: Math.max(0, Math.floor(Number(line.buy_unit_size) || 0)) };
}

function newLine(product: ProductOption, options: PricingOptions): Line {
  const last = product.last;
  // Bought as last time: the same pack (one of the product's, or another size), currency and price.
  let boughtBy = 'unit';
  let other = { name: '', size: '' };
  if (last && last.buyUnitSize > 1) {
    const index = product.packs.findIndex((p) => p.units === last.buyUnitSize);
    if (index >= 0) boughtBy = `pack:${index}`;
    else {
      boughtBy = 'other';
      other = { name: last.buyUnit ?? 'Box', size: String(last.buyUnitSize) };
    }
  } else if (!last && product.packs.length) boughtBy = 'pack:0';
  const currency = last && (last.buyCurrency === options.currency || last.buyCurrency === options.secondCurrency) ? last.buyCurrency : options.currency;
  return {
    key: randomUUID(),
    product,
    batch_number: '',
    expires_on: '',
    bought_by: boughtBy,
    buy_unit: other.name,
    buy_unit_size: other.size,
    buy_quantity: '',
    buy_price: last?.buyPrice ?? '',
    buy_currency: currency,
    sell_price: product.sellPrice,
    sell_currency: options.currency,
  };
}

interface FormProps {
  suppliers: { id: string; name: string }[];
  products: ProductOption[];
  pricing: PricingOptions;
  today: string;
}

/**
 * Receiving a delivery, line by line: what came, bought by the unit or the
 * box, at the invoice's price in either currency; a unit's cost follows, and
 * its selling price is set here.
 */
export function ReceiptFormScreen({ page, path, reload, loading }: ScreenProps<FormProps>) {
  const p = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    supplier_id: '',
    invoice_number: '',
    received_on: p.today,
    note: '',
    exchange_rate: p.pricing.rate ? String(p.pricing.rate) : '',
  });
  const [lines, setLines] = useState<Line[]>([]);
  const [search, setSearch] = useState('');

  const rate = Number(data.exchange_rate.replace(/,/g, '')) || null;
  const m = moneyAt(p.pricing, rate);
  const second = p.pricing.secondCurrency;

  const found = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return [];
    return p.products.filter((x) => x.label.toLowerCase().includes(q) || x.barcode === search.trim()).slice(0, 8);
  }, [search, p.products]);

  const add = (product: ProductOption) => {
    setLines((all) => [...all, newLine(product, p.pricing)]);
    setSearch('');
  };
  const setLine = (key: string, patch: Partial<Line>) => setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)));

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

  const submit = async () => {
    const ok = await run('POST', 'receipts', {
      ...data,
      supplier_id: data.supplier_id || null,
      lines: lines.map((l) => {
        const pack = packOf(l);
        return {
          product_id: l.product.id,
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
    // Received: a form left open (in the menu's own screen) starts again.
    if (ok) {
      setLines([]);
      setData((d) => ({ ...d, invoice_number: '', note: '' }));
    }
  };

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">Each line becomes a batch with its expiry, cost and selling price. It can be sold as soon as this is saved.</Text>

      <Panel title="The delivery">
        <View className="gap-4 p-4">
          <ChoiceRow
            label="Supplier"
            options={[{ value: '', label: 'Not named' }, ...p.suppliers.map((s) => ({ value: s.id, label: s.name }))]}
            value={data.supplier_id}
            onChange={(v) => setData({ ...data, supplier_id: v })}
            error={errors.supplier_id}
          />
          <Field
            label="Their invoice or delivery note"
            value={data.invoice_number}
            onChangeText={(t) => setData({ ...data, invoice_number: t })}
            error={errors.invoice_number}
          />
          <DateField label="Received on" value={data.received_on} onChange={(t) => setData({ ...data, received_on: t })} error={errors.received_on} />
          {second && (
            <Field
              label={`1 ${second} in ${p.pricing.currency}`}
              value={data.exchange_rate}
              onChangeText={(t) => setData({ ...data, exchange_rate: t })}
              keyboardType="decimal-pad"
              placeholder="e.g. 4500"
              hint={p.pricing.rate ? 'Today’s rate under Settings; change it for this delivery' : 'No rate under Settings yet'}
            />
          )}
          <Field label="Note" value={data.note} onChangeText={(t) => setData({ ...data, note: t })} error={errors.note} />
        </View>
      </Panel>

      <Panel title="What came" right={<Text className="text-sm font-bold text-slate-900">{bothCurrencies(m, total)}</Text>}>
        <View className="gap-3 p-4">
          {errors.lines && <Banner tone="error">{errors.lines}</Banner>}
          {needsRate && !rate && <Banner tone="warning">Enter the exchange rate above to buy or sell in {second}.</Banner>}
          {lines.map((line, i) => {
            const f = figures(line);
            const unit = line.product.unit;
            const e = (field: string) => errors[`lines.${i}.${field}`];
            return (
              <View key={line.key} className="gap-3 border border-slate-200 p-3">
                <View className="flex-row items-start justify-between gap-3">
                  <View className="flex-1">
                    <Text className="text-base font-semibold text-slate-900">{line.product.label}</Text>
                    <Text className="text-xs text-slate-500">Sold by the {unit}</Text>
                  </View>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="Remove"
                    hitSlop={8}
                    onPress={() => setLines((all) => all.filter((l) => l.key !== line.key))}>
                    <Ionicons name="trash-outline" size={20} color={colors.dangerDark} />
                  </Pressable>
                </View>
                <Field
                  label="Batch number (optional)"
                  value={line.batch_number}
                  onChangeText={(t) => setLine(line.key, { batch_number: t })}
                  error={e('batch_number')}
                  autoCapitalize="characters"
                  hint="Empty: the shop numbers it"
                />
                <DateField
                  label={line.product.tracksExpiry ? 'Expires' : 'Expires (optional)'}
                  value={line.expires_on}
                  onChange={(t) => setLine(line.key, { expires_on: t })}
                  error={e('expires_on')}
                />
                <Choice
                  label="Bought by"
                  options={[
                    { value: 'unit', label: `The ${unit}` },
                    ...line.product.packs.map((pk, index) => ({ value: `pack:${index}`, label: `${pk.name} of ${pk.units}` })),
                    { value: 'other', label: 'Another box size' },
                  ]}
                  value={line.bought_by}
                  onChange={(v) => setLine(line.key, { bought_by: v })}
                  error={e('buy_unit_size')}
                />
                {line.bought_by === 'other' && (
                  <View className="flex-row gap-2">
                    <Field className="flex-1" label="Called" value={line.buy_unit} onChangeText={(t) => setLine(line.key, { buy_unit: t })} placeholder="Box" />
                    <Field
                      className="flex-1"
                      label={`${unit}s in one`}
                      value={line.buy_unit_size}
                      onChangeText={(t) => setLine(line.key, { buy_unit_size: t })}
                      keyboardType="number-pad"
                    />
                  </View>
                )}
                <Field
                  label={f.pack.size > 1 ? `How many (${f.pack.name})` : `How many ${unit}s`}
                  value={line.buy_quantity}
                  onChangeText={(t) => setLine(line.key, { buy_quantity: t })}
                  keyboardType="number-pad"
                  error={e('buy_quantity')}
                  hint={f.pack.size > 1 && f.units ? `${f.units} ${unit}s` : undefined}
                />
                <PriceField
                  label={`Buying price a ${f.pack.size > 1 ? (f.pack.name ?? 'box').toLowerCase() : unit}`}
                  options={p.pricing}
                  amount={line.buy_price}
                  currency={line.buy_currency}
                  onAmount={(t) => setLine(line.key, { buy_price: t })}
                  onCurrency={(c) => setLine(line.key, { buy_currency: c })}
                  error={e('buy_price') ?? e('buy_currency')}
                  hint={f.unitCost === null ? undefined : `One ${unit} costs ${bothCurrencies(m, f.unitCost)}`}
                />
                <PriceField
                  label={`Selling price a ${unit}`}
                  options={p.pricing}
                  amount={line.sell_price}
                  currency={line.sell_currency}
                  onAmount={(t) => setLine(line.key, { sell_price: t })}
                  onCurrency={(c) => setLine(line.key, { sell_currency: c })}
                  error={e('sell_price') ?? e('sell_currency')}
                  placeholder="The product’s price"
                />
                {(f.sell !== null || f.cost > 0) && (
                  <Text className="text-sm text-slate-600">
                    {f.sell !== null ? `Sells at ${bothCurrencies(m, f.sell)}` : ''}
                    {f.margin !== null ? (f.margin < 0 ? ' · below cost' : ` · ${f.margin}% margin`) : ''}
                    {f.cost ? `${f.sell !== null ? ' · ' : ''}line cost ${money(Math.round(f.cost))}` : ''}
                  </Text>
                )}
              </View>
            );
          })}

          <SearchBox value={search} onChange={setSearch} placeholder="Add a product: name or barcode" />
          {found.map((x) => (
            <Pressable
              key={x.id}
              accessibilityRole="button"
              onPress={() => add(x)}
              className="flex-row items-center gap-3 border border-slate-200 bg-white px-3 py-3 active:bg-brand-50">
              <Ionicons name="add-circle-outline" size={20} color={colors.brand[600]} />
              <View className="flex-1">
                <Text className="text-base font-medium text-slate-900">{x.label}</Text>
                <Text className="text-xs text-slate-500">{x.barcode ?? x.unit}</Text>
              </View>
            </Pressable>
          ))}
          {search.trim() !== '' && found.length === 0 && (
            <Pressable accessibilityRole="link" onPress={() => openPage('products/create')}>
              <Text className="text-sm text-slate-500">
                No product matches. <Text className="font-semibold text-brand-700 underline">Add it under Products</Text> first.
              </Text>
            </Pressable>
          )}
          {lines.length === 0 && search.trim() === '' && <Text className="text-sm text-slate-500">Search above for each product that came.</Text>}
        </View>
      </Panel>

      <Button title="Receive into stock" icon="checkmark" square size="lg" loading={busy} disabled={lines.length === 0} onPress={submit} />
      {router.canGoBack() && <Button title="Cancel" variant="outline" square onPress={() => router.back()} />}
    </PageScroll>
  );
}

interface ShowProps {
  receipt: {
    id: string;
    number: string;
    received_on: string;
    supplier: string | null;
    invoice_number: string | null;
    note: string | null;
    total_minor: number;
    by: string;
    created_at: string;
  };
  lines: {
    id: string;
    product: string;
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

/** A delivery received, line by line. */
export function ReceiptScreen({ page, loading, reload }: ScreenProps<ShowProps>) {
  const { receipt, lines } = page.props;
  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Card className="gap-2">
        <Text accessibilityRole="header" className="text-xl font-bold text-slate-900">
          {receipt.number}
        </Text>
        <Details
          rows={[
            ['Received', fullDate(receipt.received_on)],
            ['Supplier', receipt.supplier ?? '—'],
            ['Invoice', receipt.invoice_number ?? '—'],
            ['By', `${receipt.by}, ${dateTime(receipt.created_at)}`],
            ['Total cost', money(receipt.total_minor)],
          ]}
        />
      </Card>
      {receipt.note && <Banner tone="info">{receipt.note}</Banner>}
      <Panel title="Lines">
        {lines.map((l) => (
          <PanelRow
            key={l.id}
            title={l.product}
            subtitle={[
              `Batch ${l.batch_number}${l.expires_on ? ` · expires ${fullDate(l.expires_on)}` : ''}`,
              [l.bought, l.boughtAs].filter(Boolean).join(' · ') || null,
              `Unit cost ${money(l.unit_cost_minor)}${l.sell_price_minor === null ? '' : ` · sells at ${money(l.sell_price_minor)}`}`,
            ]
              .filter(Boolean)
              .join('\n')}
            right={money(l.total_minor)}
            rightSub={`${l.quantity} ${l.unit}`}
          />
        ))}
      </Panel>
    </PageScroll>
  );
}
