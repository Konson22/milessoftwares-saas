import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ConnectionPill, EntryRow, when } from '@/components/entries';
import { useOpenDrawer } from '@/components/screen-header';
import { distance } from '@/components/trip-format';
import { IconButton } from '@/components/ui/button';
import { Banner, Card, CountBadge, IconTile, ListGroup, ListItem, QuickAction } from '@/components/ui/misc';
import { colors } from '@/lib/theme';
import { buckets } from '@/logbook/sync';
import type { KitSheet } from '@/logbook/types';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useTrips } from '@/store/trips';
import { useWork } from '@/store/work';

function initials(name: string | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase() || 'L';
}

/** What needs the person now: a summary, the places they go, sheets to write on and what waits on them. */
export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const openMenu = useOpenDrawer();
  const me = useAuth((s) => s.me);
  const needsSignIn = useAuth((s) => s.needsSignIn);
  const items = useLogbook((s) => s.items);
  const kit = useLogbook((s) => s.kit);
  const downloaded = useLogbook((s) => s.downloaded);
  const syncing = useLogbook((s) => s.syncing);
  const lastSyncAt = useLogbook((s) => s.lastSyncAt);
  const refresh = useLogbook((s) => s.refresh);
  const start = useLogbook((s) => s.start);
  const b = buckets(items);
  const tasks = useWork((s) => s.tasks);
  const due = useWork((s) => s.due);
  const unread = useWork((s) => s.unread);
  const missed = useWork((s) => s.missed);
  const toReview = useWork((s) => s.toReview);
  const stuck = useWork((s) => s.actions.filter((a) => a.refused !== null).length);
  const pendingActions = useWork((s) => s.actions.filter((a) => a.refused === null).length);
  const toDo = tasks.filter((t) => ['pending', 'in_progress', 'returned'].includes(t.status));
  const overdue = toDo.filter((t) => t.overdue).length;
  const trip = useTrips((s) => s.active);
  const canWrite = !!me?.can.write;
  const tripsOn = canWrite && (kit?.sheets ?? []).some((s) => s.location?.mode === 'route');
  const needYou = b.returned.length + b.refused.length;
  const attention = [...b.returned, ...b.refused, ...b.drafts].sort((x, y) => y.updatedAt.localeCompare(x.updatedAt)).slice(0, 5);
  const shortcuts = (kit?.sheets ?? []).filter((s) => s.version.id && downloaded.includes(s.version.id)).slice(0, 4);
  const [opening, setOpening] = useState<string | null>(null);

  const write = (sheet: KitSheet) => {
    setOpening(sheet.id);
    start(sheet)
      .then((item) => router.push({ pathname: '/entry/[id]', params: { id: item.id } }))
      .catch((e: unknown) => toast(e instanceof Error ? e.message : 'Could not start the entry.', 'error'))
      .finally(() => setOpening(null));
  };

  return (
    <View className="flex-1 bg-canvas">
      {/* App bar: stays put while the page scrolls under it. */}
      <View className="bg-brand-logo px-4 pb-3" style={{ paddingTop: insets.top + 10 }}>
        <View className="flex-row items-center gap-3">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Menu"
            onPress={openMenu}
            className="h-11 w-11 items-center justify-center rounded-full bg-accent active:bg-accent-600">
            <Text className="text-base font-bold text-brand-950">{initials(me?.user.name)}</Text>
          </Pressable>
          <Text accessibilityRole="header" className="flex-1 text-lg font-bold text-white" numberOfLines={1}>
            {me?.organization.name ?? 'Logbook'}
          </Text>
          <View>
            <IconButton
              icon={unread > 0 ? 'notifications' : 'notifications-outline'}
              label={unread > 0 ? `Notifications: ${unread} unread` : 'Notifications'}
              onDark
              onPress={() => router.push('/notifications')}
            />
            <View className="absolute right-0 top-0" pointerEvents="none">
              <CountBadge count={unread} />
            </View>
          </View>
        </View>
      </View>
      <ScrollView
        contentContainerStyle={{ paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} tintColor={colors.white} colors={[colors.brand[600]]} />}>
        {/* Connection and the day's figures on a card that overlaps the blue. */}
        <View className="bg-brand-logo px-4 pb-16 pt-1">
          <View className="flex-row items-center justify-between">
            <ConnectionPill onDark />
            <Text className="text-xs text-brand-200">{lastSyncAt ? `Synced ${when(lastSyncAt)}` : 'Not synced yet'}</Text>
          </View>
        </View>

        <View className="-mt-12 gap-4 px-4">
          <Card padded={false}>
            <View className="flex-row">
              <Figure label="Tasks to do" value={toDo.length} sub={overdue ? `${overdue} overdue` : undefined} subTone="red" onPress={() => router.push('/tasks')} />
              <View className="my-3 w-px bg-slate-100" />
              <Figure
                label="Due today"
                value={due.length}
                sub={due.some((o) => o.state === 'overdue') ? 'Some late' : undefined}
                subTone="amber"
                onPress={() => router.push('/tasks')}
              />
              <View className="my-3 w-px bg-slate-100" />
              <Figure label="Needs you" value={needYou} sub={b.drafts.length ? `${b.drafts.length} drafts` : undefined} onPress={() => router.push('/entries')} />
            </View>
          </Card>

          {needsSignIn && <Banner tone="warning">Your sign-in has expired. Sign in again from Account to send. Nothing is lost.</Banner>}
          {me && !me.can.write && !me.can.review && <Banner tone="warning">You have no Logbook role in this workspace yet. Ask its administrator.</Banner>}

          <Card padded={false} className="px-2 py-2">
            <View className="flex-row flex-wrap">
              {canWrite && <QuickAction icon="add" label="New entry" color="blue" onPress={() => router.push('/new')} />}
              <QuickAction icon="document-text" label="My entries" color="sky" count={needYou} onPress={() => router.push('/entries')} />
              <QuickAction icon="checkbox" label="Tasks" color="green" count={toDo.length} onPress={() => router.push('/tasks')} />
              {(tripsOn || trip) && <QuickAction icon="navigate" label="Trip" color="orange" onPress={() => router.push('/trip')} />}
              {me?.can.review && <QuickAction icon="checkmark-done" label="Reviews" color="violet" count={toReview ?? 0} onPress={() => router.push('/reviews')} />}
              <QuickAction icon="sync" label="Sync" color="teal" count={b.waiting.length + pendingActions} onPress={() => router.push('/sync')} />
              <QuickAction icon="notifications" label="Alerts" color="amber" count={unread} onPress={() => router.push('/notifications')} />
              <QuickAction icon="person" label="Account" color="slate" onPress={() => router.push('/account')} />
            </View>
          </Card>

          {trip && (
            <Pressable accessibilityRole="button" onPress={() => router.push('/trip')} className="flex-row items-center gap-3 rounded-2xl bg-brand-600 p-4 active:bg-brand-700">
              <IconTile icon="navigate" color={trip.status === 'recording' ? 'green' : 'amber'} size={40} solid />
              <View className="flex-1">
                <Text className="text-base font-bold text-white">{trip.status === 'recording' ? 'Trip recording' : 'Trip paused'}</Text>
                <Text className="text-sm text-brand-100">
                  {distance(trip.distanceM)} · {trip.points} positions{trip.status === 'recording' ? '' : ' · resume or end it'}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={colors.white} />
            </Pressable>
          )}

          {canWrite && shortcuts.length > 0 && (
            <ListGroup
              title="Write on"
              right={
                <Pressable accessibilityRole="button" onPress={() => router.push('/new')} hitSlop={8}>
                  <Text className="text-sm font-semibold text-brand-600">All sheets</Text>
                </Pressable>
              }>
              {shortcuts.map((sheet) => (
                <ListItem
                  key={sheet.id}
                  icon="document-text"
                  color="blue"
                  title={sheet.name}
                  detail={`${sheet.code} · ${sheet.logbook.name}`}
                  onPress={opening ? undefined : () => write(sheet)}
                  chevron={false}
                  a11y={`New entry on ${sheet.name}`}
                  right={
                    <View className="h-8 w-8 items-center justify-center rounded-full bg-brand-50">
                      <Ionicons name={opening === sheet.id ? 'hourglass-outline' : 'add'} size={20} color={colors.brand[600]} />
                    </View>
                  }
                />
              ))}
            </ListGroup>
          )}

          <ListGroup title="Needs you">
            {attention.length === 0 ? (
              <ListItem icon="checkmark-circle" color="green" title="All clear" detail="Nothing to finish or correct." />
            ) : (
              attention.map((item) => <EntryRow key={item.id} item={item} flat />)
            )}
          </ListGroup>

          <ListGroup title="Overview">
            <ListItem icon="create" color="slate" title="Drafts" onPress={() => router.push('/entries')} right={<Count value={b.drafts.length} />} />
            <ListItem icon="cloud-upload" color="sky" title="Waiting to send" onPress={() => router.push('/sync')} right={<Count value={b.waiting.length} />} />
            <ListItem icon="hourglass" color="amber" title="Awaiting review" onPress={() => router.push('/entries')} right={<Count value={b.inReview.length} />} />
            <ListItem icon="checkmark-done" color="green" title="Reviewed or recorded" onPress={() => router.push('/entries')} right={<Count value={b.done.length} />} />
            <ListItem icon="alert-circle" color="orange" title="Missed this week" onPress={() => router.push('/tasks')} right={<Count value={missed.length} />} />
            <ListItem icon="warning" color="red" title="Could not send" onPress={() => router.push('/sync')} right={<Count value={b.refused.length + stuck} />} />
            <ListItem icon="albums" color="violet" title="Log sheets" detail="Templates you write on" onPress={canWrite ? () => router.push('/new') : undefined} right={<Count value={kit?.sheets.length ?? 0} />} />
          </ListGroup>
        </View>
      </ScrollView>
    </View>
  );
}

function Figure({ label, value, sub, subTone = 'muted', onPress }: { label: string; value: number; sub?: string; subTone?: 'muted' | 'red' | 'amber'; onPress: () => void }) {
  const subClass = { muted: 'text-slate-500', red: 'text-red-600', amber: 'text-amber-700' }[subTone];
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={`${label}: ${value}`} onPress={onPress} className="flex-1 items-center gap-0.5 px-2 py-4 active:bg-slate-50">
      <Text className="text-2xl font-bold text-brand-800">{value}</Text>
      <Text className="text-xs font-medium text-slate-600">{label}</Text>
      <Text className={`text-[11px] font-semibold ${subClass}`}>{sub ?? ' '}</Text>
    </Pressable>
  );
}

function Count({ value }: { value: number }) {
  return <Text className={`text-base font-bold ${value > 0 ? 'text-slate-900' : 'text-slate-400'}`}>{value}</Text>;
}
