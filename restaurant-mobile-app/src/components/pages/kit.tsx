import { Ionicons } from '@expo/vector-icons';
import { useState, type ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IconButton, type IconName } from '@/components/ui/button';
import { isoDate, prettyDate, shiftDate } from '@/lib/format';
import { colors } from '@/lib/theme';

/** A page's body: scrolls, pulls down to load again, clears the keyboard and the bottom edge. */
export function Scroll({ children, onRefresh }: { children: ReactNode; onRefresh?: () => Promise<void> }) {
  const [refreshing, setRefreshing] = useState(false);
  const insets = useSafeAreaInsets();
  const refresh = onRefresh
    ? async () => {
        setRefreshing(true);
        try {
          await onRefresh();
        } finally {
          setRefreshing(false);
        }
      }
    : undefined;

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
      <ScrollView
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={{ paddingBottom: insets.bottom + 24 }}
        contentContainerClassName="w-full max-w-3xl gap-4 self-center p-4"
        refreshControl={refresh ? <RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.brand[600]} /> : undefined}>
        {children}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/** A titled white panel, with actions on the right of its title. */
export function Panel({ title, right, children, className = '' }: { title?: string; right?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <View className={`border border-slate-200 bg-white ${className}`}>
      {title || right ? (
        <View className="min-h-11 flex-row items-center justify-between gap-2 border-b border-slate-100 px-4 py-2">
          {title ? (
            <View className="flex-1 flex-row items-center gap-2">
              <View className="h-3.5 w-1 bg-accent" />
              <Text className="flex-1 text-xs font-bold uppercase tracking-wider text-slate-600" numberOfLines={1}>
                {title}
              </Text>
            </View>
          ) : (
            <View className="flex-1" />
          )}
          {right}
        </View>
      ) : null}
      {children}
    </View>
  );
}

/** A row of a list that opens something. */
export function ListRow({
  title,
  subtitle,
  right,
  icon,
  onPress,
  muted,
}: {
  title: string;
  subtitle?: string | null;
  right?: ReactNode;
  icon?: IconName;
  onPress?: () => void;
  muted?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      onPress={onPress}
      disabled={!onPress}
      className={`min-h-14 flex-row items-center gap-3 border-b border-slate-100 px-4 py-2.5 ${onPress ? 'active:bg-brand-50' : ''} ${muted ? 'opacity-60' : ''}`}>
      {icon ? <Ionicons name={icon} size={20} color={colors.muted} /> : null}
      <View className="flex-1">
        <Text className="text-base font-semibold text-slate-900" numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text className="text-sm text-slate-500" numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right}
      {onPress ? <Ionicons name="chevron-forward" size={18} color={colors.faint} /> : null}
    </Pressable>
  );
}

/** Label and value, side by side. */
export function Detail({ label, value, strong }: { label: string; value: ReactNode; strong?: boolean }) {
  return (
    <View className="flex-row items-center justify-between gap-3 py-1">
      <Text className={`text-base ${strong ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>{label}</Text>
      {typeof value === 'string' ? (
        <Text className={`text-right ${strong ? 'text-lg font-bold text-brand-900' : 'text-base font-medium text-slate-900'}`}>{value}</Text>
      ) : (
        value
      )}
    </View>
  );
}

/** One of a few choices, as chips. */
export function Chips<T extends string>({
  options,
  value,
  onChange,
  scroll,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (value: T) => void;
  /** One line that scrolls sideways, rather than wrapping. */
  scroll?: boolean;
}) {
  const chips = options.map((option) => {
    const on = option.value === value;
    return (
      <Pressable
        key={option.value}
        accessibilityRole="radio"
        accessibilityState={{ selected: on }}
        onPress={() => onChange(option.value)}
        className={`min-h-9 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white active:bg-brand-50'}`}>
        <Text className={`text-sm font-semibold ${on ? 'text-white' : 'text-slate-700'}`}>{option.label}</Text>
      </Pressable>
    );
  });
  return scroll ? (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2">
      {chips}
    </ScrollView>
  ) : (
    <View className="flex-row flex-wrap gap-2">{chips}</View>
  );
}

/** A labelled on/off switch. */
export function Toggle({ label, hint, value, onChange }: { label: string; hint?: string; value: boolean; onChange: (value: boolean) => void }) {
  return (
    <Pressable accessibilityRole="switch" accessibilityState={{ checked: value }} onPress={() => onChange(!value)} className="flex-row items-center gap-3 py-1">
      <View className="flex-1">
        <Text className="text-base font-medium text-slate-800">{label}</Text>
        {hint ? <Text className="text-sm text-slate-500">{hint}</Text> : null}
      </View>
      <Switch value={value} onValueChange={onChange} trackColor={{ true: colors.brand[600] }} />
    </Pressable>
  );
}

/** The day a list shows: earlier, later (up to today). */
export function DayPicker({ day, onChange }: { day: string; onChange: (day: string | null) => void }) {
  const go = (next: string) => onChange(next === isoDate() ? null : next);
  return (
    <View className="flex-row items-center">
      <IconButton icon="chevron-back" label="Day before" onPress={() => go(shiftDate(day, -1))} />
      <Text className="w-24 text-center text-sm font-semibold text-slate-800">{prettyDate(day)}</Text>
      <IconButton icon="chevron-forward" label="Day after" disabled={day >= isoDate()} onPress={() => go(shiftDate(day, 1))} />
    </View>
  );
}

/** A figure on the home page. */
export function Stat({ icon, label, value, tone, onPress }: { icon: IconName; label: string; value: string; tone?: 'amber'; onPress?: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} className="min-w-[46%] flex-1 border border-slate-200 bg-white p-4 active:bg-brand-50">
      <View className="flex-row items-center gap-2">
        <Ionicons name={icon} size={16} color={colors.muted} />
        <Text className="flex-1 text-xs font-medium text-slate-500" numberOfLines={1}>
          {label}
        </Text>
      </View>
      <Text className={`mt-1 text-2xl font-bold ${tone === 'amber' ? 'text-amber-700' : 'text-slate-900'}`} numberOfLines={1}>
        {value}
      </Text>
    </Pressable>
  );
}

/** Over or short after a count. */
export function Difference({ minor, format }: { minor: number; format: (minor: number) => string }) {
  if (minor === 0) return <Text className="text-base font-medium text-slate-900">{format(0)}</Text>;
  return (
    <Text className={`text-base font-semibold ${minor > 0 ? 'text-emerald-700' : 'text-red-700'}`}>
      {minor > 0 ? `${format(minor)} over` : `${format(-minor)} short`}
    </Text>
  );
}
