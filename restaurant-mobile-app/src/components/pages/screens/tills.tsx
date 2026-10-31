import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, EmptyState, Hero } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, currencyCode, dateTime, money, plural, time, toMinor } from '@/lib/format';
import { withQuery } from '@/lib/pages';

import { DayPicker, Detail, Difference, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface Till {
  id: string;
  number: string;
  status: 'open' | 'closed';
  openedBy: string;
  openedAt: string;
  openingFloatMinor: number;
  closedBy: string | null;
  closedAt: string | null;
  expectedCashMinor: number | null;
  countedCashMinor: number | null;
  differenceMinor: number | null;
  closingNote: string | null;
}

interface ListProps {
  day: string;
  managing: boolean;
  mine: Till | null;
  tills: Till[];
}

const canTake = (props: ListProps & { access: { isOwner: boolean } | null; branchPermissions: string[] }) =>
  (props.access?.isOwner ?? false) || props.branchPermissions.includes('restaurant.bills.take');

/** Cash shifts at this branch: the member's own to start, and the day's (everyone's for managers). */
export function TillsScreen({ page, path, reload, go }: ScreenProps<ListProps>) {
  const { day, managing, mine, tills } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [float, setFloat] = useState('');

  const start = async () => {
    if (await run('POST', 'tills', { opening_float: float.trim() ? amountInput(float.trim()) : null })) setFloat('');
  };

  return (
    <Scroll onRefresh={reload}>
      {mine ? (
        <Hero>
          <Text className="text-xs font-semibold uppercase tracking-wider text-brand-100">Your shift is open</Text>
          <Text className="mt-1 text-2xl font-bold text-white">{mine.number}</Text>
          <Text className="text-base text-brand-100">
            Since {time(mine.openedAt)} · float {money(mine.openingFloatMinor)}
          </Text>
          <Button title="Count and close" icon="lock-closed-outline" variant="glass" className="mt-4" onPress={() => openPage(`tills/${mine.id}`)} />
        </Hero>
      ) : canTake(page.props) ? (
        <Panel title="Start your shift">
          <View className="gap-3 p-4">
            <Field
              label="Float in the drawer"
              prefix={currencyCode()}
              value={float}
              onChangeText={setFloat}
              keyboardType="decimal-pad"
              placeholder={money(0, false)}
              error={errors.opening_float}
              hint="The cash in the drawer as you start."
            />
            <Button title="Start shift" icon="play" size="lg" loading={busy} onPress={start} />
          </View>
        </Panel>
      ) : null}

      <Panel title={managing ? "The day's shifts" : 'My shifts'} right={<DayPicker day={day} onChange={(next) => go(withQuery('tills', { day: next }))} />}>
        {tills.length === 0 ? (
          <EmptyState icon="wallet-outline" title="No shifts this day" />
        ) : (
          tills.map((till) => (
            <ListRow
              key={till.id}
              title={`${till.number} · ${till.openedBy}`}
              subtitle={`${time(till.openedAt)}${till.closedAt ? ` to ${time(till.closedAt)} · ${till.closedBy}` : ''}`}
              right={
                till.status === 'open' ? (
                  <Badge label="open" tone="blue" />
                ) : till.differenceMinor !== null ? (
                  <Difference minor={till.differenceMinor} format={money} />
                ) : null
              }
              onPress={() => openPage(`tills/${till.id}`)}
            />
          ))
        )}
      </Panel>
    </Scroll>
  );
}

interface ShowProps {
  till: Till;
  summary: { billsCount: number; takenMinor: Record<string, number>; cashRefundsMinor: number; refundsCount: number; expectedCashMinor: number };
  methods: { value: string; label: string }[];
}

/** One shift: what went through it and, while open, the count that closes it. */
export function TillScreen({ page, path, reload }: ScreenProps<ShowProps>) {
  const { till, summary, methods } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [counted, setCounted] = useState('');
  const [note, setNote] = useState('');
  const expected = till.status === 'closed' && till.expectedCashMinor !== null ? till.expectedCashMinor : summary.expectedCashMinor;
  const countedMinor = counted.trim() === '' ? null : toMinor(counted);
  const difference = countedMinor === null ? null : countedMinor - expected;

  const close = () => run('POST', `tills/${till.id}/close`, { counted_cash: amountInput(counted.trim()), closing_note: note.trim() || null });

  return (
    <Scroll onRefresh={reload}>
      <View className="flex-row items-start gap-2">
        <View className="flex-1">
          <Text className="text-2xl font-bold text-slate-900">Shift {till.number}</Text>
          <Text className="text-base text-slate-500">
            {till.openedBy} · {dateTime(till.openedAt)}
            {till.closedAt ? ` to ${time(till.closedAt)}` : ''}
          </Text>
        </View>
        <Badge label={till.status} tone={till.status === 'open' ? 'blue' : 'neutral'} />
      </View>

      <Panel title={`Taken · ${plural(summary.billsCount, 'bill')}`}>
        <View className="px-4 py-2">
          <Detail label="Float" value={money(till.openingFloatMinor)} />
          {methods.map((m) => (
            <Detail key={m.value} label={m.label} value={money(summary.takenMinor[m.value] ?? 0)} />
          ))}
          {summary.refundsCount > 0 ? <Detail label={`Cash refunds (${summary.refundsCount})`} value={`− ${money(summary.cashRefundsMinor)}`} /> : null}
          <View className="my-1 h-px bg-slate-200" />
          <Detail label="Cash expected in the drawer" value={money(expected)} strong />
          {till.status === 'closed' ? (
            <>
              <Detail label="Counted" value={money(till.countedCashMinor ?? 0)} />
              <Detail label="Over / short" value={<Difference minor={till.differenceMinor ?? 0} format={money} />} />
              {till.closingNote ? <Text className="py-1 text-base text-slate-500">“{till.closingNote}”</Text> : null}
            </>
          ) : null}
        </View>
      </Panel>

      {till.status === 'open' ? (
        <Panel title="Count and close">
          <View className="gap-3 p-4">
            <Field
              label="Cash counted in the drawer"
              prefix={currencyCode()}
              value={counted}
              onChangeText={setCounted}
              keyboardType="decimal-pad"
              error={errors.counted_cash}
              hint={difference === null ? undefined : difference === 0 ? 'Exactly as expected.' : difference > 0 ? `${money(difference)} over` : `${money(-difference)} short`}
            />
            {difference !== null && difference !== 0 ? <Banner tone="warning">The count differs from what is expected: say why below.</Banner> : null}
            <Field label="Note" value={note} onChangeText={setNote} maxLength={255} error={errors.closing_note} />
            <Button title="Close shift" icon="lock-closed-outline" size="lg" loading={busy} disabled={counted.trim() === ''} onPress={close} />
          </View>
        </Panel>
      ) : null}
    </Scroll>
  );
}
