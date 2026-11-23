import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { when } from '@/components/entries';
import { Button } from '@/components/ui/button';
import { currentFix, prepare } from '@/lib/trip-location';
import type { LocalTrack } from '@/logbook/tracks';
import type { EntryLocation, LocationSettings } from '@/logbook/types';

/**
 * An entry's location (ADR-0028): read only when the person presses the
 * button, shown as taken, never made up. While a trip is open the entry
 * can be linked to it.
 */
export function LocationControl({
  settings,
  value,
  trackId,
  trip,
  error,
  onChange,
  onTrack,
}: {
  settings: LocationSettings;
  value: EntryLocation | null;
  trackId: string | null;
  /** The trip open on this phone, when in the same logbook. */
  trip: LocalTrack | null;
  error?: string;
  onChange: (value: EntryLocation | null) => void;
  onTrack: (id: string | null) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const take = async () => {
    setBusy(true);
    setProblem(null);
    try {
      const ready = await prepare();
      if (!ready.ok) {
        setProblem(ready.message);
        return;
      }
      const fix = await currentFix();
      onChange({ lat: fix.lat, lng: fix.lng, accuracy_m: fix.accuracy, captured_at: fix.at });
    } catch {
      setProblem('No location could be found. Try again outside or near a window.');
    } finally {
      setBusy(false);
    }
  };

  const rough = value?.accuracy_m != null && settings.accuracy_m != null && value.accuracy_m > settings.accuracy_m;

  return (
    <View className="gap-2 rounded-xl border border-slate-200 bg-white p-3">
      <Text className="text-base font-medium text-slate-800">
        Location
        {settings.required ? <Text className="text-red-600"> *</Text> : <Text className="text-sm font-normal text-slate-500"> (optional)</Text>}
      </Text>
      {value ? (
        <Text className="text-sm text-slate-700">
          {value.lat.toFixed(5)}, {value.lng.toFixed(5)}
          {value.accuracy_m != null ? ` · ±${Math.round(value.accuracy_m)} m` : ''} · taken {when(value.captured_at)}
        </Text>
      ) : (
        <Text className="text-sm text-slate-500">Not taken. The phone reads your location only when you press the button.</Text>
      )}
      {rough && <Text className="text-sm text-amber-800">Less accurate than the {settings.accuracy_m} m this sheet asks for. Try again if you can.</Text>}
      <View className="flex-row gap-2">
        <Button title={value ? 'Take again' : 'Take location'} icon="locate-outline" variant="outline" loading={busy} onPress={() => void take()} />
        {value && <Button title="Remove" variant="ghost" disabled={busy} onPress={() => onChange(null)} />}
      </View>
      {trip && (
        <Pressable
          accessibilityRole="checkbox"
          accessibilityState={{ checked: trackId === trip.id }}
          onPress={() => onTrack(trackId === trip.id ? null : trip.id)}
          className="flex-row items-center gap-2 py-1">
          <View className={`size-5 items-center justify-center rounded border ${trackId === trip.id ? 'border-brand-600 bg-brand-600' : 'border-slate-400 bg-white'}`}>
            {trackId === trip.id && <Text className="text-xs font-bold text-white">✓</Text>}
          </View>
          <Text className="flex-1 text-sm text-slate-700">Written on the trip in progress ({trip.resource?.label ?? trip.sheet.code})</Text>
        </Pressable>
      )}
      {problem && <Text className="text-sm text-red-700">{problem}</Text>}
      {error && <Text className="text-sm text-red-700">{error}</Text>}
    </View>
  );
}
