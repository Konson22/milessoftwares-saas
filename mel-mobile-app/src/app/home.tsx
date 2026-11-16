import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { IconButton } from '@/components/ui/button';
import { Badge, Banner, EmptyState } from '@/components/ui/misc';
import { STATUS_LABEL } from '@/collect/labels';
import type { Assignment } from '@/collect/types';
import { errorMessage } from '@/lib/api/client';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useCollect } from '@/store/collect';
import { toast } from '@/store/toast';

/** The forms assigned to the collector, and what waits to be sent. */
export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const me = useAuth((s) => s.me);
  const reachable = useAuth((s) => s.reachable);
  const needsSignIn = useAuth((s) => s.needsSignIn);
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const syncing = useCollect((s) => s.syncing);
  const kitError = useCollect((s) => s.kitError);
  const refresh = useCollect((s) => s.refresh);
  const start = useCollect((s) => s.start);
  const [busy, setBusy] = useState<string | null>(null);

  const drafts = items.filter((i) => i.status === 'draft');
  const waiting = items.filter((i) => ['ready', 'syncing', 'failed'].includes(i.status));
  const action = items.filter((i) => i.status === 'action_required');

  async function begin(assignment: Assignment) {
    setBusy(assignment.id);
    try {
      const item = await start(assignment);
      router.push({ pathname: '/fill/[id]', params: { id: item.id } });
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(null);
    }
  }

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        title={me?.organization.name ?? 'M&E'}
        subtitle={reachable ? 'Online' : 'Offline: your work is kept on this phone'}
        right={<IconButton icon="person-circle-outline" label="Account" onPress={() => router.push('/account')} onDark />}
      />
      {needsSignIn && (
        <Banner tone="warning">Sign in again to send your records. Nothing is lost: they stay on this phone.</Banner>
      )}
      {kitError && !kit && <Banner tone="error">{kitError}</Banner>}

      <Pressable
        accessibilityRole="button"
        onPress={() => router.push('/outbox')}
        className="mx-4 mt-4 flex-row items-center gap-3 border border-brand-100 bg-white p-4 active:bg-brand-50">
        <Ionicons name={syncing ? 'sync' : 'cloud-upload-outline'} size={24} color={colors.brand[700]} />
        <View className="flex-1">
          <Text className="text-base font-semibold text-slate-800">Your records</Text>
          <Text className="text-sm text-slate-500">
            {drafts.length} {drafts.length === 1 ? 'draft' : 'drafts'} · {waiting.length} waiting to send
            {syncing ? ' · sending…' : ''}
          </Text>
        </View>
        {action.length > 0 && <Badge label={`${action.length} ${STATUS_LABEL.action_required.toLowerCase()}`} tone="red" />}
        <Ionicons name="chevron-forward" size={20} color={colors.faint} />
      </Pressable>

      <FlatList
        data={kit?.assignments ?? []}
        keyExtractor={(a) => a.id}
        contentContainerStyle={{ padding: 16, gap: 12, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}
        ListHeaderComponent={<Text className="text-sm font-semibold uppercase text-slate-500">Assigned forms</Text>}
        ListEmptyComponent={
          <EmptyState
            icon="clipboard-outline"
            title={kit ? 'No forms assigned' : 'No forms on this phone yet'}
            message={kit ? 'Your coordinator assigns forms to you in M&E.' : 'Connect to the internet once to download your forms.'}
          />
        }
        renderItem={({ item: a }) => {
          const open = a.canStart && a.version !== null;
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: !open }}
              disabled={!open || busy !== null}
              onPress={() => void begin(a)}
              className={`gap-1 border border-slate-200 bg-white p-4 ${open ? 'active:bg-brand-50' : 'opacity-60'}`}>
              <View className="flex-row items-center gap-2">
                <Text className="flex-1 text-base font-semibold text-slate-800">{a.form.name}</Text>
                {a.status === 'closing' && <Badge label="Closing" tone="amber" />}
                {a.form.containsPersonalData && <Badge label="Personal data" tone="purple" />}
              </View>
              {a.unit && <Text className="text-sm text-slate-600">{a.unit.label}</Text>}
              {a.form.description && <Text className="text-sm text-slate-500">{a.form.description}</Text>}
              <Text className="text-xs text-slate-500">
                {open ? `Version ${a.version?.number} · tap to start a new record` : a.canStart ? 'Not downloaded yet' : 'Not open for new records'}
                {a.endsOn ? ` · until ${a.endsOn}` : ''}
              </Text>
            </Pressable>
          );
        }}
      />
    </View>
  );
}
