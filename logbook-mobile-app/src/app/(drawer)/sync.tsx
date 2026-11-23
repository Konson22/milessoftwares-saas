import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ConnectionPill, EntryRow, StatCard, when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, ListGroup, ListItem } from '@/components/ui/misc';
import { buckets } from '@/logbook/sync';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';

/**
 * Sending and downloading, in the open. Whether the server has an entry
 * (the phone's job) is kept apart from what reviewers decided.
 */
export default function SyncScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const needsSignIn = useAuth((s) => s.needsSignIn);
  const kit = useLogbook((s) => s.kit);
  const items = useLogbook((s) => s.items);
  const downloaded = useLogbook((s) => s.downloaded);
  const syncing = useLogbook((s) => s.syncing);
  const downloading = useLogbook((s) => s.downloading);
  const lastSyncAt = useLogbook((s) => s.lastSyncAt);
  const lastKitAt = useLogbook((s) => s.lastKitAt);
  const lastSync = useLogbook((s) => s.lastSync);
  const kitError = useLogbook((s) => s.kitError);
  const sync = useLogbook((s) => s.sync);
  const retryFailed = useLogbook((s) => s.retryFailed);
  const downloadSheets = useLogbook((s) => s.downloadSheets);
  const refresh = useLogbook((s) => s.refresh);
  const [retrying, setRetrying] = useState(false);

  const b = buckets(items);
  const failed = b.waiting.filter((i) => i.status === 'failed');
  const problems = [...b.refused, ...failed].sort((x, y) => y.updatedAt.localeCompare(x.updatedAt));
  const sheetsOnPhone = (kit?.sheets ?? []).filter((s) => s.version.id && downloaded.includes(s.version.id)).length;

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="Sync" subtitle={lastSyncAt ? `Last successful sync ${when(lastSyncAt)}` : 'Not synchronized yet'} />
      <ScrollView
        contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}>
        <ConnectionPill />
        {needsSignIn && <Banner tone="warning">Your sign-in has expired. Sign in again from Account to send. Nothing is lost.</Banner>}
        {!reachable && <Banner tone="info">Offline. Everything you finish is queued and sent by itself when the connection is back.</Banner>}
        {lastSync && lastSync.failed > 0 && reachable && (
          <Banner tone="warning">
            {lastSync.failed} {lastSync.failed === 1 ? 'entry' : 'entries'} could not be sent on the last try. They are retried by themselves.
          </Banner>
        )}

        <View className="gap-2">
          <Button title={syncing ? 'Syncing…' : 'Sync now'} icon="sync" size="lg" loading={syncing} onPress={() => void sync({ pull: true })} />
          <View className="flex-row gap-2">
            <View className="flex-1">
              <Button
                title="Retry failed"
                icon="refresh"
                variant="outline"
                loading={retrying}
                disabled={failed.length === 0}
                onPress={() => {
                  setRetrying(true);
                  void retryFailed()
                    .then((n) => toast(n ? `Retrying ${n} ${n === 1 ? 'entry' : 'entries'}.` : 'Nothing to retry.'))
                    .finally(() => setRetrying(false));
                }}
              />
            </View>
            <View className="flex-1">
              <Button
                title="Update sheets"
                icon="cloud-download-outline"
                variant="outline"
                loading={downloading}
                onPress={() => void downloadSheets().then((ok) => toast(ok ? 'Log sheets are up to date.' : 'Could not reach the server.', ok ? 'success' : 'error'))}
              />
            </View>
          </View>
        </View>

        <View className="gap-2">
          <View className="flex-row gap-2">
            <StatCard icon="cloud-upload" color="sky" label="Waiting to send" value={b.waiting.length} />
            <StatCard icon="arrow-undo" color="red" label="Needs you" value={b.returned.length + b.refused.length} />
          </View>
          <View className="flex-row gap-2">
            <StatCard icon="create" color="slate" label="Drafts" value={b.drafts.length} />
            <StatCard icon="hourglass" color="amber" label="Sent, awaiting review" value={b.inReview.length} />
          </View>
        </View>

        <ListGroup title="Log sheets">
          <ListItem
            icon="albums"
            color="violet"
            title="On this phone"
            detail={`${lastKitAt ? `Updated ${when(lastKitAt)}` : 'Not downloaded yet'}${kitError && lastKitAt ? ` · last update failed: ${kitError}` : ''}`}
            right={<Text className="text-base font-bold text-slate-900">{`${sheetsOnPhone} of ${kit?.sheets.length ?? 0}`}</Text>}
          />
        </ListGroup>

        {problems.length > 0 && (
          <View className="gap-2">
            <Text accessibilityRole="header" className="px-1 text-base font-bold text-slate-900">Not sent</Text>
            {problems.map((item) => (
              <EntryRow key={item.id} item={item} />
            ))}
          </View>
        )}
      </ScrollView>
    </View>
  );
}
