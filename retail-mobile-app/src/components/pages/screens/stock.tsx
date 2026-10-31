import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { dateTime, fullDate, inSecond, money } from '@/lib/format';

import {
  bothCurrencies,
  Choice,
  DateField,
  enteredInBase,
  FormSheet,
  marginOf,
  moneyAt,
  Muted,
  Panel,
  PanelRow,
  PriceField,
  RowActions,
  SmallButton,
  type Action,
  type PricingOptions,
} from '../forms';
import { FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

interface Row {
  id: string;
  label: string;
  unit: string;
  sellable: number;
  expired: number;
  held: number;
  nextExpiry: string | null;
  low: boolean;
  expiringSoon: boolean;
  reorderLevel: number;
}

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'low', label: 'Low' },
  { value: 'expiring', label: 'Expiring soon' },
  { value: 'expired', label: 'Expired' },
  { value: 'held', label: 'Held back' },
];

/** The shop's stock, product by product. */
export function StockScreen({
  page,
  loading,
  reload,
  setQuery,
}: ScreenProps<{ rows: Row[]; filters: { search: string; filter: string }; expiringDays: number; canReceive: boolean }>) {
  const { rows, filters, expiringDays, canReceive } = page.props;
  const [search, setSearch] = useState(filters.search);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">Stock on hand · expiring soon is within {expiringDays} days</Text>
      <View className="flex-row gap-2">
        <Button title="Batches" variant="outline" icon="layers-outline" square className="flex-1" onPress={() => openPage('batches')} />
        {canReceive && <Button title="Receive stock" icon="download-outline" square className="flex-1" onPress={() => openPage('receipts/create')} />}
      </View>
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ search: q })} placeholder="Search products" />
      <FilterChips options={FILTERS} value={filters.filter || 'all'} onChange={(filter) => setQuery({ filter, search })} />
      {rows.length === 0 ? (
        <EmptyState icon="cube-outline" title="Nothing here" />
      ) : (
        <Panel>
          {rows.map((r) => (
            <PanelRow
              key={r.id}
              title={r.label}
              subtitle={[
                r.nextExpiry ? `Next expiry ${fullDate(r.nextExpiry)}` : null,
                r.held ? `${r.held} held back` : null,
                r.expired ? `${r.expired} expired` : null,
                `reorder at ${r.reorderLevel}`,
              ]
                .filter(Boolean)
                .join(' · ')}
              right={`${r.sellable} ${r.unit}`}
              rightSub={r.low ? 'Low' : r.expiringSoon ? 'Expiring soon' : r.expired ? 'Has expired stock' : null}
              tone={r.expired ? 'red' : r.low || r.expiringSoon ? 'amber' : undefined}
              onPress={() => openPage(`stock/${r.id}`)}
            />
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}

interface BatchRow {
  id: string;
  batchNumber: string;
  expiresOn: string | null;
  expired: boolean;
  quantity: number;
  unitCostMinor: number;
  sellPriceMinor: number;
  /** Priced on its own; otherwise at the product's price. */
  ownPrice: boolean;
  boughtAs: string | null;
  status: 'available' | 'quarantined' | 'recalled';
  holdReason: string | null;
}

interface ShowProps {
  product: { id: string; label: string; unit: string; reorderLevel: number; tracksExpiry: boolean };
  batches: BatchRow[];
  movements: { id: string; at: string; type: string; quantity: number; batchNumber: string; reason: string | null; note: string | null; by: string }[];
  pendingDisposals: { id: string; number: string; batchNumber: string; quantity: number; reason: string; requestedBy: string }[];
  reasons: { value: string; label: string }[];
  disposalReasons: { value: string; label: string }[];
  canAdjust: boolean;
  canHold: boolean;
  listPriceMinor: number;
  packs: { name: string; units: number }[];
  pricing: PricingOptions;
  canPrice: boolean;
}

type Dialog = { kind: 'opening' } | { kind: 'adjust' | 'dispose' | 'hold' | 'price'; batch: BatchRow };

/** One product's stock, batch by batch, with what can be done to each. */
export function StockItemScreen({ page, path, reload, loading }: ScreenProps<ShowProps>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const close = () => setDialog(null);
  const second = inSecond(p.listPriceMinor);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <View className="gap-1">
        <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
          {p.product.label}
        </Text>
        <Text className="text-sm text-slate-500">
          Sells at {money(p.listPriceMinor)} a {p.product.unit}
          {second ? ` (${second})` : ''} unless a batch has its own price · reorder at {p.product.reorderLevel}
        </Text>
      </View>
      {p.canAdjust && <Button title="Add stock" icon="add" square onPress={() => setDialog({ kind: 'opening' })} />}

      {p.pendingDisposals.length > 0 && (
        <Banner tone="warning">
          Waiting for approval to dispose:{' '}
          {p.pendingDisposals.map((d) => `${d.number} (${d.quantity} of ${d.batchNumber}, ${d.reason.toLowerCase()}, by ${d.requestedBy})`).join('; ')}
        </Banner>
      )}

      <Panel title="Batches, soonest to expire first">
        {p.batches.length === 0 ? (
          <Muted>No stock here.</Muted>
        ) : (
          p.batches.map((b) => {
            const margin = marginOf(b.unitCostMinor, b.sellPriceMinor);
            const live = b.quantity > 0;
            return (
              <PanelRow
                key={b.id}
                dim={!live}
                title={`Batch ${b.batchNumber}`}
                subtitle={[
                  b.expiresOn ? `Expires ${fullDate(b.expiresOn)}` : 'No expiry',
                  `cost ${money(b.unitCostMinor)}${b.boughtAs ? ` (${b.boughtAs})` : ''}`,
                  `sells at ${money(b.sellPriceMinor)} (${b.ownPrice ? 'this batch' : 'the product’s'}) · margin ${margin.text}`,
                ].join('\n')}
                right={`${b.quantity} on hand`}
                tone={b.expired ? 'red' : undefined}>
                {(b.expired || b.status !== 'available') && (
                  <View className="flex-row flex-wrap gap-1">
                    {b.expired && <Badge label="Expired" tone="red" />}
                    {b.status !== 'available' && (
                      <Badge label={`${b.status === 'recalled' ? 'Recalled' : 'Quarantined'}${b.holdReason ? `: ${b.holdReason}` : ''}`} tone="amber" />
                    )}
                  </View>
                )}
                {live && (p.canPrice || p.canAdjust || p.canHold) && (
                  <RowActions>
                    {p.canPrice && <SmallButton title="Price" onPress={() => setDialog({ kind: 'price', batch: b })} />}
                    {p.canAdjust && <SmallButton title="Adjust" onPress={() => setDialog({ kind: 'adjust', batch: b })} />}
                    {p.canAdjust && <SmallButton title="Dispose" tone="red" onPress={() => setDialog({ kind: 'dispose', batch: b })} />}
                    {p.canHold &&
                      (b.status === 'available' ? (
                        <SmallButton title="Hold back" onPress={() => setDialog({ kind: 'hold', batch: b })} />
                      ) : (
                        <SmallButton title="Release" disabled={action.busy} onPress={() => void action.run('POST', `batches/${b.id}/release`)} />
                      ))}
                  </RowActions>
                )}
              </PanelRow>
            );
          })
        )}
      </Panel>

      <Panel title="Latest movements">
        {p.movements.length === 0 ? (
          <Muted>None yet.</Muted>
        ) : (
          p.movements.map((m) => (
            <PanelRow
              key={m.id}
              title={m.type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())}
              subtitle={[`Batch ${m.batchNumber}`, [m.reason, m.note].filter(Boolean).join(': '), `${m.by}, ${dateTime(m.at)}`].filter(Boolean).join(' · ')}
              right={m.quantity > 0 ? `+${m.quantity}` : String(m.quantity)}
              tone={m.quantity < 0 ? 'red' : 'green'}
            />
          ))
        )}
      </Panel>

      {dialog?.kind === 'opening' && <OpeningSheet p={p} action={action} onClose={close} />}
      {dialog?.kind === 'price' && <PriceSheet p={p} batch={dialog.batch} action={action} onClose={close} />}

      {dialog?.kind === 'adjust' && (
        <FormSheet
          title={`Adjust batch ${dialog.batch.batchNumber}`}
          submit="Adjust"
          action={action}
          onClose={close}
          to={`batches/${dialog.batch.id}/adjustments`}
          initial={{ change: '', reason: p.reasons[0]?.value ?? '', note: '' }}>
          {(data, set, errors) => (
            <>
              <Field
                label="Change"
                value={data.change}
                onChangeText={(t) => set('change', t)}
                keyboardType="numbers-and-punctuation"
                error={errors.change}
                hint={`On hand now ${dialog.batch.quantity}. -3 takes three off, 5 adds five. Damaged or expired stock is disposed of instead.`}
                autoFocus
              />
              <Choice label="Why" options={p.reasons} value={data.reason} onChange={(v) => set('reason', v)} error={errors.reason} />
              <Field label="Note" value={data.note} onChangeText={(t) => set('note', t)} error={errors.note} />
            </>
          )}
        </FormSheet>
      )}

      {dialog?.kind === 'dispose' && (
        <FormSheet
          title={`Dispose of batch ${dialog.batch.batchNumber}`}
          submit="Dispose"
          danger
          action={action}
          onClose={close}
          to={`batches/${dialog.batch.id}/disposals`}
          initial={{ quantity: String(dialog.batch.quantity), reason: p.disposalReasons[0]?.value ?? '', note: '' }}>
          {(data, set, errors) => (
            <>
              <Banner tone="info">The stock leaves now and is recorded in the batch’s movements.</Banner>
              <Field label="Quantity" value={data.quantity} onChangeText={(t) => set('quantity', t)} keyboardType="number-pad" error={errors.quantity} />
              <Choice label="Why" options={p.disposalReasons} value={data.reason} onChange={(v) => set('reason', v)} error={errors.reason} />
              <Field label="Note" value={data.note} onChangeText={(t) => set('note', t)} error={errors.note} />
            </>
          )}
        </FormSheet>
      )}

      {dialog?.kind === 'hold' && (
        <FormSheet
          title={`Hold back batch ${dialog.batch.batchNumber}`}
          submit="Hold back"
          action={action}
          onClose={close}
          to={`batches/${dialog.batch.id}/hold`}
          initial={{ status: 'quarantined', reason: '' }}>
          {(data, set, errors) => (
            <>
              <Choice
                label="As"
                options={[
                  { value: 'quarantined', label: 'Quarantined (checking it)' },
                  { value: 'recalled', label: 'Recalled (by the maker or regulator)' },
                ]}
                value={data.status}
                onChange={(v) => set('status', v)}
                error={errors.status}
              />
              <Field label="Why" value={data.reason} onChangeText={(t) => set('reason', t)} error={errors.reason} autoFocus />
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
  );
}

/** Stock already on the shelf (or found in a count), priced like a delivery. */
function OpeningSheet({ p, action, onClose }: { p: ShowProps; action: Action; onClose: () => void }) {
  const m = moneyAt(p.pricing, p.pricing.rate);
  const unit = p.product.unit;
  const size = (d: Record<string, string>) => (d.bought_by === 'unit' ? 1 : (p.packs[Number(d.bought_by)]?.units ?? 1));
  return (
    <FormSheet
      title="Add stock"
      submit="Add"
      action={action}
      onClose={onClose}
      to={`stock/${p.product.id}/opening`}
      initial={{
        batch_number: '',
        expires_on: '',
        bought_by: 'unit',
        buy_quantity: '',
        buy_price: '',
        buy_currency: p.pricing.currency,
        sell_price: '',
        sell_currency: p.pricing.currency,
      }}
      clean={(d) => ({ ...d, buy_unit: d.bought_by === 'unit' ? null : p.packs[Number(d.bought_by)]?.name, buy_unit_size: size(d) })}>
      {(data, set, errors) => {
        const packCost = enteredInBase(m, data.buy_price, data.buy_currency);
        const each = size(data);
        const count = Number(data.buy_quantity);
        return (
          <>
            <Banner tone="info">Stock already on the shelf, or found in a count. Deliveries go under Receive stock.</Banner>
            <Field label="Batch number (optional)" value={data.batch_number} onChangeText={(t) => set('batch_number', t)} error={errors.batch_number} autoCapitalize="characters" />
            <DateField
              label={p.product.tracksExpiry ? 'Expires on' : 'Expires on (optional)'}
              value={data.expires_on}
              onChange={(t) => set('expires_on', t)}
              error={errors.expires_on}
            />
            <Choice
              label="Counted by"
              options={[{ value: 'unit', label: `The ${unit}` }, ...p.packs.map((pk, i) => ({ value: String(i), label: `${pk.name} of ${pk.units}` }))]}
              value={data.bought_by}
              onChange={(v) => set('bought_by', v)}
              error={errors.buy_unit_size}
            />
            <Field
              label="How many"
              value={data.buy_quantity}
              onChangeText={(t) => set('buy_quantity', t)}
              keyboardType="number-pad"
              error={errors.buy_quantity}
              hint={each > 1 && count ? `${count * each} ${unit}s` : undefined}
            />
            <PriceField
              label={`Buying price a ${each > 1 ? (p.packs[Number(data.bought_by)]?.name ?? 'pack').toLowerCase() : unit}`}
              options={p.pricing}
              amount={data.buy_price}
              currency={data.buy_currency}
              onAmount={(t) => set('buy_price', t)}
              onCurrency={(c) => set('buy_currency', c)}
              error={errors.buy_price ?? errors.buy_currency}
              hint={packCost !== null ? `One ${unit} costs ${bothCurrencies(m, packCost / each)}` : undefined}
            />
            <PriceField
              label={`Selling price a ${unit}`}
              options={p.pricing}
              amount={data.sell_price}
              currency={data.sell_currency}
              onAmount={(t) => set('sell_price', t)}
              onCurrency={(c) => set('sell_currency', c)}
              error={errors.sell_price ?? errors.sell_currency}
              hint={`Empty: the product’s price, ${money(p.listPriceMinor)}`}
            />
          </>
        );
      }}
    </FormSheet>
  );
}

/** A batch's own selling price, or the product's again. */
function PriceSheet({ p, batch, action, onClose }: { p: ShowProps; batch: BatchRow; action: Action; onClose: () => void }) {
  const m = moneyAt(p.pricing, p.pricing.rate);
  return (
    <FormSheet
      title={`Price of batch ${batch.batchNumber}`}
      submit="Save price"
      action={action}
      onClose={onClose}
      to={`batches/${batch.id}/price`}
      initial={{
        sell_price: batch.ownPrice ? (batch.sellPriceMinor / 10 ** p.pricing.decimals).toFixed(p.pricing.decimals) : '',
        sell_currency: p.pricing.currency,
      }}>
      {(data, set, errors) => {
        const price = enteredInBase(m, data.sell_price, data.sell_currency);
        return (
          <>
            <Text className="text-sm text-slate-500">
              A {p.product.unit} of this batch cost {money(batch.unitCostMinor)}. Empty: it sells at the product’s price, {money(p.listPriceMinor)}.
            </Text>
            <PriceField
              label={`Selling price a ${p.product.unit}`}
              options={p.pricing}
              amount={data.sell_price}
              currency={data.sell_currency}
              onAmount={(t) => set('sell_price', t)}
              onCurrency={(c) => set('sell_currency', c)}
              error={errors.sell_price ?? errors.sell_currency}
              hint={price !== null ? `Sells at ${bothCurrencies(m, price)} · margin ${marginOf(batch.unitCostMinor, price).text}` : undefined}
            />
          </>
        );
      }}
    </FormSheet>
  );
}
