import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import type { ComponentProps, ReactNode } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';

import { Badge, IconTile } from '@/components/ui/misc';
import { summary } from '@/logbook/fields';
import type { Item, LocalStatus, ServerStatus, StoredVersion } from '@/logbook/types';
import { colors, webNoOutline, type TileColor } from '@/lib/theme';
import { useAuth } from '@/store/auth';

type Tone = 'neutral' | 'green' | 'amber' | 'red' | 'blue' | 'purple';

/** Today's time, or the day and month for older dates: short enough for a row's corner. */
export function shortWhen(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const today = new Date();
  if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

export function when(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

/** Whether it reached the server, in the phone's own words. */
export const SENDING: Record<LocalStatus, [string, Tone]> = {
  draft: ['Draft', 'neutral'],
  ready: ['Waiting to send', 'blue'],
  syncing: ['Sending', 'blue'],
  synced: ['Sent', 'green'],
  failed: ['Not sent yet', 'amber'],
  action_required: ['Needs you', 'red'],
};

/** What the server decided, once it has it. Reaching the server is never shown as reviewed. */
export const REVIEW: Record<ServerStatus, [string, Tone]> = {
  draft: ['Not submitted', 'neutral'],
  submitted: ['Awaiting review', 'amber'],
  returned: ['Returned', 'red'],
  reviewed: ['Reviewed', 'green'],
  finalized: ['Recorded', 'purple'],
};

export function EntryBadges({ item }: { item: Item }) {
  const correcting = item.serverStatus === 'returned' && item.status !== 'synced';
  const [sending, sendingTone] = correcting && item.status === 'draft' ? ['Correcting', 'amber' as Tone] : SENDING[item.status];
  const review = item.serverStatus && item.serverStatus !== 'draft' ? REVIEW[item.serverStatus] : null;
  return (
    <View className="flex-row flex-wrap items-center gap-1.5">
      <Badge label={sending} tone={sendingTone} a11y={`Sending: ${sending}`} />
      {review && item.status === 'synced' && <Badge label={review[0]} tone={review[1]} a11y={`Review: ${review[0]}`} />}
      {item.status === 'draft' && item.activityStartedAt && !item.activityEndedAt && <Badge label="Activity going on" tone="blue" a11y="The activity has started and not ended" />}
    </View>
  );
}

/** The colour and icon that say where an entry stands, for its tile. */
export function entryLook(item: Item): { icon: ComponentProps<typeof Ionicons>['name']; color: TileColor } {
  if (item.status === 'action_required' || item.serverStatus === 'returned') return { icon: 'arrow-undo', color: 'red' };
  if (item.status === 'failed') return { icon: 'warning', color: 'amber' };
  if (item.status === 'draft') return { icon: 'create', color: 'slate' };
  if (item.status === 'ready' || item.status === 'syncing') return { icon: 'cloud-upload', color: 'sky' };
  if (item.serverStatus === 'reviewed') return { icon: 'checkmark-done', color: 'green' };
  if (item.serverStatus === 'finalized') return { icon: 'lock-closed', color: 'violet' };
  if (item.serverStatus === 'submitted') return { icon: 'hourglass', color: 'amber' };
  return { icon: 'document-text', color: 'blue' };
}

export function EntryRow({ item, version, flat }: { item: Item; version?: StoredVersion | null; /** Inside a ListGroup: no card of its own. */ flat?: boolean }) {
  const about = version ? summary(version.fields, item.values) : '';
  const look = entryLook(item);
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => router.push({ pathname: '/entry/[id]', params: { id: item.id } })}
      className={`min-h-16 flex-row gap-3 p-3.5 active:bg-slate-50 ${flat ? '' : 'rounded-2xl border border-slate-200 bg-white'}`}>
      <IconTile icon={look.icon} color={look.color} size={40} />
      <View className="flex-1 gap-1">
        <View className="flex-row items-center gap-2">
          <Text className="flex-1 text-[15px] font-semibold text-slate-900" numberOfLines={1}>
            {item.reference ?? item.sheet.name}
          </Text>
          <Text className="text-xs text-slate-400">{shortWhen(item.occurredAt)}</Text>
        </View>
        <Text className="text-[13px] text-slate-500" numberOfLines={1}>
          {item.reference ? `${item.sheet.code} · ${item.sheet.name}` : `${item.sheet.code} · ${item.logbook}`}
        </Text>
        {about !== '' && (
          <Text className="text-sm text-slate-700" numberOfLines={1}>
            {about}
          </Text>
        )}
        <EntryBadges item={item} />
        {item.status === 'action_required' && item.error && <Text className="text-sm text-red-700">{item.error.message}</Text>}
        {item.status === 'failed' && item.error && (
          <Text className="text-sm text-amber-800">
            {item.error.message}
            {item.nextAttemptAt ? ` Next try ${when(item.nextAttemptAt)}.` : ''}
          </Text>
        )}
      </View>
    </Pressable>
  );
}

/** Online or offline, in words read at a glance. `onDark` for the blue header. */
export function ConnectionPill({ onDark }: { onDark?: boolean }) {
  const reachable = useAuth((s) => s.reachable);
  const box = onDark ? 'bg-brand-800' : reachable ? 'bg-emerald-50' : 'bg-amber-50';
  const text = onDark ? 'text-white' : reachable ? 'text-emerald-800' : 'text-amber-900';
  return (
    <View className={`flex-row items-center gap-1.5 self-start rounded-full px-2.5 py-1 ${box}`} accessible accessibilityLabel={reachable ? 'Online' : 'Offline'}>
      <View className={`h-2 w-2 rounded-full ${reachable ? 'bg-emerald-500' : 'bg-amber-500'}`} />
      <Text className={`text-xs font-semibold ${text}`}>{reachable ? 'Online' : 'Offline: kept on this phone'}</Text>
    </View>
  );
}

/** A figure in a tile: coloured icon, number and label; opens where it leads. */
export function StatCard({ icon, label, value, color = 'blue', onPress }: { icon: ComponentProps<typeof Ionicons>['name']; label: string; value: number | string; color?: TileColor; onPress?: () => void }) {
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={`${label}: ${value}`}
      onPress={onPress}
      disabled={!onPress}
      className="min-h-20 flex-1 gap-2 rounded-2xl border border-slate-200 bg-white p-3 active:bg-slate-50">
      <IconTile icon={icon} color={color} size={32} />
      <View>
        <Text className="text-xl font-bold text-slate-900">{value}</Text>
        <Text className="text-xs font-medium text-slate-500" numberOfLines={2}>
          {label}
        </Text>
      </View>
    </Pressable>
  );
}

/** A row of chips, one active; scrolls sideways when they do not fit. */
export function Segments<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2" accessibilityRole="tablist">
      {options.map((o) => (
        <Pressable
          key={o.value}
          accessibilityRole="tab"
          accessibilityState={{ selected: value === o.value }}
          onPress={() => onChange(o.value)}
          className={`min-h-9 justify-center rounded-full border px-4 ${value === o.value ? 'border-brand-600 bg-brand-600' : 'border-slate-200 bg-white active:bg-slate-100'}`}>
          <Text className={`text-sm font-semibold ${value === o.value ? 'text-white' : 'text-slate-600'}`}>{o.label}</Text>
        </Pressable>
      ))}
    </ScrollView>
  );
}

export function SearchBox({ value, onChange, placeholder, children }: { value: string; onChange: (v: string) => void; placeholder: string; children?: ReactNode }) {
  return (
    <View className="min-h-12 flex-row items-center gap-2 rounded-xl border border-slate-200 bg-white px-3">
      <Ionicons name="search" size={18} color={colors.faint} />
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.faint}
        accessibilityLabel={placeholder}
        autoCorrect={false}
        className="flex-1 text-base text-slate-900"
        style={webNoOutline}
      />
      {value !== '' && (
        <Pressable accessibilityRole="button" accessibilityLabel="Clear search" onPress={() => onChange('')} hitSlop={8}>
          <Ionicons name="close-circle" size={18} color={colors.faint} />
        </Pressable>
      )}
      {children}
    </View>
  );
}
