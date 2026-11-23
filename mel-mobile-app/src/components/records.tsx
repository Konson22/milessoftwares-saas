import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import type { ComponentProps, ReactNode } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { Badge } from '@/components/ui/misc';
import { actionText, STATUS_LABEL, STATUS_TONE } from '@/collect/labels';
import { VERIFICATION_LABEL, VERIFICATION_TONE, verificationOf } from '@/collect/overview';
import type { Item } from '@/collect/types';
import { colors, webNoOutline } from '@/lib/theme';
import { useAuth } from '@/store/auth';

export function when(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

/** Today on the phone, as YYYY-MM-DD. */
export function today(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * A record's two states side by side: whether it reached the server
 * (transport) and, once it did, what the reviewers decided (verification).
 */
export function RecordBadges({ item }: { item: Item }) {
  const verification = verificationOf(item);
  return (
    <View className="flex-row flex-wrap items-center gap-1.5">
      <Badge label={item.status === 'synced' ? 'Uploaded' : STATUS_LABEL[item.status]} tone={STATUS_TONE[item.status]} a11y={`Sending: ${STATUS_LABEL[item.status]}`} />
      {verification !== 'none' && <Badge label={VERIFICATION_LABEL[verification]} tone={VERIFICATION_TONE[verification]} a11y={`Review: ${VERIFICATION_LABEL[verification]}`} />}
    </View>
  );
}

export function RecordRow({ item, showForm = true }: { item: Item; showForm?: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => router.push({ pathname: '/fill/[id]', params: { id: item.id } })}
      className="min-h-14 gap-1.5 border border-slate-200 bg-white p-3 active:bg-brand-50">
      <View className="flex-row items-center gap-2">
        <Text className="flex-1 text-base font-semibold text-slate-800" numberOfLines={1}>
          {showForm ? item.formName : `Started ${when(item.startedAt)}`}
        </Text>
        <Ionicons name="chevron-forward" size={18} color={colors.faint} />
      </View>
      <RecordBadges item={item} />
      <Text className="text-xs text-slate-500">
        {showForm ? `Started ${when(item.startedAt)}` : ''}
        {item.status === 'synced' && item.syncedAt ? `${showForm ? ' · ' : ''}uploaded ${when(item.syncedAt)}` : ''}
        {item.status === 'failed' && item.nextAttemptAt ? `${showForm ? ' · ' : ''}next try ${when(item.nextAttemptAt)}` : ''}
      </Text>
      {item.status === 'action_required' && <Text className="text-sm text-red-700">{actionText(item)}</Text>}
      {item.status === 'failed' && item.error && <Text className="text-sm text-amber-800">{item.error.message}</Text>}
    </Pressable>
  );
}

/** Online / offline, in words a fieldworker reads at a glance. */
export function ConnectionPill() {
  const reachable = useAuth((s) => s.reachable);
  return (
    <View className={`flex-row items-center gap-1.5 self-start px-2 py-1 ${reachable ? 'bg-emerald-50' : 'bg-amber-50'}`} accessible accessibilityLabel={reachable ? 'Online' : 'Offline'}>
      <View className={`h-2 w-2 rounded-full ${reachable ? 'bg-emerald-600' : 'bg-amber-600'}`} />
      <Text className={`text-xs font-semibold ${reachable ? 'text-emerald-800' : 'text-amber-900'}`}>{reachable ? 'Online' : 'Offline: work is kept on this phone'}</Text>
    </View>
  );
}

/** A compact figure that opens where it leads. */
export function StatCard({ icon, label, value, tone = 'neutral', onPress }: { icon: ComponentProps<typeof Ionicons>['name']; label: string; value: number; tone?: 'neutral' | 'amber' | 'red' | 'green'; onPress?: () => void }) {
  const color = { neutral: colors.brand[700], amber: colors.warning, red: colors.dangerDark, green: colors.successDark }[tone];
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={`${label}: ${value}`}
      onPress={onPress}
      disabled={!onPress}
      className="min-h-20 flex-1 gap-1 border border-slate-200 bg-white p-3 active:bg-brand-50">
      <Ionicons name={icon} size={20} color={color} />
      <Text className="text-2xl font-bold text-slate-900">{value}</Text>
      <Text className="text-xs font-medium text-slate-600" numberOfLines={2}>
        {label}
      </Text>
    </Pressable>
  );
}

/** A row of choices, one active. */
export function Segments<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <View className="flex-row flex-wrap gap-2" accessibilityRole="tablist">
      {options.map((o) => (
        <Pressable
          key={o.value}
          accessibilityRole="tab"
          accessibilityState={{ selected: value === o.value }}
          onPress={() => onChange(o.value)}
          className={`min-h-10 justify-center border px-3 ${value === o.value ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white active:bg-brand-50'}`}>
          <Text className={`text-sm font-semibold ${value === o.value ? 'text-white' : 'text-slate-700'}`}>{o.label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

export function SearchBox({ value, onChange, placeholder, children }: { value: string; onChange: (v: string) => void; placeholder: string; children?: ReactNode }) {
  return (
    <View className="min-h-12 flex-row items-center gap-2 border border-slate-300 bg-white px-3">
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
