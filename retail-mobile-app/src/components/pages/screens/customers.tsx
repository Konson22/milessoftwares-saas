import { randomUUID } from 'expo-crypto';
import { router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, Card, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api';
import { shareHtml, statementHtml } from '@/lib/documents';
import { notify } from '@/lib/feedback';
import { currencyCode, dateTime, fullDate, money, toMajorInput } from '@/lib/format';
import { useAuth } from '@/store/auth';

import { Choice, FormSheet, Panel, PanelRow, RowActions, SmallButton, Toggle } from '../forms';
import { Details, FilterChips, PageScroll, SearchBox } from '../kit';
import type { ScreenProps } from '../types';

interface CustomerRow {
  id: string;
  number: string;
  name: string;
  phone: string | null;
  onAccount: boolean;
  creditLimitMinor: number | null;
  balanceMinor: number;
  active: boolean;
}

interface ListProps {
  customers: CustomerRow[];
  filters: { search: string; filter: string };
  owedMinor: number;
  overdueMinor: number;
  canManage: boolean;
}

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'owing', label: 'Owing' },
  { value: 'inactive', label: 'With inactive' },
];

/** The shop's customers: who buys on account, and what each owes. */
export function CustomersScreen({ page, path, loading, reload, setQuery }: ScreenProps<ListProps>) {
  const p = page.props;
  const action = useAction(path, reload);
  const [search, setSearch] = useState(p.filters.search);
  const [adding, setAdding] = useState(false);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <View className="flex-row flex-wrap gap-3">
        <Card className="min-w-[45%] flex-1 gap-0.5">
          <Text className="text-xs text-slate-500">Owed by customers</Text>
          <Text className="text-lg font-bold text-slate-900">{money(p.owedMinor)}</Text>
        </Card>
        <Card className="min-w-[45%] flex-1 gap-0.5">
          <Text className="text-xs text-slate-500">Of it overdue</Text>
          <Text className={`text-lg font-bold ${p.overdueMinor ? 'text-red-700' : 'text-slate-900'}`}>{money(p.overdueMinor)}</Text>
        </Card>
      </View>
      <Button title="Add customer" icon="person-add-outline" square onPress={() => setAdding(true)} />
      <SearchBox value={search} onChange={setSearch} onSubmit={(q) => setQuery({ search: q, filter: p.filters.filter || null })} placeholder="Name, phone or number" />
      <FilterChips options={FILTERS} value={p.filters.filter} onChange={(filter) => setQuery({ search, filter: filter || null })} />
      {p.customers.length === 0 ? (
        <EmptyState icon="people-outline" title="No customers found" message="Add the customers who buy on account, or whom you send quotations." />
      ) : (
        <Panel>
          {p.customers.map((c) => (
            <PanelRow
              key={c.id}
              dim={!c.active}
              title={c.name}
              subtitle={[
                c.number,
                c.phone,
                c.onAccount ? (c.creditLimitMinor === null ? 'on account' : `limit ${money(c.creditLimitMinor)}`) : 'pays at once',
              ]
                .filter(Boolean)
                .join(' · ')}
              right={c.balanceMinor ? money(c.balanceMinor) : null}
              rightSub={c.balanceMinor > 0 ? 'owes' : c.balanceMinor < 0 ? 'in credit' : null}
              tone={c.balanceMinor > 0 ? 'amber' : undefined}
              onPress={() => openPage(`customers/${c.id}`)}
            />
          ))}
        </Panel>
      )}

      {adding && (
        <CustomerSheet
          title="Add a customer"
          to="customers"
          method="POST"
          action={action}
          canManage={p.canManage}
          initial={null}
          onClose={() => setAdding(false)}
        />
      )}
    </PageScroll>
  );
}

interface CustomerDetails {
  id: string;
  number: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  note: string | null;
  onAccount: boolean;
  creditLimit: string;
  creditLimitMinor: number | null;
  active: boolean;
  since: string;
}

/** Adding or changing a customer; buying on account and the limit are a supervisor's to set. */
function CustomerSheet({
  title,
  to,
  method,
  action,
  canManage,
  initial,
  onClose,
}: {
  title: string;
  to: string;
  method: 'POST' | 'PUT';
  action: ReturnType<typeof useAction>;
  canManage: boolean;
  initial: CustomerDetails | null;
  onClose: () => void;
}) {
  return (
    <FormSheet
      title={title}
      submit="Save"
      method={method}
      to={to}
      action={action}
      onClose={onClose}
      initial={{
        name: initial?.name ?? '',
        phone: initial?.phone ?? '',
        email: initial?.email ?? '',
        address: initial?.address ?? '',
        note: initial?.note ?? '',
        on_account: initial?.onAccount ? '1' : '',
        credit_limit: initial?.creditLimit ?? '',
        active: initial && !initial.active ? '' : '1',
      }}
      clean={(d) => ({ ...d, on_account: d.on_account === '1', active: d.active === '1' })}>
      {(data, set, errors) => (
        <>
          <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} autoCapitalize="words" />
          <Field label="Phone" value={data.phone} onChangeText={(t) => set('phone', t)} error={errors.phone} keyboardType="phone-pad" />
          <Field label="Email" value={data.email} onChangeText={(t) => set('email', t)} error={errors.email} keyboardType="email-address" autoCapitalize="none" />
          <Field label="Address" value={data.address} onChangeText={(t) => set('address', t)} error={errors.address} />
          <Field label="Note" value={data.note} onChangeText={(t) => set('note', t)} error={errors.note} />
          {canManage ? (
            <>
              <Toggle label="Buys on account" hint="Takes goods now and pays later, on an invoice" value={data.on_account === '1'} onChange={(on) => set('on_account', on ? '1' : '')} />
              {data.on_account === '1' && (
                <Field
                  label="Credit limit"
                  prefix={currencyCode()}
                  value={data.credit_limit}
                  onChangeText={(t) => set('credit_limit', t)}
                  error={errors.credit_limit}
                  keyboardType="decimal-pad"
                  hint="The most they may owe. Empty: no limit."
                />
              )}
            </>
          ) : (
            <Text className="text-sm text-slate-500">A supervisor or manager decides who buys on account, and up to how much.</Text>
          )}
          {initial && <Toggle label="Active" value={data.active === '1'} onChange={(on) => set('active', on ? '1' : '')} />}
        </>
      )}
    </FormSheet>
  );
}

interface Invoice {
  id: string;
  number: string;
  sold_at: string;
  due_on: string | null;
  total_minor: number;
  returned_minor: number;
  net_minor: number;
  paid_minor: number;
  owing_minor: number;
  status: 'paid' | 'part_paid' | 'unpaid';
  overdue: boolean;
}

interface Payment {
  id: string;
  number: string;
  amountMinor: number;
  method: string;
  reference: string | null;
  note: string | null;
  paidAt: string;
  by: string;
  voided: boolean;
  voidReason: string | null;
  canVoid: boolean;
}

interface ShowProps {
  customer: CustomerDetails;
  balanceMinor: number;
  availableMinor: number | null;
  invoices: Invoice[];
  otherSales: { id: string; number: string; sold_at: string; total_minor: number; status: string; method: string }[];
  payments: Payment[];
  quotations: { id: string; number: string; status: string; issued_on: string; total_minor: number }[];
  paymentMethods: { value: string; label: string }[];
  canManage: boolean;
  canTakePayment: boolean;
  canQuote: boolean;
}

export function invoiceBadge(inv: Pick<Invoice, 'status' | 'overdue'>): { label: string; tone: 'green' | 'amber' | 'red' | 'blue' } {
  if (inv.status === 'paid') return { label: 'Paid', tone: 'green' };
  if (inv.overdue) return { label: 'Overdue', tone: 'red' };
  return inv.status === 'part_paid' ? { label: 'Part paid', tone: 'blue' } : { label: 'Unpaid', tone: 'amber' };
}

const openSale = (id: string) => router.push({ pathname: '/receipt/[id]', params: { id } });

/** One customer: what they owe, their invoices and payments, and taking a payment. */
export function CustomerScreen({ page, path, loading, reload }: ScreenProps<ShowProps>) {
  const p = page.props;
  const c = p.customer;
  const action = useAction(path, reload);
  const shop = useAuth((s) => s.counter?.receipt);
  const [sheet, setSheet] = useState<'edit' | 'pay' | { void: Payment } | null>(null);
  const close = () => setSheet(null);

  const shareStatement = async () => {
    if (!shop) return;
    const entries = [
      ...p.invoices.flatMap((inv) => [
        { date: inv.sold_at, what: `Invoice ${inv.number}`, chargeMinor: inv.total_minor, paymentMinor: 0 },
        ...(inv.returned_minor ? [{ date: inv.sold_at, what: `Returned from ${inv.number}`, chargeMinor: 0, paymentMinor: inv.returned_minor }] : []),
      ]),
      ...p.payments
        .filter((x) => !x.voided)
        .map((x) => ({ date: x.paidAt, what: `Payment ${x.number} (${x.method})`, chargeMinor: 0, paymentMinor: x.amountMinor })),
    ].sort((a, b) => a.date.localeCompare(b.date));
    try {
      await shareHtml(statementHtml(shop, { customer: c, balanceMinor: p.balanceMinor, entries }), `Statement ${c.name}`);
    } catch (e) {
      notify('Could not share', errorMessage(e));
    }
  };

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Card className="gap-2">
        <View className="flex-row items-start justify-between gap-3">
          <View className="flex-1">
            <Text accessibilityRole="header" className="text-xl font-bold text-slate-900">
              {c.name}
            </Text>
            <Text className="text-sm text-slate-500">
              {[c.number, c.phone, c.email].filter(Boolean).join(' · ')}
            </Text>
          </View>
          {!c.active && <Badge label="Inactive" />}
        </View>
        {c.address && <Text className="text-sm text-slate-600">{c.address}</Text>}
        {c.note && <Text className="text-sm text-slate-600">{c.note}</Text>}
        <Details
          rows={[
            [p.balanceMinor < 0 ? 'In credit' : 'Owes', money(Math.abs(p.balanceMinor))],
            ['Buys on account', c.onAccount ? (c.creditLimitMinor === null ? 'Yes, no limit' : `Up to ${money(c.creditLimitMinor)}`) : 'No'],
            ...(c.onAccount && p.availableMinor !== null ? ([['May still buy', money(p.availableMinor)]] as [string, string][]) : []),
          ]}
        />
      </Card>

      <View className="flex-row flex-wrap gap-2">
        {p.canTakePayment && <Button title="Take payment" icon="cash-outline" square onPress={() => setSheet('pay')} />}
        {p.canQuote && <Button title="New quotation" icon="document-text-outline" variant="outline" square onPress={() => openPage(`quotations/create?customer=${c.id}`)} />}
        <Button title="Share statement" icon="share-outline" variant="outline" square onPress={shareStatement} />
        {p.canManage && <Button title="Edit" icon="create-outline" variant="outline" square onPress={() => setSheet('edit')} />}
      </View>

      <Panel title="Invoices">
        {p.invoices.length === 0 ? (
          <Text className="px-4 py-3 text-sm text-slate-500">Nothing bought on account yet.</Text>
        ) : (
          p.invoices.map((inv) => {
            const badge = invoiceBadge(inv);
            return (
              <PanelRow
                key={inv.id}
                title={inv.number}
                subtitle={[
                  `${fullDate(inv.sold_at.slice(0, 10))}${inv.due_on ? ` · due ${fullDate(inv.due_on)}` : ''}`,
                  inv.returned_minor ? `${money(inv.returned_minor)} returned` : null,
                  inv.status === 'part_paid' ? `${money(inv.owing_minor)} still owed` : null,
                ]
                  .filter(Boolean)
                  .join('\n')}
                right={money(inv.net_minor)}
                onPress={() => openSale(inv.id)}>
                <View className="flex-row">
                  <Badge label={badge.label} tone={badge.tone} />
                </View>
              </PanelRow>
            );
          })
        )}
      </Panel>

      <Panel title="Payments">
        {p.payments.length === 0 ? (
          <Text className="px-4 py-3 text-sm text-slate-500">No payments yet.</Text>
        ) : (
          p.payments.map((x) => (
            <PanelRow
              key={x.id}
              dim={x.voided}
              title={`${x.number} · ${x.method}`}
              subtitle={[`${dateTime(x.paidAt)} · ${x.by}`, x.reference, x.note, x.voided ? `Voided: ${x.voidReason}` : null].filter(Boolean).join('\n')}
              right={money(x.amountMinor)}
              tone={x.voided ? undefined : 'green'}>
              {x.canVoid && (
                <RowActions>
                  <SmallButton title="Void" tone="red" onPress={() => setSheet({ void: x })} />
                </RowActions>
              )}
            </PanelRow>
          ))
        )}
      </Panel>

      {p.quotations.length > 0 && (
        <Panel title="Quotations">
          {p.quotations.map((q) => (
            <PanelRow
              key={q.id}
              title={q.number}
              subtitle={`${fullDate(q.issued_on)} · ${q.status}`}
              right={money(q.total_minor)}
              onPress={() => openPage(`quotations/${q.id}`)}
            />
          ))}
        </Panel>
      )}

      {p.otherSales.length > 0 && (
        <Panel title="Paid at once">
          {p.otherSales.map((s) => (
            <PanelRow
              key={s.id}
              dim={s.status === 'voided'}
              title={s.number}
              subtitle={`${dateTime(s.sold_at)} · ${s.method}${s.status === 'voided' ? ' · voided' : ''}`}
              right={money(s.total_minor)}
              onPress={() => openSale(s.id)}
            />
          ))}
        </Panel>
      )}

      {sheet === 'edit' && (
        <CustomerSheet title={c.name} to={`customers/${c.id}`} method="PUT" action={action} canManage={p.canManage} initial={c} onClose={close} />
      )}

      {sheet === 'pay' && (
        <FormSheet
          title={`Payment from ${c.name}`}
          submit="Record payment"
          to={`customers/${c.id}/payments`}
          action={action}
          onClose={close}
          initial={{ amount: '', method: p.paymentMethods[0]?.value ?? 'cash', reference: '', note: '', submission_id: randomUUID() }}>
          {(data, set, errors) => (
            <>
              <Banner tone="info">They owe {money(p.balanceMinor)}. The payment settles the oldest invoice first.</Banner>
              <Field
                label="Amount"
                prefix={currencyCode()}
                value={data.amount}
                onChangeText={(t) => set('amount', t)}
                error={errors.amount}
                keyboardType="decimal-pad"
                placeholder={toMajorInput(p.balanceMinor)}
              />
              <Choice label="Paid by" options={p.paymentMethods} value={data.method} onChange={(v) => set('method', v)} error={errors.method} />
              {data.method === 'cash' ? (
                <Text className="text-sm text-slate-500">Cash goes into your shift’s drawer.</Text>
              ) : (
                <Field label="Reference (optional)" value={data.reference} onChangeText={(t) => set('reference', t)} autoCapitalize="characters" />
              )}
              <Field label="Note (optional)" value={data.note} onChangeText={(t) => set('note', t)} />
            </>
          )}
        </FormSheet>
      )}

      {sheet && typeof sheet === 'object' && (
        <FormSheet
          title={`Void ${sheet.void.number}`}
          submit="Void payment"
          danger
          to={`payments/${sheet.void.id}/void`}
          action={action}
          onClose={close}
          initial={{ reason: '' }}>
          {(data, set, errors) => (
            <>
              <Text className="text-sm text-slate-600">
                {money(sheet.void.amountMinor)} by {sheet.void.method} no longer counts toward the account: they owe it again.
              </Text>
              <Field label="Why" value={data.reason} onChangeText={(t) => set('reason', t)} error={errors.reason} />
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
  );
}
