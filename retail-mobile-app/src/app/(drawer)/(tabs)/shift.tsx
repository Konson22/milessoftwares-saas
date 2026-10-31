import { Ionicons } from '@expo/vector-icons';
import { randomUUID } from 'expo-crypto';
import { router } from 'expo-router';
import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, Card, Divider, Hero, HeroStat, Row, SectionTitle } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { api, errorMessage } from '@/lib/api';
import { haptic } from '@/lib/feedback';
import { currencyCode, money, time, toMajorInput, toMinor } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { CashCounterpart, CashDirection, Till } from '@/lib/types';
import { useAuth } from '@/store/auth';

export default function ShiftScreen() {
  const till = useAuth((s) => s.till);
  const loaded = useAuth((s) => s.tillLoaded);
  const refreshTill = useAuth((s) => s.refreshTill);
  const [closed, setClosed] = useState<Till | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  async function refresh() {
    setRefreshing(true);
    await refreshTill().catch(() => {});
    setRefreshing(false);
  }

  return (
    <ScrollView
      contentContainerClassName="w-full max-w-2xl self-center gap-4 p-4"
      keyboardShouldPersistTaps="handled"
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} />}>
      {closed && <ClosedSummary till={closed} />}
      {!loaded ? null : till ? <ActiveShift till={till} onClosed={setClosed} /> : <OpenShift onOpened={() => setClosed(null)} />}
    </ScrollView>
  );
}

function ClosedSummary({ till }: { till: Till }) {
  if (till.blind || till.difference_minor === null) {
    return <Banner tone="success">Shift {till.number} closed. Counted {money(till.counted_cash_minor)}; a manager checks the count.</Banner>;
  }
  const diff = till.difference_minor;
  return (
    <Banner tone={diff ? 'warning' : 'success'}>
      Shift {till.number} closed · expected {money(till.expected_cash_minor)}, counted {money(till.counted_cash_minor)}
      {diff ? ` (${diff > 0 ? 'over' : 'short'} ${money(Math.abs(diff))})` : ''}
    </Banner>
  );
}

function OpenShift({ onOpened }: { onOpened: () => void }) {
  const setTill = useAuth((s) => s.setTill);
  const rules = useAuth((s) => s.counter?.counter);
  const [float, setFloat] = useState(rules?.default_float_minor ? toMajorInput(rules.default_float_minor) : '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function open() {
    const minor = toMinor(float);
    if (float.trim() === '' || minor === null) return setError('Enter the opening float (0 if the drawer is empty).');
    setBusy(true);
    setError(null);
    try {
      setTill(await api.openTill(minor));
      haptic.success();
      onOpened();
      router.navigate('/sell');
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="gap-4">
      <View className="flex-row items-center gap-3">
        <View className="h-12 w-12 items-center justify-center rounded-2xl bg-brand-600">
          <Ionicons name="lock-open" size={24} color={colors.white} />
        </View>
        <View className="flex-1">
          <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
            Start your shift
          </Text>
          <Text className="text-base text-slate-500">Count the cash drawer and enter the float</Text>
        </View>
      </View>
      {rules?.shifts_required ? (
        <Banner tone="warning">This branch sells within a shift: start yours before selling.</Banner>
      ) : (
        <Banner tone="info">Shifts are optional here; one keeps your cash count.</Banner>
      )}
      <Field
        label="Opening float"
        prefix={currencyCode()}
        value={float}
        onChangeText={setFloat}
        keyboardType="decimal-pad"
        placeholder="0"
        returnKeyType="done"
        onSubmitEditing={open}
      />
      {error && <Banner tone="error">{error}</Banner>}
      <Button title="Start shift" size="lg" icon="lock-open" loading={busy} onPress={open} />
    </Card>
  );
}

function ActiveShift({ till, onClosed }: { till: Till; onClosed: (t: Till) => void }) {
  const methods = useAuth((s) => s.counter?.payment_methods ?? []);
  const [closing, setClosing] = useState(false);
  const [moving, setMoving] = useState<CashDirection | null>(null);
  const s = till.summary;
  const label = (method: string) => (method === 'account' ? 'On account' : (methods.find((m) => m.value === method)?.label ?? method.replace('_', ' ')));

  return (
    <>
      <Hero className="gap-3">
        <Text className="text-sm font-medium text-brand-100">Shift {till.number}</Text>
        {till.blind ? (
          <Text className="text-2xl font-bold text-white">Open since {time(till.opened_at)}</Text>
        ) : (
          <>
            <Text className="text-sm font-medium text-brand-100">Cash the drawer should hold</Text>
            <Text accessibilityLabel={`Expected cash ${money(till.expected_cash_minor)}`} className="text-4xl font-bold text-white">
              {money(till.expected_cash_minor)}
            </Text>
            <Text className="text-sm text-brand-100">Open since {time(till.opened_at)}</Text>
          </>
        )}
        <View className="flex-row gap-2">
          <HeroStat label="Float" value={money(till.opening_float_minor, false)} />
          <HeroStat label="Sales" value={String(s.sales_count)} />
          <HeroStat label="Refunds" value={String(s.refunds_count)} />
        </View>
      </Hero>

      {s.taken_minor && Object.keys(s.taken_minor).length > 0 && (
        <Card className="gap-1">
          <SectionTitle>Taken this shift</SectionTitle>
          {Object.entries(s.taken_minor).map(([method, amount]) => (
            <Row key={method} label={label(method)} value={money(amount)} />
          ))}
          {!!s.cash_refunds_minor && (
            <>
              <Divider />
              <Row label="Cash refunded" value={`−${money(s.cash_refunds_minor)}`} />
            </>
          )}
        </Card>
      )}
      {s.received_minor && Object.keys(s.received_minor).length > 0 && (
        <Card className="gap-1">
          <SectionTitle>Paid in by customers</SectionTitle>
          {Object.entries(s.received_minor).map(([method, amount]) => (
            <Row key={method} label={label(method)} value={money(amount)} />
          ))}
        </Card>
      )}
      <Card className="gap-3">
        <SectionTitle>Cash in and out</SectionTitle>
        {till.movements.length === 0 ? (
          <Text className="text-base text-slate-500">
            Change from the safe, a drop to it, cash banked or an expense paid. It changes what the drawer should hold.
          </Text>
        ) : (
          <View className="gap-1">
            {till.movements.map((m) => (
              <View key={m.id} className="flex-row items-start justify-between gap-3 py-1">
                <View className="flex-1">
                  <Text className="text-base text-slate-900">{m.reason}</Text>
                  <Text className="text-sm text-slate-500">
                    {m.label} · {m.recorded_by} · {time(m.recorded_at)}
                  </Text>
                </View>
                <Text
                  accessibilityLabel={`${m.direction === 'in' ? 'Put in' : 'Taken out'} ${money(m.amount_minor)}`}
                  className={`text-base font-semibold ${m.direction === 'in' ? 'text-emerald-700' : 'text-slate-900'}`}>
                  {m.direction === 'in' ? '+' : '−'}
                  {money(m.amount_minor)}
                </Text>
              </View>
            ))}
            <Divider />
            <Row label="Put in" value={money(s.cash_in_minor)} />
            <Row label="Taken out" value={`−${money(s.cash_out_minor)}`} />
          </View>
        )}
        <View className="flex-row gap-2">
          <Button title="Cash in" variant="outline" icon="add-circle-outline" className="flex-1" onPress={() => setMoving('in')} />
          <Button title="Cash out" variant="outline" icon="remove-circle-outline" className="flex-1" onPress={() => setMoving('out')} />
        </View>
      </Card>

      {till.blind && <Banner tone="info">You count the drawer blind at close; a manager compares it with the sales.</Banner>}

      <Button
        title="Close shift"
        variant="danger"
        size="lg"
        icon="lock-closed"
        onPress={() => setClosing(true)}
      />

      <CloseSheet visible={closing} till={till} onClose={() => setClosing(false)} onClosed={onClosed} />
      {moving && <MoveSheet direction={moving} onClose={() => setMoving(null)} />}
    </>
  );
}

const COUNTERPARTS: Record<CashDirection, { value: CashCounterpart; label: string; help: string }[]> = {
  in: [
    { value: 'safe', label: 'From the safe', help: 'Change brought from the safe.' },
    { value: 'bank', label: 'From the bank', help: 'Cash drawn from the bank; it goes to the books.' },
  ],
  out: [
    { value: 'safe', label: 'To the safe', help: 'A drop to the safe.' },
    { value: 'bank', label: 'Banked', help: 'Deposited at the bank; it goes to the books.' },
    { value: 'expense', label: 'Expense', help: 'Paid for something (cleaning, transport); it goes to the books as an expense.' },
  ],
};

/**
 * Cash put in or taken out; one submission id until it goes through, so a
 * retry records it once. An expense paid from the drawer names its category
 * (those marked "paid from the till" under Expenses).
 */
function MoveSheet({ direction, onClose }: { direction: CashDirection; onClose: () => void }) {
  const setTill = useAuth((s) => s.setTill);
  const [categories, setCategories] = useState<{ id: string; name: string }[] | null>(null);
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [counterpart, setCounterpart] = useState<CashCounterpart>('safe');
  const [submissionId] = useState(() => randomUUID());
  const options = COUNTERPARTS[direction];
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const into = direction === 'in';

  function choose(value: CashCounterpart) {
    setCounterpart(value);
    if (value === 'expense' && categories === null) {
      api
        .expenseCategories()
        .then((found) => {
          setCategories(found);
          if (found.length === 1) setCategoryId(found[0].id);
        })
        .catch(() => setCategories([]));
    }
  }

  async function save() {
    const minor = amount.trim() ? toMinor(amount) : null;
    if (minor === null || minor <= 0) return setError('Enter an amount above zero.');
    if (!reason.trim()) return setError(into ? 'Say where the cash comes from.' : 'Say where the cash goes.');
    if (counterpart === 'expense' && !categoryId) return setError('Choose what the expense is for.');
    setBusy(true);
    setError(null);
    try {
      setTill(await api.moveCash(direction, counterpart, minor, reason.trim(), submissionId, counterpart === 'expense' ? categoryId : null));
      haptic.success();
      onClose();
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet visible onClose={onClose} title={into ? 'Put cash in' : 'Take cash out'}>
      <View accessibilityRole="radiogroup" className="flex-row gap-2">
        {options.map((option) => (
          <Button
            key={option.value}
            title={option.label}
            variant={counterpart === option.value ? 'primary' : 'outline'}
            className="flex-1"
            accessibilityLabel={`${option.label}${counterpart === option.value ? ', selected' : ''}`}
            onPress={() => choose(option.value)}
          />
        ))}
      </View>
      <Text className="text-base text-slate-500">{options.find((o) => o.value === counterpart)?.help}</Text>
      {counterpart === 'expense' &&
        (categories === null ? null : categories.length === 0 ? (
          <Banner tone="warning">No expense category is paid from the till yet. A manager marks one under Expenses.</Banner>
        ) : (
          <View accessibilityRole="radiogroup" className="flex-row flex-wrap gap-2">
            {categories.map((c) => (
              <Button
                key={c.id}
                title={c.name}
                variant={categoryId === c.id ? 'secondary' : 'outline'}
                accessibilityLabel={`${c.name}${categoryId === c.id ? ', selected' : ''}`}
                onPress={() => setCategoryId(c.id)}
              />
            ))}
          </View>
        ))}
      <Field label="Amount" prefix={currencyCode()} value={amount} onChangeText={setAmount} keyboardType="decimal-pad" placeholder="0" />
      <Field
        label="Reason"
        value={reason}
        onChangeText={setReason}
        placeholder={counterpart === 'expense' ? 'e.g. Cleaning materials' : into ? 'e.g. Change for the morning' : 'e.g. End of morning drop'}
        maxLength={255}
        returnKeyType="done"
        onSubmitEditing={save}
      />
      {error && <Banner tone="error">{error}</Banner>}
      <Button title={into ? 'Put cash in' : 'Take cash out'} size="lg" icon={into ? 'add-circle' : 'remove-circle'} loading={busy} onPress={save} />
    </Sheet>
  );
}

function CloseSheet({ visible, till, onClose, onClosed }: { visible: boolean; till: Till; onClose: () => void; onClosed: (t: Till) => void }) {
  const setTill = useAuth((s) => s.setTill);
  const [counted, setCounted] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const countedMinor = counted.trim() ? toMinor(counted) : null;
  const expected = till.expected_cash_minor;
  const difference = !till.blind && expected !== null && countedMinor !== null ? countedMinor - expected : 0;

  async function close() {
    if (countedMinor === null) return setError('Enter the cash you counted.');
    if (difference !== 0 && !note.trim()) return setError('Say why the count differs from what the drawer should hold.');
    setBusy(true);
    setError(null);
    try {
      const result = await api.closeTill(countedMinor, note.trim() || null);
      haptic.success();
      onClose();
      setTill(null);
      onClosed(result);
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="Close shift">
      {till.blind ? (
        <Text className="text-base text-slate-500">Count every note and coin in the drawer, then enter the total.</Text>
      ) : (
        <Row label="The drawer should hold" value={money(expected)} strong />
      )}
      <Field label="Cash counted" prefix={currencyCode()} value={counted} onChangeText={setCounted} keyboardType="decimal-pad" placeholder="0" />
      {!till.blind && countedMinor !== null && (
        <View className={`px-4 py-3 ${difference === 0 ? 'bg-emerald-50' : 'bg-amber-50'}`}>
          <Text className={`text-base font-semibold ${difference === 0 ? 'text-emerald-800' : 'text-amber-800'}`}>
            {difference === 0 ? 'The drawer balances' : `${difference > 0 ? 'Over' : 'Short'} by ${money(Math.abs(difference))}`}
          </Text>
        </View>
      )}
      {(till.blind || difference !== 0) && (
        <Field
          label={till.blind ? 'Note (optional)' : 'Why it differs'}
          value={note}
          onChangeText={setNote}
          placeholder="e.g. Short change given"
          multiline
          maxLength={255}
        />
      )}
      {error && <Banner tone="error">{error}</Banner>}
      <Button title="Close shift" variant="danger" size="lg" loading={busy} onPress={close} />
    </Sheet>
  );
}
