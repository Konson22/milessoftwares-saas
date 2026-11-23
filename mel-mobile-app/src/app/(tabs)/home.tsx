import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ConnectionPill, RecordRow, StatCard, today, when } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { IconButton } from '@/components/ui/button';
import { Banner, SectionTitle } from '@/components/ui/misc';
import { buckets, purposeLabel, taskRows } from '@/collect/overview';
import { BRAND } from '@/lib/brand';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useCollect } from '@/store/collect';

/**
 * The fieldworker's workspace. It answers one question: what should I do
 * next? Corrections first, then unfinished drafts, then open tasks.
 */
export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const me = useAuth((s) => s.me);
  const needsSignIn = useAuth((s) => s.needsSignIn);
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const downloaded = useCollect((s) => s.downloaded);
  const syncing = useCollect((s) => s.syncing);
  const kitError = useCollect((s) => s.kitError);
  const lastSyncAt = useCollect((s) => s.lastSyncAt);
  const refresh = useCollect((s) => s.refresh);

  const b = buckets(items);
  const rows = useMemo(() => taskRows(kit, items, new Set(downloaded), today()), [kit, items, downloaded]);
  const open = rows.filter((r) => r.state === 'open' || r.state === 'closing');
  const dueSoon = open.filter((r) => r.daysLeft !== null && r.daysLeft >= 0 && r.daysLeft <= 7);
  const latestDraft = [...b.drafts].sort((x, y) => y.updatedAt.localeCompare(x.updatedAt))[0];
  const firstName = me?.user.name.split(' ')[0];

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        title={firstName ? `Hello, ${firstName}` : BRAND.name}
        subtitle={me?.organization.name}
        right={<IconButton icon="person-circle-outline" label="Account and about" onPress={() => router.push('/account')} onDark />}
      />
      <ScrollView
        contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}>
        <View className="flex-row flex-wrap items-center justify-between gap-2">
          <ConnectionPill />
          <Text className="text-xs text-slate-500">{lastSyncAt ? `Last sync ${when(lastSyncAt)}` : 'Not synchronized yet'}</Text>
        </View>

        {needsSignIn && <Banner tone="warning">Sign in again to send your records. Nothing is lost: they stay on this phone.</Banner>}
        {kitError && !kit && <Banner tone="error">{kitError}</Banner>}
        {me && !me.canCollect && <Banner tone="warning">Your role here does not allow collecting data. Ask your coordinator.</Banner>}

        <View className="gap-2">
          <View className="flex-row gap-2">
            <StatCard icon="alert-circle-outline" label="Need correction" value={b.corrections.length} tone={b.corrections.length ? 'red' : 'neutral'} onPress={() => router.push({ pathname: '/forms', params: { show: 'attention' } })} />
            <StatCard icon="create-outline" label="Drafts" value={b.drafts.length} tone={b.drafts.length ? 'amber' : 'neutral'} onPress={() => router.push({ pathname: '/forms', params: { show: 'drafts' } })} />
          </View>
          <View className="flex-row gap-2">
            <StatCard icon="checkbox-outline" label="Open tasks" value={open.length} onPress={() => router.push('/tasks')} />
            <StatCard icon="cloud-upload-outline" label="Waiting to sync" value={b.waiting.length + b.refused.length} tone={b.refused.length ? 'red' : b.waiting.length ? 'amber' : 'neutral'} onPress={() => router.push('/sync')} />
          </View>
        </View>

        {(b.corrections.length > 0 || latestDraft) && (
          <View>
            <SectionTitle>Do next</SectionTitle>
            <View className="gap-2">
              {b.corrections.slice(0, 3).map((item) => (
                <RecordRow key={item.id} item={item} />
              ))}
              {latestDraft && b.corrections.length < 3 && <RecordRow item={latestDraft} />}
            </View>
          </View>
        )}

        <View>
          <SectionTitle right={<LinkText title="All tasks" onPress={() => router.push('/tasks')} />}>{dueSoon.length ? 'Due this week' : 'Your tasks'}</SectionTitle>
          {open.length === 0 ? (
            <View className="items-center gap-2 border border-slate-200 bg-white p-5">
              <Ionicons name="clipboard-outline" size={28} color={colors.brand[600]} />
              <Text className="text-center text-base font-semibold text-slate-800">{kit ? 'No open tasks' : 'No forms on this phone yet'}</Text>
              <Text className="text-center text-sm text-slate-500">
                {kit ? 'Your coordinator assigns field work to you in Tatook M&E.' : 'Connect to the internet once to download your assigned forms.'}
              </Text>
            </View>
          ) : (
            <View className="gap-2">
              {(dueSoon.length ? dueSoon : open).slice(0, 4).map((row) => (
                <Pressable
                  key={row.assignment.id}
                  accessibilityRole="button"
                  onPress={() => router.push('/tasks')}
                  className="min-h-14 flex-row items-center gap-3 border border-slate-200 bg-white p-3 active:bg-brand-50">
                  <View className="flex-1 gap-0.5">
                    <Text className="text-base font-semibold text-slate-800" numberOfLines={1}>
                      {row.assignment.form.name}
                    </Text>
                    <Text className="text-xs text-slate-500" numberOfLines={1}>
                      {purposeLabel(row.assignment.form.purpose)}
                      {row.assignment.unit ? ` · ${row.assignment.unit.label}` : ''}
                      {row.daysLeft !== null ? ` · ${row.daysLeft === 0 ? 'due today' : `${row.daysLeft} days left`}` : ''}
                    </Text>
                  </View>
                  <Ionicons name="chevron-forward" size={18} color={colors.faint} />
                </Pressable>
              ))}
            </View>
          )}
        </View>
      </ScrollView>
    </View>
  );
}

function LinkText({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={10}>
      <Text className="text-sm font-semibold text-brand-700">{title}</Text>
    </Pressable>
  );
}
