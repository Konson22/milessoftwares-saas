import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { distance, duration, syncLabel } from '@/components/trip-format';
import { Button } from '@/components/ui/button';
import { Badge, Banner, Card, Row, SectionTitle } from '@/components/ui/misc';
import { STORAGE_WARNING, type LocalTrack } from '@/logbook/tracks';
import type { KitSheet } from '@/logbook/types';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useTrips } from '@/store/trips';

/*
 * A trip (ADR-0028): choose the log sheet and the vehicle, read what is
 * recorded, then start. While it records, the phone shows the notification
 * and this screen shows the time, the distance, the last position and what
 * is still to send. Pause and End stop the location service.
 */

export default function TripScreen() {
  const insets = useSafeAreaInsets();
  const active = useTrips((s) => s.active);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Trip" subtitle={active ? `${active.sheet.code} · ${active.logbook}` : 'Record a route'} icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
        {active ? <ActiveTrip trip={active} /> : <StartTrip />}
        <Button title="Earlier trips" icon="list-outline" variant="ghost" onPress={() => router.push('/trips')} />
      </ScrollView>
    </View>
  );
}

function StartTrip() {
  const kit = useLogbook((s) => s.kit);
  const start = useTrips((s) => s.start);
  const sheets = (kit?.sheets ?? []).filter((s) => s.location?.mode === 'route' && s.version.id);
  const [sheet, setSheet] = useState<KitSheet | null>(sheets.length === 1 ? sheets[0] : null);
  const [resource, setResource] = useState<string | null>(null);
  const [read, setRead] = useState(false);
  const [busy, setBusy] = useState(false);
  const resources = kit?.resources ?? [];
  const picked = resources.find((r) => r.id === resource) ?? null;
  const needsResource = sheet?.location?.resource_required ?? false;

  if (sheets.length === 0) {
    return <Banner tone="info">No log sheet here records routes. An administrator can turn it on for a sheet, under Location in its template.</Banner>;
  }

  return (
    <>
      <SectionTitle>Log sheet</SectionTitle>
      <View className="gap-2">
        {sheets.map((s) => (
          <Choice key={s.id} selected={sheet?.id === s.id} title={`${s.code} · ${s.name}`} subtitle={s.logbook.name} onPress={() => setSheet(s)} />
        ))}
      </View>

      {sheet && resources.length > 0 && (
        <>
          <SectionTitle>Vehicle or resource{needsResource ? ' (required)' : ''}</SectionTitle>
          <View className="gap-2">
            {!needsResource && <Choice selected={resource === null} title="None" onPress={() => setResource(null)} />}
            {resources.map((r) => (
              <Choice key={r.id} selected={resource === r.id} title={`${r.code} · ${r.name}`} subtitle={r.type} onPress={() => setResource(r.id)} />
            ))}
          </View>
        </>
      )}

      <Card className="gap-2">
        <Text className="text-base font-semibold text-slate-900">Before you start</Text>
        <Text className="text-sm text-slate-700">
          While the trip records, Miles Logbook reads this phone&apos;s location every few seconds, also when the screen is off or another app is open. Android shows a
          notification the whole time.
        </Text>
        <Text className="text-sm text-slate-700">
          Recording stops when you pause or end the trip. Nothing is read before you press Start, and it never starts by itself.
        </Text>
        <Text className="text-sm text-slate-700">
          The route is sent to your workspace. You, managers and people allowed to see routes in this logbook can see it. It is kept for the time your workspace sets,
          then removed.
        </Text>
        <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: read }} onPress={() => setRead(!read)} className="mt-1 flex-row items-center gap-2 py-1">
          <View className={`size-5 items-center justify-center rounded border ${read ? 'border-brand-600 bg-brand-600' : 'border-slate-400 bg-white'}`}>
            {read && <Text className="text-xs font-bold text-white">✓</Text>}
          </View>
          <Text className="flex-1 text-sm text-slate-800">I understand. Record my route for this trip.</Text>
        </Pressable>
      </Card>

      <Button
        title="Start trip"
        icon="navigate-outline"
        size="lg"
        loading={busy}
        disabled={!sheet || !read || (needsResource && !picked)}
        onPress={() => {
          if (!sheet) return;
          setBusy(true);
          start(sheet, picked ? { id: picked.id, label: `${picked.code} · ${picked.name}` } : null)
            .then(() => toast('Recording. Pause or end the trip here.'))
            .catch((e: unknown) => toast(e instanceof Error ? e.message : 'Could not start the trip.', 'error'))
            .finally(() => setBusy(false));
        }}
      />
    </>
  );
}

function ActiveTrip({ trip }: { trip: LocalTrack }) {
  const { interrupted, problem, unsent, pause, resume, end, check, sync } = useTrips();
  const [busy, setBusy] = useState(false);
  const [confirmEnd, setConfirmEnd] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1_000);
    void check();
    return () => clearInterval(timer);
  }, [check]);

  const act = (work: () => Promise<void>, done?: string) => {
    setBusy(true);
    work()
      .then(() => done && toast(done))
      .catch((e: unknown) => toast(e instanceof Error ? e.message : 'That did not work.', 'error'))
      .finally(() => setBusy(false));
  };

  const elapsed = Math.max(0, Math.round((now - new Date(trip.startedAt).getTime()) / 1000));
  const lastAge = trip.lastPointAt ? Math.round((now - new Date(trip.lastPointAt).getTime()) / 1000) : null;
  const recording = trip.status === 'recording';

  return (
    <>
      <View className="flex-row items-center gap-2">
        <Badge label={recording ? 'Recording' : interrupted ? 'Interrupted' : 'Paused'} tone={recording ? 'green' : 'amber'} />
        <Text className="text-sm text-slate-600">{trip.resource?.label ?? 'No vehicle chosen'}</Text>
      </View>
      {interrupted && (
        <Banner tone="warning">The phone stopped the recording (the app was closed or the phone restarted). Nothing was recorded since. Resume to go on, or end the trip.</Banner>
      )}
      {problem && <Banner tone="error">{problem}</Banner>}
      {recording && lastAge !== null && lastAge > 120 && <Banner tone="warning">No position for {duration(lastAge)}. The phone may be indoors or without GPS signal.</Banner>}
      {unsent > STORAGE_WARNING && <Banner tone="warning">Many positions are waiting to be sent. Connect to the internet when you can.</Banner>}

      <Card className="gap-1">
        <Row label="Started" value={when(trip.startedAt)} />
        <Row label="Time" value={duration(elapsed)} strong />
        <Row label="Distance (estimate)" value={distance(trip.distanceM)} strong />
        <Row label="Positions" value={String(trip.points)} />
        <Row label="Last position" value={trip.lastFix ? `${lastAge !== null ? `${duration(lastAge)} ago` : ''}${trip.lastFix.accuracy !== null ? ` · ±${Math.round(trip.lastFix.accuracy)} m` : ''}` : 'None yet'} />
        <Row label="Sent" value={`${syncLabel[trip.sync]}${trip.nextSeq - 1 - trip.sentSeq > 0 ? ` · ${trip.nextSeq - 1 - trip.sentSeq} to send` : ''}`} />
      </Card>
      {trip.sync === 'action_required' && trip.error && <Banner tone="error">{trip.error.message}</Banner>}
      {trip.sync === 'failed' && trip.error?.code === 'signed_out' && <Banner tone="warning">{trip.error.message}</Banner>}

      <View className="gap-2">
        <Button title="Write an entry on this trip" icon="create-outline" variant="outline" onPress={() => router.push('/new')} />
        {recording ? (
          <Button title="Pause" icon="pause-outline" variant="outline" size="lg" loading={busy} onPress={() => act(pause, 'Paused: nothing is recorded until you resume.')} />
        ) : (
          <Button title="Resume" icon="play-outline" size="lg" loading={busy} onPress={() => act(resume, 'Recording again.')} />
        )}
        {confirmEnd ? (
          <Card className="gap-2">
            <Text className="text-sm text-slate-800">End this trip? Recording stops and the route is sent. It cannot be resumed afterwards.</Text>
            <View className="flex-row gap-2">
              <Button title="End trip" icon="stop-outline" variant="danger" loading={busy} onPress={() => act(end, 'Trip ended.')} />
              <Button title="Keep going" variant="ghost" disabled={busy} onPress={() => setConfirmEnd(false)} />
            </View>
          </Card>
        ) : (
          <Button title="End trip" icon="stop-outline" variant="ghost" disabled={busy} onPress={() => setConfirmEnd(true)} />
        )}
        {trip.sync !== 'synced' && <Button title="Send now" icon="cloud-upload-outline" variant="ghost" disabled={busy} onPress={() => void sync()} />}
      </View>
    </>
  );
}

function Choice({ title, subtitle, selected, onPress }: { title: string; subtitle?: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected }}
      onPress={onPress}
      className={`rounded-md border px-3 py-3 ${selected ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white'}`}>
      <Text className="text-base font-medium text-slate-900">{title}</Text>
      {subtitle ? <Text className="text-sm text-slate-500">{subtitle}</Text> : null}
    </Pressable>
  );
}
