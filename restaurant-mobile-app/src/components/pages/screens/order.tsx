import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button, IconButton } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useBusiness } from '@/hooks/use-business';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, money, time, toMinor } from '@/lib/format';
import { errorMessage } from '@/lib/api/client';
import { getPage } from '@/lib/pages';
import { printsKitchenTickets } from '@/lib/printing';
import { printBill, printKitchenTicket, printPaidBill } from '@/lib/receipt';
import { colors, webNoOutline } from '@/lib/theme';
import { useUser } from '@/store/auth';
import { toast } from '@/store/toast';

import { Chips, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';
import type { BillProps } from './bills';

type LineStatus = 'new' | 'preparing' | 'ready' | 'served' | 'voided';

interface Line {
  id: string;
  name: string;
  kind: 'dish' | 'drink';
  quantity: number;
  note: string | null;
  status: LineStatus;
  ticket: string | null;
  voidReason: string | null;
  voidedBy: string | null;
}

interface MenuItem {
  id: string;
  name: string;
  kind: 'dish' | 'drink';
  section: string | null;
  priceMinor: number;
}

interface Props {
  order: {
    id: string;
    number: string;
    service: 'dine_in' | 'takeaway';
    table: { id: string; name: string } | null;
    guests: number | null;
    customerName: string | null;
    status: 'open' | 'paid' | 'cancelled';
    openedAt: string;
    openedBy: string;
    cancelReason: string | null;
    bill: { id: string; number: string; totalMinor: number; status: string } | null;
    lines: Line[];
  };
  bill: {
    lines: { item_id: string; name: string; quantity: number; unit_price_minor: number; total_minor: number }[];
    total_minor: number;
  };
  menu: MenuItem[];
  freeTables: { id: string; name: string }[];
  paymentMethods: { value: string; label: string }[];
  tillOpen: boolean;
  can: { void: boolean; pay: boolean };
}

type Draft = { key: number; item: MenuItem; quantity: number; note: string };

const STATUS: Record<LineStatus, { label: string; tone: 'neutral' | 'amber' | 'green' | 'blue' | 'red' }> = {
  new: { label: 'Not sent', tone: 'blue' },
  preparing: { label: 'Cooking', tone: 'amber' },
  ready: { label: 'Ready', tone: 'green' },
  served: { label: 'Served', tone: 'neutral' },
  voided: { label: 'Voided', tone: 'red' },
};

/**
 * One order. "Add" is the menu: dishes and drinks are gathered first, then
 * added to the order together and sent to the kitchen on one ticket.
 * "Order" is what the kitchen has and the bill, which the cashier takes into
 * their shift.
 */
export function OrderScreen({ page, path, reload }: ScreenProps<Props>) {
  const { order, bill, menu, freeTables, paymentMethods, tillOpen, can } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const business = useBusiness();
  const user = useUser();
  const insets = useSafeAreaInsets();
  const isOpen = order.status === 'open';

  const [view, setView] = useState<'menu' | 'order'>(isOpen && order.lines.length === 0 ? 'menu' : 'order');
  const [search, setSearch] = useState('');
  const [section, setSection] = useState<string>('');
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [nextKey, setNextKey] = useState(0);
  const [reviewing, setReviewing] = useState(false);
  const [voiding, setVoiding] = useState<Line | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [moving, setMoving] = useState(false);
  const [paying, setPaying] = useState(false);
  const [justPaid, setJustPaid] = useState(false);
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');

  const sections = useMemo(() => [...new Set(menu.map((item) => item.section ?? 'Other'))], [menu]);
  const shown = menu.filter(
    (item) =>
      (section === '' || (item.section ?? 'Other') === section) &&
      (search.trim() === '' || item.name.toLowerCase().includes(search.trim().toLowerCase())),
  );
  const newLines = order.lines.filter((line) => line.status === 'new');
  const draftCount = drafts.reduce((sum, draft) => sum + draft.quantity, 0);
  const draftTotal = drafts.reduce((sum, draft) => sum + draft.quantity * draft.item.priceMinor, 0);
  const where = order.table ? `Table ${order.table.name}` : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`;
  const canSeeBills = (page.props.access?.isOwner ?? false) || (page.props.branchPermissions ?? []).includes('restaurant.bills.view');
  const tickets = [...new Set(order.lines.map((line) => line.ticket).filter((t): t is string => t !== null))].sort();
  const tenderedMinor = tendered.trim() === '' ? null : toMinor(tendered);
  const change = tenderedMinor !== null ? tenderedMinor - bill.total_minor : null;

  const pick = (item: MenuItem) => {
    const same = drafts.find((draft) => draft.item.id === item.id && draft.note === '');
    if (same) {
      setDrafts(drafts.map((draft) => (draft === same ? { ...draft, quantity: draft.quantity + 1 } : draft)));
    } else {
      setDrafts([...drafts, { key: nextKey, item, quantity: 1, note: '' }]);
      setNextKey(nextKey + 1);
    }
  };

  const changeDraft = (key: number, patch: Partial<Draft>) =>
    setDrafts(drafts.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)).filter((draft) => draft.quantity > 0));

  const addDrafts = async (thenSend: boolean) => {
    const ok = await run('POST', `orders/${order.id}/lines`, {
      lines: drafts.map((draft) => ({ item_id: draft.item.id, quantity: draft.quantity, note: draft.note.trim() || null })),
    });
    if (!ok) return;
    setDrafts([]);
    setReviewing(false);
    setView('order');
    if (thenSend) await send();
  };

  /** What goes to the kitchen, then its ticket on paper when this phone prints them. */
  const send = async () => {
    if (!(await run('POST', `orders/${order.id}/send`))) return;
    if (!(await printsKitchenTickets())) return;
    try {
      const fresh = await getPage<Props>(path);
      const numbers = fresh.props.order.lines.map((line) => line.ticket).filter((t): t is string => t !== null);
      const latest = numbers.sort().at(-1);
      if (latest) await printTicket(latest, fresh.props.order.lines, false);
    } catch (e) {
      toast(errorMessage(e), 'error');
    }
  };

  const printTicket = (number: string, lines: Line[], again = true) =>
    printKitchenTicket({
      number: again ? `${number} (again)` : number,
      where,
      orderNumber: order.number,
      sentAt: new Date().toISOString(),
      sentBy: user?.name ?? '',
      lines: lines
        .filter((line) => line.ticket === number)
        .map((line) => ({ name: line.name, quantity: line.quantity, note: line.note, voided: line.status === 'voided' })),
    });

  const withReason = async () => {
    const ok = voiding
      ? await run('POST', `orders/${order.id}/lines/${voiding.id}/void`, { reason: reason.trim() })
      : await run('POST', `orders/${order.id}/cancel`, { reason: reason.trim() });
    if (ok) {
      setVoiding(null);
      setCancelling(false);
    }
  };

  const pay = async () => {
    if (newLines.length > 0 || drafts.length > 0) return toast('Send the new lines to the kitchen, or take them off, before the bill.', 'warning');
    const ok = await run('POST', `orders/${order.id}/pay`, {
      payment_method: method,
      amount_tendered: method === 'cash' && tendered.trim() !== '' ? amountInput(tendered.trim()) : null,
      payment_reference: method !== 'cash' && reference.trim() !== '' ? reference.trim() : null,
    });
    if (ok) {
      setPaying(false);
      setJustPaid(true);
    }
  };

  /** The customer's receipt once the order is paid. */
  const printReceipt = async () => {
    if (!order.bill) return;
    try {
      const page = await getPage<BillProps>(`bills/${order.bill.id}`);
      await printPaidBill(page.props.bill, business);
    } catch (e) {
      toast(errorMessage(e), 'error');
    }
  };

  const print = () =>
    printBill({
      business: business.name,
      branch: business.branch,
      title: 'Customer bill',
      details: [`${where} · ${order.number}`, `Opened ${time(order.openedAt)} by ${order.openedBy}`],
      lines: bill.lines.map((line) => ({ name: line.name, quantity: line.quantity, unitPriceMinor: line.unit_price_minor, totalMinor: line.total_minor })),
      totalMinor: bill.total_minor,
      footer: ['Not yet paid.'],
    }).catch((e) => toast(errorMessage(e), 'error'));

  return (
    <View className="flex-1">
      <View className="border-b border-slate-200 bg-white px-4 pb-3 pt-3">
        <View className="flex-row items-center gap-2">
          <View className="flex-1">
            <Text className="text-xl font-bold text-slate-900" numberOfLines={1}>
              {where}
            </Text>
            <Text className="text-sm text-slate-500" numberOfLines={1}>
              {order.number} · {time(order.openedAt)} · {order.openedBy}
              {order.guests ? ` · ${order.guests} guests` : ''}
            </Text>
          </View>
          <Badge label={order.status} tone={order.status === 'open' ? 'blue' : order.status === 'paid' ? 'green' : 'red'} />
          {isOpen && order.service === 'dine_in' && freeTables.length > 0 ? (
            <IconButton icon="swap-horizontal-outline" label="Move to another table" onPress={() => setMoving(true)} />
          ) : null}
          {isOpen ? (
            <IconButton
              icon="close-circle-outline"
              label="Cancel the order"
              color={colors.danger}
              onPress={() => {
                setReason('');
                setCancelling(true);
              }}
            />
          ) : null}
        </View>
        {isOpen ? (
          <View className="mt-3">
            <Chips
              options={[
                { value: 'menu', label: 'Add from the menu' },
                { value: 'order', label: `On the order (${order.lines.filter((l) => l.status !== 'voided').length})` },
              ]}
              value={view}
              onChange={setView}
            />
          </View>
        ) : null}
      </View>

      {isOpen && view === 'menu' ? (
        <>
          <Scroll>
            <View className="min-h-12 flex-row items-center border border-slate-300 bg-white px-3">
              <Ionicons name="search" size={18} color={colors.muted} />
              <TextInput
                value={search}
                onChangeText={setSearch}
                placeholder="Find a dish or drink"
                placeholderTextColor={colors.faint}
                accessibilityLabel="Find on the menu"
                className="ml-2 flex-1 py-3 text-base text-slate-900"
                style={webNoOutline}
              />
              {search ? <IconButton icon="close" label="Clear" onPress={() => setSearch('')} /> : null}
            </View>
            {sections.length > 1 ? (
              <Chips scroll options={[{ value: '', label: 'All' }, ...sections.map((s) => ({ value: s, label: s }))]} value={section} onChange={setSection} />
            ) : null}
            {shown.length === 0 ? (
              <Text className="py-6 text-center text-base text-slate-500">{menu.length === 0 ? 'The menu is empty: add dishes and drinks under Menu.' : 'Nothing on the menu matches.'}</Text>
            ) : (
              <View className="flex-row flex-wrap gap-2">
                {shown.map((item) => {
                  const picked = drafts.filter((d) => d.item.id === item.id).reduce((sum, d) => sum + d.quantity, 0);
                  return (
                    <Pressable
                      key={item.id}
                      accessibilityRole="button"
                      accessibilityLabel={`Add ${item.name}, ${money(item.priceMinor)}`}
                      onPress={() => pick(item)}
                      className={`min-h-20 w-[48.5%] flex-grow justify-between border p-3 ${picked ? 'border-brand-400 bg-brand-50' : 'border-slate-200 bg-white'} active:bg-brand-100`}>
                      <View className="flex-row items-start gap-1">
                        <Text className="flex-1 text-base font-semibold text-slate-900" numberOfLines={2}>
                          {item.name}
                        </Text>
                        {picked ? (
                          <View className="h-6 min-w-6 items-center justify-center bg-brand-600 px-1">
                            <Text className="text-xs font-bold text-white">{picked}</Text>
                          </View>
                        ) : null}
                      </View>
                      <View className="flex-row items-center gap-1">
                        <Ionicons name={item.kind === 'drink' ? 'wine-outline' : 'restaurant-outline'} size={12} color={colors.faint} />
                        <Text className="text-sm text-slate-500">{money(item.priceMinor)}</Text>
                      </View>
                    </Pressable>
                  );
                })}
              </View>
            )}
          </Scroll>

          {drafts.length > 0 ? (
            <Pressable
              accessibilityRole="button"
              onPress={() => setReviewing(true)}
              className="flex-row items-center gap-3 bg-brand-600 px-4 pt-3 active:bg-brand-700"
              style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
              <View className="h-8 min-w-8 items-center justify-center bg-white/20 px-1.5">
                <Text className="text-base font-bold text-white">{draftCount}</Text>
              </View>
              <Text className="flex-1 text-base font-semibold text-white">Review and send</Text>
              <Text className="text-base font-bold text-white">{money(draftTotal)}</Text>
              <Ionicons name="chevron-up" size={20} color={colors.white} />
            </Pressable>
          ) : null}
        </>
      ) : (
        <Scroll onRefresh={reload}>
          {order.status === 'cancelled' ? <Banner tone="error">Cancelled: {order.cancelReason}</Banner> : null}
          {order.bill ? (
            <Pressable accessibilityRole="button" onPress={() => openPage(`bills/${order.bill!.id}`)}>
              <Banner tone="success">
                Paid on bill {order.bill.number}, {money(order.bill.totalMinor)}. Tap to open the bill.
              </Banner>
            </Pressable>
          ) : null}
          {order.bill && canSeeBills ? <Button title="Print receipt" icon="print-outline" variant="outline" onPress={() => void printReceipt()} /> : null}

          <Panel title="On the order">
            {order.lines.length === 0 ? (
              <Text className="px-4 py-6 text-center text-base text-slate-500">Nothing yet: add from the menu.</Text>
            ) : (
              order.lines.map((line) => (
                <View key={line.id} className={`flex-row items-start gap-2 border-b border-slate-100 px-4 py-2.5 ${line.status === 'voided' ? 'opacity-60' : ''}`}>
                  <Text className="w-8 text-base font-bold text-slate-900">{line.quantity}×</Text>
                  <View className="flex-1 gap-1">
                    <Text className={`text-base font-medium text-slate-900 ${line.status === 'voided' ? 'line-through' : ''}`}>{line.name}</Text>
                    {line.note ? <Text className="text-sm text-amber-800">“{line.note}”</Text> : null}
                    {line.status === 'voided' ? (
                      <Text className="text-sm text-red-700">
                        {line.voidReason}
                        {line.voidedBy ? ` · ${line.voidedBy}` : ''}
                      </Text>
                    ) : null}
                    <View className="flex-row">
                      <Badge label={`${STATUS[line.status].label}${line.ticket ? ` · ${line.ticket}` : ''}`} tone={STATUS[line.status].tone} />
                    </View>
                  </View>
                  {isOpen && line.status === 'new' ? (
                    <IconButton icon="trash-outline" label={`Take off ${line.name}`} disabled={busy} onPress={() => void run('DELETE', `orders/${order.id}/lines/${line.id}`)} />
                  ) : null}
                  {isOpen && can.void && line.status !== 'new' && line.status !== 'voided' ? (
                    <IconButton
                      icon="ban-outline"
                      label={`Void ${line.name}`}
                      color={colors.danger}
                      disabled={busy}
                      onPress={() => {
                        setReason('');
                        setVoiding(line);
                      }}
                    />
                  ) : null}
                </View>
              ))
            )}
            {isOpen ? (
              <View className="gap-2 p-4">
                <Button title="Add more items" icon="add-circle-outline" variant="secondary" onPress={() => setView('menu')} />
                {tickets.length > 0 ? (
                  <View className="flex-row flex-wrap gap-2">
                    {tickets.map((number) => (
                      <Button
                        key={number}
                        title={`Print ${number}`}
                        icon="print-outline"
                        variant="outline"
                        onPress={() => void printTicket(number, order.lines).catch((e) => toast(errorMessage(e), 'error'))}
                      />
                    ))}
                  </View>
                ) : null}
              </View>
            ) : null}
          </Panel>

          {isOpen ? (
            <Panel title="Bill" right={<Text className="text-lg font-bold text-brand-900">{money(bill.total_minor)}</Text>}>
              {bill.lines.map((line) => (
                <View key={line.item_id} className="flex-row justify-between gap-2 border-b border-slate-100 px-4 py-2">
                  <Text className="flex-1 text-base text-slate-700">
                    {line.quantity} × {line.name}
                  </Text>
                  <Text className="text-base text-slate-900">{money(line.total_minor)}</Text>
                </View>
              ))}
              {can.pay && !tillOpen && bill.lines.length > 0 ? (
                <View className="gap-2 p-4">
                  <Banner tone="warning">Start your shift to take the bill.</Banner>
                  <Button title="Go to my shift" icon="wallet-outline" variant="secondary" onPress={() => router.navigate('/tills')} />
                </View>
              ) : null}
            </Panel>
          ) : null}
        </Scroll>
      )}

      {isOpen && view === 'order' && (newLines.length > 0 || bill.lines.length > 0) ? (
        <View className="flex-row gap-2 border-t border-slate-200 bg-white px-4 pt-3" style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
          {newLines.length > 0 ? (
            <Button title={`Send ${newLines.length} to the kitchen`} icon="send" size="lg" className="flex-1" loading={busy} onPress={() => void send()} />
          ) : (
            <>
              <Button title="Print bill" icon="print-outline" variant="outline" size="lg" className={can.pay && tillOpen ? '' : 'flex-1'} onPress={print} />
              {can.pay && tillOpen ? (
                <Button title={`Take ${money(bill.total_minor)}`} icon="cash-outline" variant="accent" size="lg" className="flex-1" onPress={() => setPaying(true)} />
              ) : null}
            </>
          )}
        </View>
      ) : null}

      <Sheet visible={reviewing} onClose={() => setReviewing(false)} title={`Adding · ${money(draftTotal)}`}>
        {drafts.map((draft) => (
          <View key={draft.key} className="gap-2 border-b border-slate-100 pb-3">
            <View className="flex-row items-center gap-2">
              <Text className="flex-1 text-base font-semibold text-slate-900">{draft.item.name}</Text>
              <IconButton icon="remove-circle-outline" label={`One fewer ${draft.item.name}`} onPress={() => changeDraft(draft.key, { quantity: draft.quantity - 1 })} />
              <Text className="w-8 text-center text-lg font-bold text-slate-900">{draft.quantity}</Text>
              <IconButton icon="add-circle-outline" label={`One more ${draft.item.name}`} onPress={() => changeDraft(draft.key, { quantity: draft.quantity + 1 })} />
            </View>
            {draft.item.kind === 'dish' ? (
              <Field
                value={draft.note}
                onChangeText={(note) => changeDraft(draft.key, { note })}
                placeholder="Note for the kitchen: no onions…"
                accessibilityLabel={`Note for ${draft.item.name}`}
                maxLength={160}
              />
            ) : null}
          </View>
        ))}
        {errors.lines ? <Banner tone="error">{errors.lines}</Banner> : null}
        <Button title="Add and send to kitchen" icon="send" size="lg" loading={busy} onPress={() => void addDrafts(true)} />
        <Button title="Add to order, send later" variant="outline" disabled={busy} onPress={() => void addDrafts(false)} />
      </Sheet>

      <Sheet visible={paying} onClose={() => setPaying(false)} title={`Take ${money(bill.total_minor)}`}>
        <Text className="text-sm font-medium text-slate-700">Paid by</Text>
        <Chips options={paymentMethods} value={method} onChange={setMethod} />
        {errors.payment_method ? <Banner tone="error">{errors.payment_method}</Banner> : null}
        {method === 'cash' ? (
          <Field
            label="Cash given"
            prefix={money(0).split(' ')[0]}
            value={tendered}
            onChangeText={setTendered}
            keyboardType="decimal-pad"
            placeholder={money(bill.total_minor, false)}
            error={errors.amount_tendered}
            hint={change !== null && change >= 0 ? `Change ${money(change)}` : change !== null ? 'Less than the bill' : 'Optional: for the change.'}
          />
        ) : (
          <Field label="Reference" hint="Optional: the transaction number." value={reference} onChangeText={setReference} maxLength={64} error={errors.payment_reference} />
        )}
        <Button
          title={`Take ${money(bill.total_minor)}`}
          icon="checkmark-circle-outline"
          variant="accent"
          size="lg"
          loading={busy}
          disabled={change !== null && change < 0}
          onPress={pay}
        />
      </Sheet>

      <Sheet visible={justPaid && order.bill !== null && canSeeBills} onClose={() => setJustPaid(false)} title={`Paid · bill ${order.bill?.number ?? ''}`}>
        <Text className="text-base text-slate-600">Give the customer their receipt?</Text>
        <Button
          title="Print receipt"
          icon="print-outline"
          variant="accent"
          size="lg"
          onPress={() => {
            setJustPaid(false);
            void printReceipt();
          }}
        />
        <Button title="No receipt" variant="outline" onPress={() => setJustPaid(false)} />
      </Sheet>

      <Sheet
        visible={voiding !== null || cancelling}
        onClose={() => {
          setVoiding(null);
          setCancelling(false);
        }}
        title={voiding ? `Void ${voiding.quantity} × ${voiding.name}` : `Cancel order ${order.number}`}>
        <Text className="text-base text-slate-600">
          {voiding ? 'The kitchen has it: it stays on the ticket, crossed out, and is never billed.' : 'Everything on it is voided and the table is free again.'}
        </Text>
        <Field label="Reason" value={reason} onChangeText={setReason} maxLength={255} placeholder="Sent back, ordered by mistake…" autoFocus error={errors.reason} />
        <Button title={voiding ? 'Void' : 'Cancel order'} variant="danger" size="lg" loading={busy} disabled={reason.trim() === ''} onPress={withReason} />
      </Sheet>

      <Sheet visible={moving} onClose={() => setMoving(false)} title={`Move ${where} to`}>
        {errors.dining_table_id ? <Banner tone="error">{errors.dining_table_id}</Banner> : null}
        <View className="flex-row flex-wrap gap-2">
          {freeTables.map((table) => (
            <Button
              key={table.id}
              title={table.name}
              variant="outline"
              className="min-w-24 flex-grow"
              disabled={busy}
              onPress={() => void run('POST', `orders/${order.id}/move`, { dining_table_id: table.id }).then((ok) => ok && setMoving(false))}
            />
          ))}
        </View>
      </Sheet>
    </View>
  );
}
