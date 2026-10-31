import { Ionicons } from '@expo/vector-icons';
import type { ReactNode } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button, type IconName } from '@/components/ui/button';
import { dateTime, money, fullDate } from '@/lib/format';
import { colors, webNoOutline } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/** A page's scrolling body, pulled down to load it again; its end clears the system bar. */
export function PageScroll({ children, loading, onRefresh }: { children: ReactNode; loading: boolean; onRefresh: () => void }) {
  const insets = useSafeAreaInsets();
  return (
    <ScrollView
      contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-4 p-4"
      contentContainerStyle={{ paddingBottom: insets.bottom + 40 }}
      keyboardShouldPersistTaps="handled"
      refreshControl={<RefreshControl refreshing={loading} onRefresh={onRefresh} />}>
      {children}
    </ScrollView>
  );
}

/** "expectedCashMinor" → "Expected cash". */
export function humanize(key: string): string {
  const words = key
    .replace(/Minor$/, '')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .trim()
    .toLowerCase();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const DATE_TIME = /^\d{4}-\d{2}-\d{2}T/;

/** A value as the web shows it: amounts (…Minor) as money, dates readable, yes/no. */
export function formatValue(key: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'number' && /Minor$/.test(key)) return money(value);
  if (typeof value === 'string' && DATE_TIME.test(value)) return dateTime(value);
  if (typeof value === 'string' && DATE.test(value)) return fullDate(value);
  if (typeof value === 'object') return Array.isArray(value) ? `${value.length}` : '…';
  return String(value);
}

const STATUS_TONE: Record<string, string> = {
  open: 'bg-emerald-100 text-emerald-800',
  available: 'bg-emerald-100 text-emerald-800',
  completed: 'bg-emerald-100 text-emerald-800',
  approved: 'bg-emerald-100 text-emerald-800',
  received: 'bg-emerald-100 text-emerald-800',
  paid: 'bg-brand-100 text-brand-800',
  pending: 'bg-amber-100 text-amber-800',
  draft: 'bg-slate-100 text-slate-700',
  ordered: 'bg-brand-100 text-brand-800',
  sent: 'bg-brand-100 text-brand-800',
  quarantined: 'bg-amber-100 text-amber-800',
  recalled: 'bg-red-100 text-red-700',
  rejected: 'bg-red-100 text-red-700',
  cancelled: 'bg-red-100 text-red-700',
  voided: 'bg-red-100 text-red-700',
  closed: 'bg-slate-100 text-slate-700',
};

/** A record's status as a coloured chip. */
export function StatusChip({ status, label }: { status: string; label?: string }) {
  const [bg, fg] = (STATUS_TONE[status] ?? 'bg-slate-100 text-slate-700').split(' ');
  return (
    <View className={`rounded-md px-1.5 py-0.5 ${bg}`}>
      <Text className={`text-xs font-bold capitalize ${fg}`}>{label ?? humanize(status)}</Text>
    </View>
  );
}

/** A row of a list: a title, a line below, something on the right; opens the record when it has somewhere to go. */
export function ListCard({
  icon,
  title,
  subtitle,
  meta,
  right,
  onPress,
}: {
  icon?: IconName;
  title: string;
  subtitle?: string | null;
  meta?: string | null;
  right?: ReactNode;
  onPress?: () => void;
}) {
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      disabled={!onPress}
      onPress={onPress}
      className="flex-row items-center gap-3 border border-slate-200 bg-white p-4 shadow-sm shadow-brand-900/5 active:border-brand-300 active:bg-brand-50">
      {icon && (
        <View className="h-11 w-11 items-center justify-center rounded-2xl bg-brand-50">
          <Ionicons name={icon} size={22} color={colors.brand[700]} />
        </View>
      )}
      <View className="flex-1 gap-0.5">
        <Text className="text-base font-semibold text-slate-900" numberOfLines={2}>
          {title}
        </Text>
        {!!subtitle && (
          <Text className="text-sm text-slate-500" numberOfLines={2}>
            {subtitle}
          </Text>
        )}
        {!!meta && <Text className="text-xs text-slate-400">{meta}</Text>}
      </View>
      {right && <View className="items-end gap-1">{right}</View>}
      {onPress && !right && <Ionicons name="chevron-forward" size={18} color={colors.faint} />}
    </Pressable>
  );
}

export function SearchBox({
  value,
  onChange,
  placeholder,
  onSubmit,
}: {
  value: string;
  onChange: (text: string) => void;
  placeholder: string;
  /** Searching on the keyboard's search key (and on clearing), for a search the shop answers. */
  onSubmit?: (text: string) => void;
}) {
  return (
    <View className="min-h-12 flex-row items-center border border-slate-200 bg-white px-3">
      <Ionicons name="search" size={20} color={colors.muted} />
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.faint}
        autoCorrect={false}
        accessibilityLabel={placeholder}
        returnKeyType="search"
        onSubmitEditing={() => onSubmit?.(value)}
        style={webNoOutline}
        className="flex-1 px-2 py-3 text-base text-slate-900"
      />
      {value !== '' && (
        <Pressable accessibilityRole="button" accessibilityLabel="Clear search" onPress={() => {
            onChange('');
            onSubmit?.('');
          }}
          hitSlop={8}>
          <Ionicons name="close-circle" size={20} color={colors.faint} />
        </Pressable>
      )}
    </View>
  );
}

export function FilterChips<T extends string>({
  options,
  value,
  onChange,
}: {
  options: { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
}) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2">
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable
            key={o.value}
            accessibilityRole="radio"
            accessibilityState={{ selected: on }}
            onPress={() => onChange(o.value)}
            className={`min-h-9 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white'}`}>
            <Text className={`font-medium ${on ? 'text-white' : 'text-slate-700'}`}>{o.label}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

/** A figure on the dashboard or a report; opens its section when given one. */
export function StatTile({
  icon,
  label,
  value,
  tone = 'brand',
  onPress,
}: {
  icon: IconName;
  label: string;
  value: string;
  tone?: 'brand' | 'amber' | 'red' | 'green';
  onPress?: () => void;
}) {
  const tint = { brand: colors.brand[700], amber: colors.warning, red: colors.dangerDark, green: colors.successDark }[tone];
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={`${label}: ${value}`}
      disabled={!onPress}
      onPress={onPress}
      className="min-w-[45%] flex-1 gap-2 border border-slate-200 bg-white p-4 active:bg-brand-50">
      <Ionicons name={icon} size={22} color={tint} />
      <Text className="text-2xl font-bold text-slate-900" numberOfLines={1}>
        {value}
      </Text>
      <Text className="text-sm text-slate-500">{label}</Text>
    </Pressable>
  );
}

/** Details as label/value lines. */
export function Details({ rows }: { rows: [string, string][] }) {
  return (
    <View className="gap-1">
      {rows.map(([label, value]) => (
        <View key={label} className="flex-row justify-between gap-4 py-1">
          <Text className="text-base text-slate-500">{label}</Text>
          <Text className="flex-1 text-right text-base font-medium text-slate-900">{value}</Text>
        </View>
      ))}
    </View>
  );
}
