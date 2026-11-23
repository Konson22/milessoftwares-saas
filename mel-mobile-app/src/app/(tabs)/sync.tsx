import { Ionicons } from '@expo/vector-icons';
import { useMemo, type ComponentProps } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ConnectionPill, RecordRow, when } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, SectionTitle } from '@/components/ui/misc';
import { buckets, syncSummary } from '@/collect/overview';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useCollect } from '@/store/collect';
import { toast } from '@/store/toast';

/**
 * Sending and downloading, in the open. Two separate ledgers:
 * - Upload: whether the server has the record (the phone's job).
 * - Review: what the reviewers decided (never implied by an upload).
 */
export default function SyncScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const needsSignIn = useAuth((s) => s.needsSignIn);
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const downloaded = useCollect((s) => s.downloaded);
  const syncing = useCollect((s) => s.syncing);
  const downloading = useCollect((s) => s.downloading);
  const lastSyncAt = useCollect((s) => s.lastSyncAt);
  const lastKitAt = useCollect((s) => s.lastKitAt);
  const lastSync = useCollect((s) => s.lastSync);
  const kitError = useCollect((s) => s.kitError);
  const sync = useCollect((s) => s.sync);
  const retryFailed = useCollect((s) => s.retryFailed);
  const downloadForms = useCollect((s) => s.downloadForms);
  const refresh = useCollect((s) => s.refresh);

  const s = useMemo(() => syncSummary(items, kit, new Set(downloaded)), [items, kit, downloaded]);
  const b = buckets(items);
  const problems = [...b.refused, ...b.failed].sort((x, y) => y.updatedAt.localeCompare(x.updatedAt));

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Sync" subtitle={lastSyncAt ? `Last successful sync ${when(lastSyncAt)}` : 'Not synchronized yet'} />
      <ScrollView
        contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}>
        <ConnectionPill />
        {needsSignIn && <Banner tone="warning">Your sign-in has expired. Sign in again from Account to send. Nothing is lost.</Banner>}
        {!reachable && <Banner tone="info">Offline. Everything you finish is queued and sent by itself when the connection is back.</Banner>}
        {lastSync && lastSync.failed > 0 && reachable && <Banner tone="warning">{lastSync.failed} {lastSync.failed === 1 ? 'record' : 'records'} could not be sent on the last try. They are retried by themselves.</Banner>}

        <View className="gap-2">
          <Button title={syncing ? 'Syncing…' : 'Sync now'} icon="sync" size="lg" loading={syncing} onPress={() => void sync({ pull: true })} />
          <View className="flex-row gap-2">
            <Button
              title="Retry failed"
              icon="refresh"
              variant="outline"
              size="lg"
              className="flex-1"
              disabled={b.failed.length === 0 || syncing}
              onPress={() =>
                void retryFailed().then((n) => toast(n ? `Retrying ${n} ${n === 1 ? 'record' : 'records'}.` : 'Nothing to retry.', 'success'))
              }
            />
            <Button
              title="Update forms"
              icon="cloud-download-outline"
              variant="outline"
              size="lg"
              className="flex-1"
              loading={downloading}
              onPress={() => void downloadForms().then((ok) => toast(ok ? 'Forms are up to date.' : 'Could not reach the server. The forms on this phone still work.', ok ? 'success' : 'error'))}
            />
          </View>
        </View>

        <View>
          <SectionTitle>Upload</SectionTitle>
          <View className="border border-slate-200 bg-white px-4 py-2">
            <Line icon="time-outline" label="Ready to send" value={s.pending} />
            <Line icon="arrow-up-circle-outline" label="Sending now" value={s.sending} />
            <Line icon="alert-circle-outline" label="Failed, retried by itself" value={s.failed} tone={s.failed ? 'amber' : undefined} />
            <Line icon="close-circle-outline" label="Refused, needs you" value={s.refused} tone={s.refused ? 'red' : undefined} />
            <Line icon="cloud-done-outline" label="Received by the server" value={s.uploaded} last />
          </View>
        </View>

        <View>
          <SectionTitle>Review of uploaded records</SectionTitle>
          <View className="border border-slate-200 bg-white px-4 py-2">
            <Line icon="hourglass-outline" label="Verification pending" value={s.review.pending} />
            <Line icon="checkmark-circle-outline" label="Approved" value={s.review.approved} tone={s.review.approved ? 'green' : undefined} />
            <Line icon="create-outline" label="Correction required" value={s.review.correction} tone={s.review.correction ? 'red' : undefined} />
            <Line icon="ban-outline" label="Rejected" value={s.review.rejected} last />
          </View>
          <Text className="mt-1.5 text-xs text-slate-500">Uploaded means the server has the record. Only a reviewer approves it.</Text>
        </View>

        <View>
          <SectionTitle>Forms</SectionTitle>
          <View className="border border-slate-200 bg-white px-4 py-2">
            <Line icon="phone-portrait-outline" label="On this phone, usable offline" value={s.formsOnPhone} />
            <Line icon="document-text-outline" label="Assigned to you" value={s.formsAssigned} />
            <Text className="py-2 text-xs text-slate-500">{lastKitAt ? `Downloaded ${when(lastKitAt)}` : 'Not downloaded yet'}</Text>
          </View>
          {kitError && <Text className="mt-1.5 text-xs text-amber-800">Last download failed: {kitError}</Text>}
        </View>

        {problems.length > 0 && (
          <View>
            <SectionTitle>Sync errors</SectionTitle>
            <View className="gap-2">
              {problems.map((item) => (
                <RecordRow key={item.id} item={item} />
              ))}
            </View>
          </View>
        )}
      </ScrollView>
    </View>
  );
}

function Line({ icon, label, value, tone, last }: { icon: ComponentProps<typeof Ionicons>['name']; label: string; value: number; tone?: 'amber' | 'red' | 'green'; last?: boolean }) {
  const color = tone === 'red' ? colors.dangerDark : tone === 'amber' ? colors.warning : tone === 'green' ? colors.successDark : colors.muted;
  return (
    <View className={`min-h-11 flex-row items-center gap-3 ${last ? '' : 'border-b border-slate-100'}`} accessible accessibilityLabel={`${label}: ${value}`}>
      <Ionicons name={icon} size={20} color={color} />
      <Text className="flex-1 text-base text-slate-700">{label}</Text>
      <Text className="text-base font-bold text-slate-900">{value}</Text>
    </View>
  );
}
