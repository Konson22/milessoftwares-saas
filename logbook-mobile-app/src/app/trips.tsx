import { router } from 'expo-router';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { distance, syncLabel } from '@/components/trip-format';
import { Button } from '@/components/ui/button';
import { Badge, Card, EmptyState } from '@/components/ui/misc';
import { KEEP_DAYS } from '@/logbook/tracks';
import { useTrips } from '@/store/trips';

/** Trips recorded on this phone for the person signed in, newest first. */
export default function TripsScreen() {
  const insets = useSafeAreaInsets();
  const { history, active, retry, syncing } = useTrips();
  const trips = active ? [active, ...history] : history;
  const waiting = trips.some((t) => t.sync === 'failed' || t.sync === 'pending');

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Trips" subtitle="Recorded on this phone" icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 12, paddingBottom: insets.bottom + 24 }}>
        {trips.length === 0 ? (
          <EmptyState icon="navigate-outline" title="No trips yet">
            Start one from Trip on the home screen.
          </EmptyState>
        ) : (
          trips.map((t) => (
            <Card key={t.id} className="gap-1">
              <View className="flex-row items-center justify-between gap-2">
                <Text className="flex-1 text-base font-medium text-slate-900">{t.resource?.label ?? `${t.sheet.code} · ${t.sheet.name}`}</Text>
                <Badge label={t.status === 'ended' ? 'Ended' : t.status === 'recording' ? 'Recording' : 'Paused'} tone={t.status === 'ended' ? 'neutral' : 'amber'} />
              </View>
              <Text className="text-sm text-slate-600">
                {when(t.startedAt)}
                {t.endedAt ? ` to ${when(t.endedAt)}` : ''} · {distance(t.distanceM)} · {t.points} positions
              </Text>
              <Text className={`text-sm ${t.sync === 'action_required' ? 'text-red-700' : t.sync === 'synced' ? 'text-emerald-700' : 'text-amber-800'}`}>
                {syncLabel[t.sync]}
                {t.error && t.sync !== 'synced' ? `: ${t.error.message}` : ''}
              </Text>
            </Card>
          ))
        )}
        {waiting && <Button title="Retry now" icon="refresh-outline" variant="outline" loading={syncing} onPress={() => void retry()} />}
        <Text className="text-xs text-slate-500">Ended trips your workspace has received leave this phone after {KEEP_DAYS} days. The full route stays in Miles Logbook on the web.</Text>
      </ScrollView>
    </View>
  );
}
