import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { SectionList, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Badge, EmptyState } from '@/components/ui/misc';
import { actionText, SERVER_LABEL, STATUS_LABEL, STATUS_TONE } from '@/collect/labels';
import type { Item, LocalStatus } from '@/collect/types';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useCollect } from '@/store/collect';

const SECTIONS: { title: string; statuses: LocalStatus[] }[] = [
  { title: 'Action required', statuses: ['action_required'] },
  { title: 'Drafts', statuses: ['draft'] },
  { title: 'Waiting to send', statuses: ['ready', 'syncing', 'failed'] },
  { title: 'Sent', statuses: ['synced'] },
];

function when(iso: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

/** Everything collected on this phone: drafts, the queue, what needs the collector, and the sent history. */
export default function OutboxScreen() {
  const insets = useSafeAreaInsets();
  const items = useCollect((s) => s.items);
  const syncing = useCollect((s) => s.syncing);
  const lastSyncAt = useCollect((s) => s.lastSyncAt);
  const sync = useCollect((s) => s.sync);
  const reachable = useAuth((s) => s.reachable);

  const sections = SECTIONS.map((s) => ({
    title: s.title,
    data: items.filter((i) => s.statuses.includes(i.status)).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)),
  })).filter((s) => s.data.length > 0);

  const waiting = items.some((i) => ['ready', 'failed', 'syncing'].includes(i.status));

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Your records" subtitle={lastSyncAt ? `Last sent ${when(lastSyncAt)}` : reachable ? 'Online' : 'Offline'} icon="arrow-back" onIcon={() => router.back()} />
      <SectionList
        sections={sections}
        keyExtractor={(i) => i.id}
        contentContainerStyle={{ padding: 16, gap: 8, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
        ListHeaderComponent={
          waiting ? (
            <Button title={syncing ? 'Sending…' : 'Send now'} icon="cloud-upload-outline" loading={syncing} onPress={() => void sync()} className="mb-2" />
          ) : null
        }
        ListEmptyComponent={<EmptyState icon="file-tray-outline" title="Nothing yet" message="Records you start appear here until they are sent." />}
        renderSectionHeader={({ section }) => <Text className="mt-3 text-sm font-semibold uppercase text-slate-500">{section.title}</Text>}
        renderItem={({ item }) => <Row item={item} />}
      />
    </View>
  );
}

function Row({ item }: { item: Item }) {
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => router.push({ pathname: '/fill/[id]', params: { id: item.id } })}
      className="gap-1 border border-slate-200 bg-white p-3 active:bg-brand-50">
      <View className="flex-row items-center gap-2">
        <Text className="flex-1 text-base font-semibold text-slate-800" numberOfLines={1}>
          {item.formName}
        </Text>
        <Badge label={STATUS_LABEL[item.status]} tone={STATUS_TONE[item.status]} />
        <Ionicons name="chevron-forward" size={18} color={colors.faint} />
      </View>
      <Text className="text-xs text-slate-500">
        Started {when(item.startedAt)}
        {item.status === 'synced' && item.serverStatus ? ` · ${SERVER_LABEL[item.serverStatus] ?? item.serverStatus}` : ''}
        {item.status === 'failed' && item.nextAttemptAt ? ` · next try ${when(item.nextAttemptAt)}` : ''}
      </Text>
      {item.status === 'action_required' && <Text className="text-sm text-red-700">{actionText(item)}</Text>}
      {item.status === 'failed' && item.error && <Text className="text-sm text-amber-800">{item.error.message}</Text>}
    </Pressable>
  );
}
