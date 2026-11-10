import { useState } from 'react';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, EmptyState } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { openPage, useAction } from '@/hooks/use-page';
import { amountInput, litres, money, prettyDate, time } from '@/lib/format';

import { Chips, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

/* ---------------------------------------------------------------- shifts */

interface ShiftsProps {
  shifts: { id: string; number: string; status: string; businessDate: string; openedAt: string }[];
  schedules: { id: string; name: string; starts_at: string; ends_at: string }[];
  canOpen: boolean;
  books: string;
}

const shiftTone = (status: string) =>
  status === 'open' ? 'green' : status === 'approved' ? 'blue' : status === 'rejected' ? 'red' : 'amber';

/**
 * The branch's shifts, newest first. A supervisor opens one here with the
 * cash float; the meters open at the last closing readings (change them on
 * the shift's page on the web).
 */
export function ShiftsScreen({ page, path, reload }: ScreenProps<ShiftsProps>) {
  const { shifts, schedules, canOpen, books } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [opening, setOpening] = useState(false);
  const [float, setFloat] = useState('');
  const [scheduleId, setScheduleId] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const open = shifts.find((s) => s.status === 'open');

  return (
    <Scroll onRefresh={reload}>
      {canOpen && !open ? <Button title="Open a shift" icon="play-outline" size="lg" onPress={() => setOpening(true)} /> : null}
      <Panel>
        {shifts.length === 0 ? (
          <EmptyState icon="time-outline" title="No shifts yet" />
        ) : (
          shifts.map((shift) => (
            <ListRow
              key={shift.id}
              title={`Shift ${shift.number}`}
              subtitle={`${prettyDate(shift.businessDate)} · opened ${time(shift.openedAt)}`}
              right={<Badge label={shift.status.replace(/_/g, ' ')} tone={shiftTone(shift.status)} />}
              onPress={() => openPage(`shifts/${shift.id}`)}
            />
          ))
        )}
      </Panel>

      <Sheet visible={opening} onClose={() => setOpening(false)} title="Open a shift">
        <Field label={`Cash float (${books})`} value={float} onChangeText={setFloat} keyboardType="decimal-pad" placeholder="0" error={errors.opening_float} />
        {schedules.length > 0 ? (
          <View className="gap-1.5">
            <Text className="text-sm font-medium text-slate-700">Schedule</Text>
            <Chips options={schedules.map((s) => ({ value: s.id, label: `${s.name} ${s.starts_at.slice(0, 5)}–${s.ends_at.slice(0, 5)}` }))} value={scheduleId} onChange={setScheduleId} />
          </View>
        ) : null}
        <Field label="Handover note" value={note} onChangeText={setNote} placeholder="Optional" error={errors.handover_note} />
        {errors.openings ? <Text className="text-sm text-red-700">{errors.openings}</Text> : null}
        <Button
          title="Open shift"
          size="lg"
          loading={busy}
          onPress={async () => {
            const done = await run('POST', 'shifts', { opening_float: amountInput(float) || '0', schedule_id: scheduleId, handover_note: note.trim() || null });
            if (done) setOpening(false);
          }}
        />
      </Sheet>
    </Scroll>
  );
}

/* ---------------------------------------------------------------- tanks */

interface TanksProps {
  tanks: {
    id: string;
    name: string;
    code: string;
    product: string;
    capacity: string;
    quantity: string;
    averageCostMinor: number;
    status: string;
    low: boolean;
  }[];
}

/** What each tank holds, fullest first in the web's order; low ones stand out. */
export function TanksScreen({ page, reload }: ScreenProps<TanksProps>) {
  const { tanks } = page.props;

  return (
    <Scroll onRefresh={reload}>
      {tanks.length === 0 ? (
        <EmptyState icon="water-outline" title="No tanks yet" message="Tanks are added on the web, under Tanks." />
      ) : (
        tanks.map((tank) => {
          const capacity = Number(tank.capacity);
          const share = capacity > 0 ? Math.max(0, Math.min(1, Number(tank.quantity) / capacity)) : 0;
          return (
            <Panel key={tank.id}>
              <ListRow
                title={`${tank.code} · ${tank.product}`}
                subtitle={`${litres(tank.quantity)} of ${litres(tank.capacity)} · ${money(tank.averageCostMinor)} / L`}
                right={tank.low ? <Badge label="low" tone="amber" /> : tank.status !== 'active' ? <Badge label={tank.status} /> : null}
                onPress={() => openPage(`tanks/${tank.id}`)}
              />
              <View className="px-4 pb-3 pt-1">
                <View className="h-2.5 bg-slate-100">
                  <View className={`h-2.5 ${tank.low ? 'bg-amber-500' : 'bg-brand-600'}`} style={{ width: `${Math.round(share * 100)}%` }} />
                </View>
                <Text className="mt-1 text-xs text-slate-500">{Math.round(share * 100)}% full</Text>
              </View>
            </Panel>
          );
        })
      )}
    </Scroll>
  );
}
