import { useState } from 'react';
import { Text, View } from 'react-native';

import { Input } from '@/components/entry-form';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { nowFor } from '@/logbook/fields';
import { legs, type Stop } from '@/logbook/vehicle-trips';
import { currentFix, prepare } from '@/lib/trip-location';

/*
 * ADR-0032 §5: the points a trip stops at, added one by one as the driver
 * goes, offline. Arriving takes the time (and, if asked, the location);
 * leaving takes the time again. The server checks them against the trip.
 */

const clock = (iso: string) => nowFor('datetime', new Date(iso)).replace('T', ' ');

/** "YYYY-MM-DD HH:MM" on the phone's clock to ISO, or null. */
function parseLocal(value: string): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d)$/.exec(value.trim());
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
  return Number.isNaN(d.getTime()) || d.getMonth() !== Number(m[2]) - 1 ? null : d.toISOString();
}

type Draft = { place: string; purpose: string; odometer: string; note: string; arrived: string; departed: string; pin: Stop['pin'] };

const toDraft = (s: Stop): Draft => ({
  place: s.place,
  purpose: s.purpose ?? '',
  odometer: s.odometer === null ? '' : String(s.odometer),
  note: s.note ?? '',
  arrived: clock(s.arrived_at),
  departed: s.departed_at ? clock(s.departed_at) : '',
  pin: s.pin,
});

export function TripStops({
  stops,
  required,
  odometerStart,
  errors,
  onChange,
}: {
  stops: Stop[];
  required: boolean;
  odometerStart: number | null;
  errors: Record<string, string>;
  onChange: (stops: Stop[]) => void;
}) {
  // The stop being edited: its index, or stops.length for a new one.
  const [editing, setEditing] = useState<number | null>(null);
  const km = legs(stops, odometerStart);
  const open = stops.find((s) => s.departed_at === null);

  return (
    <Card className="gap-3">
      <View className="gap-0.5">
        <Text className="text-base font-medium text-slate-800">
          Stops ({stops.length}){required ? <Text className="text-red-600"> *</Text> : null}
        </Text>
        <Text className="text-sm text-slate-500">Every point you stop at, in order. Kept on this phone until it is sent.</Text>
      </View>
      {errors.stops ? <Text className="text-sm text-red-700">{errors.stops}</Text> : null}

      {stops.map((s, i) => {
        const problems = Object.entries(errors)
          .filter(([k]) => k.startsWith(`stops.${i}.`))
          .map(([, v]) => v);
        return (
          <View key={i} className={`gap-1 rounded-xl border p-3 ${problems.length ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white'}`}>
            <Text className="text-[15px] font-semibold text-slate-900">
              {i + 1}. {s.place || 'Unnamed place'}
              {s.purpose ? <Text className="font-normal text-slate-600"> · {s.purpose}</Text> : null}
            </Text>
            <Text className="text-sm text-slate-600">
              Arrived {clock(s.arrived_at).slice(11)}
              {s.departed_at ? `, left ${clock(s.departed_at).slice(11)}` : ', still here'}
              {s.odometer !== null ? ` · ${s.odometer} km` : ''}
              {km[i] !== null ? ` · ${km[i]} km from the previous point` : ''}
              {s.pin ? ' · location taken' : ''}
            </Text>
            {problems.map((p) => (
              <Text key={p} className="text-sm text-red-700">
                {p}
              </Text>
            ))}
            <View className="flex-row flex-wrap gap-2 pt-1">
              {s.departed_at === null && (
                <Button title="Leaving now" icon="exit-outline" onPress={() => onChange(stops.map((x, j) => (j === i ? { ...x, departed_at: new Date().toISOString() } : x)))} />
              )}
              <Button title="Change" variant="outline" onPress={() => setEditing(i)} />
            </View>
          </View>
        );
      })}

      <Button
        title={open ? 'Arrived at the next stop' : 'Arrived at a stop'}
        icon="flag-outline"
        variant={open ? 'outline' : 'primary'}
        size="lg"
        onPress={() => setEditing(stops.length)}
      />

      {editing !== null && (
        <StopSheet
          initial={editing < stops.length ? toDraft(stops[editing]) : null}
          onClose={() => setEditing(null)}
          onRemove={
            editing < stops.length
              ? () => {
                  onChange(stops.filter((_, j) => j !== editing));
                  setEditing(null);
                }
              : undefined
          }
          onSave={(stop) => {
            // A new arrival closes the stop still open: the vehicle left it.
            const closed = editing === stops.length ? stops.map((x) => (x.departed_at === null ? { ...x, departed_at: stop.arrived_at } : x)) : stops;
            onChange(editing < stops.length ? closed.map((x, j) => (j === editing ? stop : x)) : [...closed, stop]);
            setEditing(null);
          }}
        />
      )}
    </Card>
  );
}

function StopSheet({ initial, onSave, onClose, onRemove }: { initial: Draft | null; onSave: (stop: Stop) => void; onClose: () => void; onRemove?: () => void }) {
  const [d, setD] = useState<Draft>(() => initial ?? { place: '', purpose: '', odometer: '', note: '', arrived: clock(new Date().toISOString()), departed: '', pin: null });
  const [problem, setProblem] = useState<Record<string, string>>({});
  const [locating, setLocating] = useState(false);
  const set = (patch: Partial<Draft>) => setD((x) => ({ ...x, ...patch }));

  const locate = async () => {
    setLocating(true);
    try {
      const ready = await prepare();
      if (!ready.ok) {
        setProblem((p) => ({ ...p, pin: ready.message }));
        return;
      }
      const fix = await currentFix();
      set({ pin: { lat: fix.lat, lng: fix.lng, accuracy_m: fix.accuracy === null ? null : Math.round(fix.accuracy) } });
    } catch {
      setProblem((p) => ({ ...p, pin: 'No location could be found. Try again in the open.' }));
    } finally {
      setLocating(false);
    }
  };

  const save = () => {
    const arrived = parseLocal(d.arrived);
    const departed = d.departed.trim() === '' ? null : parseLocal(d.departed);
    const odo = d.odometer.trim() === '' ? null : Number(d.odometer.replace(',', '.'));
    const errors: Record<string, string> = {};
    if (d.place.trim() === '') errors.place = 'Name the place.';
    if (!arrived) errors.arrived = 'Enter the arrival (YYYY-MM-DD HH:MM).';
    if (d.departed.trim() !== '' && !departed) errors.departed = 'Enter the departure (YYYY-MM-DD HH:MM).';
    else if (arrived && departed && departed < arrived) errors.departed = 'The departure is before the arrival.';
    if (odo !== null && (Number.isNaN(odo) || odo < 0)) errors.odometer = 'Enter the odometer as a number.';
    setProblem(errors);
    if (Object.keys(errors).length > 0 || !arrived) return;
    onSave({
      place: d.place.trim(),
      arrived_at: arrived,
      departed_at: departed,
      odometer: odo,
      purpose: d.purpose.trim() || null,
      note: d.note.trim() || null,
      pin: d.pin,
    });
  };

  const field = (label: string, key: keyof Draft, extra: { keyboard?: 'decimal-pad' | 'numbers-and-punctuation'; placeholder?: string; now?: boolean } = {}) => (
    <View className="gap-1">
      <Text className="text-sm font-medium text-slate-700">{label}</Text>
      <View className="flex-row gap-2">
        <View className="flex-1">
          <Input value={String(d[key] ?? '')} keyboard={extra.keyboard} placeholder={extra.placeholder} onChange={(t) => set({ [key]: t } as Partial<Draft>)} />
        </View>
        {extra.now && <Button title="Now" variant="outline" onPress={() => set({ [key]: clock(new Date().toISOString()) } as Partial<Draft>)} />}
      </View>
      {problem[key] ? <Text className="text-sm text-red-700">{problem[key]}</Text> : null}
    </View>
  );

  return (
    <Sheet visible onClose={onClose} title={initial ? 'Change the stop' : 'Arrived at a stop'}>
      <View className="gap-3">
        {field('Place', 'place', { placeholder: 'Where you stopped' })}
        {field('Purpose (optional)', 'purpose', { placeholder: 'Delivery, pick-up, fuel…' })}
        {field('Arrived', 'arrived', { keyboard: 'numbers-and-punctuation', placeholder: 'YYYY-MM-DD HH:MM', now: true })}
        {field('Left (optional)', 'departed', { keyboard: 'numbers-and-punctuation', placeholder: 'Empty while still here', now: true })}
        {field('Odometer (optional, km)', 'odometer', { keyboard: 'decimal-pad' })}
        {field('Note (optional)', 'note')}
        <View className="gap-1">
          <Text className="text-sm font-medium text-slate-700">Location (optional)</Text>
          {d.pin ? (
            <View className="flex-row items-center gap-2">
              <Text className="flex-1 text-sm text-slate-700">
                {d.pin.lat.toFixed(5)}, {d.pin.lng.toFixed(5)}
                {d.pin.accuracy_m !== null ? ` · ±${d.pin.accuracy_m} m` : ''}
              </Text>
              <Button title="Remove" variant="ghost" onPress={() => set({ pin: null })} />
            </View>
          ) : (
            <Button title="Take my location" icon="locate-outline" variant="outline" loading={locating} onPress={() => void locate()} />
          )}
          {problem.pin ? <Text className="text-sm text-red-700">{problem.pin}</Text> : null}
        </View>
        <Button title="Save the stop" icon="checkmark" size="lg" onPress={save} />
        {onRemove && <Button title="Remove this stop" icon="trash-outline" variant="ghost" onPress={onRemove} />}
      </View>
    </Sheet>
  );
}
