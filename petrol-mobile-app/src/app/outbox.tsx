import { router } from 'expo-router';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Panel } from '@/components/pages/kit';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { confirm } from '@/lib/feedback';
import { dateTime, time } from '@/lib/format';
import { queuedFor, useOutbox, type Entry } from '@/offline/outbox';
import { syncNow, useSync } from '@/offline/sync';
import { useAuth } from '@/store/auth';

/**
 * What this phone did without a connection: waiting to be sent, refused by
 * the station (tried again, or let go), and recently sent.
 */
export default function OutboxScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const owner = useAuth((s) => (s.workspace && s.branchId && s.me ? `${s.workspace}|${s.branchId}|${s.me.user.id}` : null));
  const entries = useOutbox((s) => s.entries);
  const allSent = useOutbox((s) => s.sent);
  const running = useSync((s) => s.running);
  const lastSyncAt = useSync((s) => s.lastSyncAt);
  const { waiting, refused } = queuedFor(entries, owner);
  const sent = allSent.filter((s) => s.owner === owner).slice(0, 15);

  const letGo = async (entry: Entry) => {
    const ok = await confirm(
      'Let it go?',
      `“${entry.label}” will not be sent: the station never hears of it.`,
      'Let go',
      true,
    );
    if (ok) useOutbox.getState().remove(entry.id);
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Waiting to send" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} contentContainerClassName="w-full max-w-xl gap-4 self-center p-4">
        <Banner tone={reachable ? 'info' : 'warning'}>
          {reachable
            ? `Connected.${lastSyncAt ? ` Last in step at ${time(lastSyncAt)}.` : ''}`
            : 'No connection to the station. Sales are kept on this phone and sent when the connection is back.'}
        </Banner>
        <Button title={running ? 'Sending…' : 'Send now'} icon="cloud-upload-outline" loading={running} disabled={!reachable || waiting.length === 0} onPress={() => void syncNow()} />

        {refused.length > 0 ? (
          <Panel title={`Not sent (${refused.length})`}>
            {refused.map((entry) => (
              <View key={entry.id} className="gap-2 border-b border-slate-100 px-4 py-3">
                <Text className="text-base font-semibold text-slate-900">{entry.label}</Text>
                <Text className="text-sm text-red-700">{entry.error}</Text>
                <Text className="text-xs text-slate-500">Done {dateTime(entry.queued_at)}</Text>
                <View className="flex-row gap-2">
                  <Button className="flex-1" title="Try again" icon="refresh" variant="outline" onPress={() => useOutbox.getState().retry(entry.id)} />
                  <Button className="flex-1" title="Let go" icon="trash-outline" variant="danger" onPress={() => void letGo(entry)} />
                </View>
              </View>
            ))}
          </Panel>
        ) : null}

        <Panel title={`Waiting (${waiting.length})`}>
          {waiting.length === 0 ? (
            <Text className="px-4 py-5 text-center text-base text-slate-500">Nothing waiting: the station has everything.</Text>
          ) : (
            waiting.map((entry) => (
              <View key={entry.id} className="border-b border-slate-100 px-4 py-2.5">
                <Text className="text-base text-slate-900">{entry.label}</Text>
                <Text className="text-xs text-slate-500">
                  {time(entry.queued_at)}
                  {entry.attempts > 0 ? ` · tried ${entry.attempts}×` : ''}
                </Text>
              </View>
            ))
          )}
        </Panel>

        {sent.length > 0 ? (
          <Panel title="Sent">
            {sent.map((s) => (
              <View key={s.id} className="border-b border-slate-100 px-4 py-2.5">
                <Text className="text-base text-slate-700">{s.label}</Text>
                <Text className="text-xs text-slate-500">Sent {time(s.sent_at)}</Text>
              </View>
            ))}
          </Panel>
        ) : null}
      </ScrollView>
    </View>
  );
}
