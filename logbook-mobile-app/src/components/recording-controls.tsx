import { Text, View } from 'react-native';

import { when } from '@/components/entries';
import { Choice, Input } from '@/components/entry-form';
import { Button } from '@/components/ui/button';
import { Card, Row } from '@/components/ui/misc';
import type { Estimate } from '@/logbook/activity';
import type { KitShift } from '@/logbook/types';
import type { Occurrence } from '@/logbook/work';

/*
 * ADR-0030 on the phone: which expected reading an entry is for, the
 * shift it was recorded in, the end of an activity, and the fuel estimate
 * as a preview. The server works the estimate out again and keeps its own.
 */

const timeOf = (iso: string) => {
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

function Label({ children, required }: { children: string; required?: boolean }) {
  return (
    <Text className="text-base font-medium text-slate-800">
      {children}
      {required ? <Text className="text-red-600"> *</Text> : null}
    </Text>
  );
}

/** The expected reading this entry is for, or an extra one. */
export function ReadingPicker({
  choices,
  value,
  extra,
  onChange,
}: {
  choices: Occurrence[];
  value: string | null;
  extra: boolean;
  onChange: (next: { occurrenceId: string | null; extra: boolean }) => void;
}) {
  return (
    <View className="gap-2">
      <Label>Which reading is this?</Label>
      {choices.map((o) => (
        <Choice
          key={o.id}
          round
          label={`${timeOf(o.dueAt)} reading · ${when(o.dueAt)}${o.state === 'missed' ? ' (missed)' : o.state === 'overdue' ? ' (late)' : ''}`}
          selected={!extra && value === o.id}
          onPress={() => onChange({ occurrenceId: o.id, extra: false })}
        />
      ))}
      <Choice round label="An extra reading (not one of the expected ones)" selected={extra} onPress={() => onChange({ occurrenceId: null, extra: true })} />
      {!extra && value === null && <Text className="text-sm text-slate-500">Not chosen: the server matches it to a reading by its time.</Text>}
    </View>
  );
}

export function ShiftPicker({ shifts, value, required, error, onChange }: { shifts: KitShift[]; value: string | null; required: boolean; error?: string; onChange: (id: string | null) => void }) {
  return (
    <View className="gap-2">
      <Label required={required}>Shift</Label>
      {shifts.map((s) => (
        <Choice key={s.id} round label={`${s.name} · ${s.starts}–${s.ends}`} selected={value === s.id} onPress={() => onChange(value === s.id && !required ? null : s.id)} />
      ))}
      {error ? <Text className="text-sm text-red-700">{error}</Text> : null}
    </View>
  );
}

/** When the activity ended; empty while it is still going on. */
export function ActivityEnd({ started, value, error, onChange }: { started: string; value: string; error?: string; onChange: (text: string) => void }) {
  return (
    <View className="gap-1.5">
      <Label required>When did it end?</Label>
      <Text className="text-sm text-slate-500">Started {started}. Save it as a draft while it goes on, and fill this in at the end.</Text>
      <View className="flex-row gap-2">
        <View className="flex-1">
          <Input value={value} placeholder="YYYY-MM-DD HH:MM" keyboard="numbers-and-punctuation" onChange={onChange} />
        </View>
        <Button title="Now" variant="outline" size="lg" onPress={() => onChange(nowText())} />
      </View>
      {error ? <Text className="text-sm text-red-700">{error}</Text> : null}
    </View>
  );
}

export function nowText(d = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

const litres = (n: number) => `${n.toLocaleString()} L`;

/** The fuel estimate as typed: a preview, worked out again by the server. */
export function EstimateCard({ estimate }: { estimate: Estimate | null }) {
  if (!estimate) return null;
  const { resource, quantity, unit, rate, mismatch, estimated, actual, refill, difference, endBelowStart, belowLast } = estimate;
  return (
    <Card className="gap-1">
      <Text className="text-base font-semibold text-slate-900">Fuel estimate</Text>
      {resource && <Text className="text-sm text-slate-500">{`${resource.code} · ${resource.name}`}</Text>}
      {endBelowStart && <Text className="text-sm text-red-700">The end reading is below the start.</Text>}
      {belowLast !== null && <Text className="text-sm text-amber-800">{`The start is below the last end reading on record (${belowLast}). The server refuses it unless a meter change was recorded.`}</Text>}
      <Row label={unit === 'km' ? 'Distance' : 'Hours'} value={quantity === null ? '—' : `${quantity} ${unit}`} />
      <Row
        label="Rate"
        value={rate === null ? 'No rate for this resource' : mismatch ? `${rate.value} ${rate.unit} (another measure)` : `${rate.value} ${rate.unit}`}
      />
      <Row label="Estimated" value={estimated === null ? '—' : litres(estimated)} strong />
      {actual !== null && <Row label="Measured" value={litres(actual)} />}
      {difference !== null && <Row label="Difference" value={`${difference > 0 ? '+' : ''}${difference.toLocaleString()} L`} />}
      {refill !== null && <Row label="Refilled (not the fuel used)" value={litres(refill)} />}
      <Text className="text-xs text-slate-500">
        A preview from the rates on this phone. The figure kept is worked out by the server when the entry is sent. A difference is a prompt to look, not a finding.
      </Text>
    </Card>
  );
}
