import { Ionicons } from '@expo/vector-icons';
import { randomUUID } from 'expo-crypto';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, Card, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api';
import { documentHtml, printHtml, shareHtml, type CustomerDocument } from '@/lib/documents';
import { confirm, notify } from '@/lib/feedback';
import { currencyCode, fullDate, money, toMinor } from '@/lib/format';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

import { Choice, DateField, Panel, PanelRow } from '../forms';
import { Details, FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

type Status = 'open' | 'invoiced' | 'cancelled';

const STATUS: Record<Status, { label: string; tone: 'blue' | 'green' | 'neutral' }> = {
  open: { label: 'Open', tone: 'blue' },
  invoiced: { label: 'Invoiced', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

interface Row {
  id: string;
  number: string;
  customer: string;
  status: Status;
  issuedOn: string;
  validUntil: string;
  expired: boolean;
  lines: number;
  totalMinor: number;
}

const FILTERS = [
  { value: 'open', label: 'Open' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'all', label: 'All' },
];

/** Prices offered to customers: sent as a PDF, invoiced when they take them. */
export function QuotationsScreen({ page, loading, reload, setQuery }: ScreenProps<{ quotations: Row[]; filters: { status: string } }>) {
  const p = page.props;
  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Button title="New quotation" icon="add" square onPress={() => openPage('quotations/create')} />
      <FilterChips options={FILTERS} value={p.filters.status} onChange={(status) => setQuery({ status })} />
      {p.quotations.length === 0 ? (
        <EmptyState icon="document-text-outline" title="No quotations" message="Quote a customer prices for what they ask, and share it as a PDF." />
      ) : (
        <Panel>
          {p.quotations.map((q) => (
            <PanelRow
              key={q.id}
              title={`${q.number} · ${q.customer}`}
              subtitle={`${fullDate(q.issuedOn)} · ${q.lines} line(s) · ${q.expired ? 'expired' : `valid to ${fullDate(q.validUntil)}`}`}
              right={money(q.totalMinor)}
              onPress={() => openPage(`quotations/${q.id}`)}>
              <View className="flex-row gap-1.5">
                <Badge label={STATUS[q.status].label} tone={STATUS[q.status].tone} />
                {q.expired && <Badge label="Expired" tone="amber" />}
              </View>
            </PanelRow>
          ))}
        </Panel>
      )}
    </PageScroll>
  );
}

interface ProductOption {
  id: string;
  name: string;
  unit: string;
  barcode: string | null;
  price: string;
  priceMinor: number;
  packs: { id: string; name: string; units: number; price: string; priceMinor: number }[];
}

interface FormProps {
  quotation: {
    id: string;
    number: string;
    customerId: string;
    validUntil: string;
    note: string;
    lines: { productId: string; name: string; packId: string | null; quantity: string; price: string; discountPercent: string }[];
  } | null;
  /** Started from a customer's page. */
  customerId: string;
  customers: { id: string; number: string; name: string; phone: string | null }[];
  products: ProductOption[];
  validUntil: string;
  canSetPrice: boolean;
  maxDiscount: number;
}

interface Line {
  key: string;
  product: ProductOption | null;
  name: string;
  packId: string | null;
  quantity: string;
  price: string;
  discountPercent: string;
}

/** Writing a quotation: the customer, then what they ask for at the shop's prices (a supervisor may quote others). */
export function QuotationFormScreen({ page, path, reload, loading }: ScreenProps<FormProps>) {
  const p = page.props;
  const q = p.quotation;
  const { run, busy, errors } = useAction(path, reload);
  const [customerId, setCustomerId] = useState(q?.customerId ?? p.customerId);
  const [validUntil, setValidUntil] = useState(q?.validUntil ?? p.validUntil);
  const [note, setNote] = useState(q?.note ?? '');
  const [lines, setLines] = useState<Line[]>(
    () =>
      q?.lines.map((l) => ({
        key: randomUUID(),
        product: p.products.find((x) => x.id === l.productId) ?? null,
        name: l.name,
        packId: l.packId,
        quantity: l.quantity,
        price: l.price,
        discountPercent: l.discountPercent,
      })) ?? [],
  );
  const [search, setSearch] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');
  const customer = p.customers.find((c) => c.id === customerId) ?? null;

  const found = useMemo(() => {
    const s = search.trim().toLowerCase();
    if (!s) return [];
    return p.products.filter((x) => x.name.toLowerCase().includes(s) || x.barcode === search.trim()).slice(0, 8);
  }, [search, p.products]);
  const foundCustomers = useMemo(() => {
    const s = customerSearch.trim().toLowerCase();
    const all = s ? p.customers.filter((c) => c.name.toLowerCase().includes(s) || (c.phone ?? '').includes(s) || c.number.toLowerCase() === s) : p.customers;
    return all.slice(0, 8);
  }, [customerSearch, p.customers]);

  const setLine = (key: string, patch: Partial<Line>) => setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const add = (product: ProductOption) => {
    setLines((all) => [...all, { key: randomUUID(), product, name: product.name, packId: null, quantity: '1', price: product.price, discountPercent: '' }]);
    setSearch('');
  };
  const figures = (l: Line) => {
    const price = toMinor(l.price) ?? 0;
    const gross = price * (Math.floor(Number(l.quantity)) || 0);
    const discount = Math.round((gross * (Number(l.discountPercent) || 0)) / 100);
    return { gross, discount, total: gross - discount };
  };
  const total = lines.reduce((sum, l) => sum + figures(l).total, 0);

  const submit = () =>
    void run(q ? 'PUT' : 'POST', q ? `quotations/${q.id}` : 'quotations', {
      customer_id: customerId,
      valid_until: validUntil,
      note,
      lines: lines.map((l) => ({
        product_id: l.product?.id ?? '',
        pack_id: l.packId,
        quantity: l.quantity,
        price: l.price,
        discount_percent: l.discountPercent,
      })),
    });

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
        {q ? `Edit ${q.number}` : 'New quotation'}
      </Text>

      <Panel title="For">
        <View className="gap-3 p-4">
          {customer ? (
            <View className="flex-row items-center gap-3">
              <View className="flex-1">
                <Text className="text-base font-semibold text-slate-900">{customer.name}</Text>
                <Text className="text-sm text-slate-500">{[customer.number, customer.phone].filter(Boolean).join(' · ')}</Text>
              </View>
              <Button title="Change" variant="outline" square onPress={() => setCustomerId('')} />
            </View>
          ) : (
            <>
              {errors.customer_id && <Banner tone="error">{errors.customer_id}</Banner>}
              <SearchBox value={customerSearch} onChange={setCustomerSearch} placeholder="Customer: name, phone or number" />
              {foundCustomers.map((c) => (
                <Pressable
                  key={c.id}
                  accessibilityRole="button"
                  onPress={() => setCustomerId(c.id)}
                  className="flex-row items-center gap-3 border border-slate-200 bg-white px-3 py-3 active:bg-brand-50">
                  <Ionicons name="person-outline" size={18} color={colors.brand[600]} />
                  <Text className="flex-1 text-base text-slate-900">{c.name}</Text>
                  <Text className="text-xs text-slate-500">{c.phone ?? c.number}</Text>
                </Pressable>
              ))}
              <Pressable accessibilityRole="link" onPress={() => openPage('customers')}>
                <Text className="text-sm text-slate-500">
                  Not on the list? <Text className="font-semibold text-brand-700 underline">Add them under Customers</Text>.
                </Text>
              </Pressable>
            </>
          )}
          <DateField label="Valid until" value={validUntil} onChange={setValidUntil} error={errors.valid_until} />
          <Field label="Note (optional)" value={note} onChangeText={setNote} hint="Delivery, terms: printed on the quotation" multiline />
        </View>
      </Panel>

      <Panel title="Items" right={<Text className="text-sm font-bold text-slate-900">{money(total)}</Text>}>
        <View className="gap-3 p-4">
          {errors.lines && <Banner tone="error">{errors.lines}</Banner>}
          {lines.map((line, i) => {
            const e = (field: string) => errors[`lines.${i}.${field}`];
            const product = line.product;
            const pack = product?.packs.find((k) => k.id === line.packId) ?? null;
            const f = figures(line);
            return (
              <View key={line.key} className="gap-3 border border-slate-200 p-3">
                <View className="flex-row items-start justify-between gap-3">
                  <Text className="flex-1 text-base font-semibold text-slate-900">{line.name}</Text>
                  <Pressable accessibilityRole="button" accessibilityLabel="Remove" hitSlop={8} onPress={() => setLines((all) => all.filter((l) => l.key !== line.key))}>
                    <Ionicons name="trash-outline" size={20} color={colors.dangerDark} />
                  </Pressable>
                </View>
                {!product && <Banner tone="warning">No longer sold: remove this line.</Banner>}
                {product && product.packs.length > 0 && (
                  <Choice
                    label="By"
                    options={[{ value: '', label: `The ${product.unit}` }, ...product.packs.map((k) => ({ value: k.id, label: `${k.name} of ${k.units}` }))]}
                    value={line.packId ?? ''}
                    onChange={(v) => {
                      const next = product.packs.find((k) => k.id === v) ?? null;
                      setLine(line.key, { packId: next?.id ?? null, price: next?.price ?? product.price });
                    }}
                    error={e('pack_id')}
                  />
                )}
                <View className="flex-row gap-2">
                  <Field
                    className="flex-1"
                    label={pack ? `${pack.name}s` : 'Quantity'}
                    value={line.quantity}
                    onChangeText={(t) => setLine(line.key, { quantity: t })}
                    keyboardType="number-pad"
                    error={e('quantity')}
                  />
                  {p.maxDiscount > 0 && (
                    <Field
                      className="flex-1"
                      label="Discount %"
                      value={line.discountPercent}
                      onChangeText={(t) => setLine(line.key, { discountPercent: t })}
                      keyboardType="decimal-pad"
                      error={e('discount_percent')}
                      hint={`Up to ${p.maxDiscount}%`}
                    />
                  )}
                </View>
                {p.canSetPrice ? (
                  <Field
                    label={`Price a ${pack ? pack.name.toLowerCase() : (product?.unit ?? 'unit')}`}
                    prefix={currencyCode()}
                    value={line.price}
                    onChangeText={(t) => setLine(line.key, { price: t })}
                    keyboardType="decimal-pad"
                    error={e('price')}
                  />
                ) : (
                  <Text className="text-sm text-slate-600">
                    {money(toMinor(line.price) ?? 0)} a {pack ? pack.name.toLowerCase() : (product?.unit ?? 'unit')}
                    {e('price') ? <Text className="text-red-700"> · {e('price')}</Text> : null}
                  </Text>
                )}
                <Text className="text-right text-sm font-semibold text-slate-900">
                  {f.discount ? `${money(f.gross)} less ${money(f.discount)} = ` : ''}
                  {money(f.total)}
                </Text>
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
                <Text className="text-base font-medium text-slate-900">{x.name}</Text>
                <Text className="text-xs text-slate-500">
                  {money(x.priceMinor)} a {x.unit}
                </Text>
              </View>
            </Pressable>
          ))}
          {lines.length === 0 && search.trim() === '' && <Text className="text-sm text-slate-500">Search above for each product quoted.</Text>}
        </View>
      </Panel>

      <Button title={q ? 'Save quotation' : 'Make quotation'} icon="checkmark" square size="lg" loading={busy} disabled={lines.length === 0} onPress={submit} />
      {router.canGoBack() && <Button title="Cancel" variant="outline" square onPress={() => router.back()} />}
    </PageScroll>
  );
}

interface ShowProps {
  quotation: {
    id: string;
    number: string;
    status: Status;
    issuedOn: string;
    validUntil: string;
    expired: boolean;
    note: string | null;
    totalMinor: number;
    discountMinor: number;
    by: string;
    sale: { id: string; number: string } | null;
  };
  customer: { id: string; number: string; name: string; phone: string | null; email: string | null; address: string | null; on_account: boolean; balance_minor: number; available_minor: number | null };
  lines: { id: string; name: string; pack: string | null; quantity: number; unitPriceMinor: number; discountPercent: number; discountMinor: number; totalMinor: number }[];
  canEdit: boolean;
  canInvoice: boolean;
}

/** A quotation: share it, and invoice it when the customer takes the goods. */
export function QuotationScreen({ page, path, loading, reload }: ScreenProps<ShowProps>) {
  const { quotation: q, customer: c, lines, canEdit, canInvoice } = page.props;
  const action = useAction(path, reload);
  const shop = useAuth((s) => s.counter?.receipt);

  const doc = (): CustomerDocument => ({
    title: 'Quotation',
    number: q.number,
    facts: [
      ['Date', fullDate(q.issuedOn)],
      ['Valid until', fullDate(q.validUntil)],
    ],
    to: { name: c.name, number: c.number, phone: c.phone, email: c.email, address: c.address },
    lines: lines.map((l) => ({
      name: l.name,
      detail: [l.pack, l.discountMinor ? `${l.discountPercent}% off, less ${money(l.discountMinor)}` : null].filter(Boolean).join(' · ') || null,
      quantity: l.quantity,
      unitPriceMinor: l.unitPriceMinor,
      totalMinor: l.totalMinor,
    })),
    totals: [['Total', q.totalMinor]],
    note: q.note,
    closing: `Prices held until ${fullDate(q.validUntil)}, subject to stock. Prepared by ${q.by}.`,
  });

  const output = async (kind: 'share' | 'print') => {
    if (!shop) return;
    try {
      const html = documentHtml(shop, doc());
      if (kind === 'share') await shareHtml(html, `Quotation ${q.number}`);
      else await printHtml(html);
    } catch (e) {
      notify('Could not complete', errorMessage(e));
    }
  };

  const invoice = async () => {
    const ok = await confirm(
      'Invoice this quotation?',
      `${money(q.totalMinor)} goes on ${c.name}’s account and the stock leaves now.`,
      'Invoice',
    );
    if (ok) await action.run('POST', `quotations/${q.id}/invoice`, { submission_id: randomUUID() });
  };

  const cancel = async () => {
    if (await confirm('Cancel this quotation?', 'It can no longer be invoiced.', 'Cancel quotation', true)) await action.run('POST', `quotations/${q.id}/cancel`);
  };

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Card className="gap-2">
        <View className="flex-row items-start justify-between gap-3">
          <Text accessibilityRole="header" className="flex-1 text-xl font-bold text-slate-900">
            {q.number}
          </Text>
          <View className="flex-row gap-1.5">
            <Badge label={STATUS[q.status].label} tone={STATUS[q.status].tone} />
            {q.expired && <Badge label="Expired" tone="amber" />}
          </View>
        </View>
        <Details
          rows={[
            ['Customer', c.name],
            ['Date', fullDate(q.issuedOn)],
            ['Valid until', fullDate(q.validUntil)],
            ['By', q.by],
            ['Total', money(q.totalMinor)],
          ]}
        />
      </Card>
      {q.note && <Banner tone="info">{q.note}</Banner>}
      {q.expired && canEdit && <Banner tone="warning">It has expired. Edit it to give a new date before invoicing.</Banner>}
      {q.status === 'open' && !q.expired && !c.on_account && (
        <Banner tone="warning">{c.name} does not buy on account: allow it under Customers to invoice this, or sell it at the counter.</Banner>
      )}
      {q.status === 'open' && c.on_account && c.available_minor !== null && q.totalMinor > c.available_minor && (
        <Banner tone="warning">Over {c.name}’s credit: {money(c.available_minor)} is left.</Banner>
      )}

      <View className="flex-row flex-wrap gap-2">
        <Button title="Share PDF" icon="share-outline" square onPress={() => output('share')} />
        <Button title="Print" icon="print-outline" variant="outline" square onPress={() => output('print')} />
        {canInvoice && <Button title="Invoice it" icon="receipt-outline" variant="accent" square loading={action.busy} onPress={invoice} />}
        {q.sale && <Button title={`Open ${q.sale.number}`} icon="open-outline" variant="outline" square onPress={() => router.push({ pathname: '/receipt/[id]', params: { id: q.sale!.id } })} />}
        {canEdit && <Button title="Edit" icon="create-outline" variant="outline" square onPress={() => openPage(`quotations/${q.id}/edit`)} />}
        {canEdit && <Button title="Cancel quotation" icon="close-circle-outline" variant="outline" square onPress={cancel} />}
      </View>

      <Panel title="Items">
        {lines.map((l) => (
          <PanelRow
            key={l.id}
            title={l.name}
            subtitle={[
              `${l.quantity} × ${money(l.unitPriceMinor)}${l.pack ? ` (${l.pack})` : ''}`,
              l.discountMinor ? `${l.discountPercent}% off: less ${money(l.discountMinor)}` : null,
            ]
              .filter(Boolean)
              .join('\n')}
            right={money(l.totalMinor)}
          />
        ))}
      </Panel>
    </PageScroll>
  );
}
