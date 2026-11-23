import { Ionicons } from '@expo/vector-icons';
import { Children, type ReactNode } from 'react';
import { Pressable, Text, View } from 'react-native';

import { colors, tiles, type TileColor } from '@/lib/theme';

import type { IconName } from './button';

type Tone = 'neutral' | 'green' | 'amber' | 'red' | 'blue' | 'purple';

const badgeTone: Record<Tone, [string, string]> = {
  neutral: ['bg-slate-100', 'text-slate-700'],
  green: ['bg-emerald-100', 'text-emerald-800'],
  amber: ['bg-amber-100', 'text-amber-800'],
  red: ['bg-red-100', 'text-red-700'],
  blue: ['bg-brand-100', 'text-brand-800'],
  purple: ['bg-violet-100', 'text-violet-800'],
};

export function Badge({ label, tone = 'neutral', a11y }: { label: string; tone?: Tone; a11y?: string }) {
  const [bg, fg] = badgeTone[tone];
  return (
    <View className={`rounded-full px-2.5 py-0.5 ${bg}`} accessible accessibilityLabel={a11y ?? label}>
      <Text className={`text-xs font-semibold ${fg}`}>{label}</Text>
    </View>
  );
}

/** Fluent's resting card: a light, solid-coloured shadow under the border. */
const cardShadow = {
  shadowColor: '#e1e5ec',
  shadowOffset: { width: 0, height: 1 },
  shadowOpacity: 1,
  shadowRadius: 2,
  elevation: 1,
} as const;

export function Card({ children, className = '', padded = true }: { children: ReactNode; className?: string; padded?: boolean }) {
  return (
    <View className={`rounded-2xl border border-slate-200 bg-white ${padded ? 'p-4' : ''} ${className}`} style={cardShadow}>
      {children}
    </View>
  );
}

/** A rounded square in a function colour with its icon: solid (white icon) or soft (coloured icon). */
export function IconTile({ icon, color = 'blue', size = 40, solid }: { icon: IconName; color?: TileColor; size?: number; solid?: boolean }) {
  const t = tiles[color];
  return (
    <View
      className="items-center justify-center"
      style={{ width: size, height: size, borderRadius: Math.round(size * 0.3), backgroundColor: solid ? t.solid : t.soft }}>
      <Ionicons name={icon} size={Math.round(size * 0.52)} color={solid ? colors.white : t.fg} />
    </View>
  );
}

/** Classes for row `index` of `count` drawn as one rounded group (lists that render rows one by one). Add the background yourself. */
export function groupedRow(index: number, count: number): string {
  const first = index === 0;
  const last = index === count - 1;
  return `border-x border-slate-200 ${first ? 'rounded-t-2xl border-t' : 'border-t border-t-slate-100'} ${last ? 'rounded-b-2xl border-b' : ''}`;
}

/** A small count bubble, red unless told otherwise. */
export function CountBadge({ count, color = tiles.red.solid }: { count: number; color?: string }) {
  if (count <= 0) return null;
  return (
    <View className="h-5 min-w-5 items-center justify-center rounded-full px-1.5" style={{ backgroundColor: color }}>
      <Text className="text-[11px] font-bold text-white">{count > 99 ? '99+' : count}</Text>
    </View>
  );
}

/** A white rounded panel holding rows separated by hairlines, with an optional heading above. */
export function ListGroup({ title, right, children, className = '' }: { title?: string; right?: ReactNode; children: ReactNode; className?: string }) {
  const rows = Children.toArray(children).filter(Boolean);
  return (
    <View className={className}>
      {(title || right) && (
        <View className="mb-2 flex-row items-center justify-between px-1">
          {title ? (
            <Text accessibilityRole="header" className="text-base font-bold text-slate-900">
              {title}
            </Text>
          ) : (
            <View />
          )}
          {right}
        </View>
      )}
      <View className="overflow-hidden rounded-2xl border border-slate-200 bg-white" style={cardShadow}>
        {rows.map((row, i) => (
          <View key={i}>
            {i > 0 && <View className="ml-16 h-px bg-slate-100" />}
            {row}
          </View>
        ))}
      </View>
    </View>
  );
}

/** One row of a ListGroup: icon tile, title and detail, then a value, badge or chevron. */
export function ListItem({
  icon,
  color = 'blue',
  title,
  detail,
  right,
  onPress,
  chevron = !!onPress,
  badge,
  children,
  a11y,
}: {
  icon?: IconName;
  color?: TileColor;
  title: string;
  detail?: string;
  right?: ReactNode;
  onPress?: () => void;
  chevron?: boolean;
  badge?: number;
  /** Extra lines under the detail (badges, errors). */
  children?: ReactNode;
  a11y?: string;
}) {
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={a11y}
      disabled={!onPress}
      onPress={onPress}
      className="min-h-16 flex-row items-center gap-3 px-4 py-3 active:bg-slate-50">
      {icon && <IconTile icon={icon} color={color} size={36} />}
      <View className="flex-1 gap-0.5">
        <Text className="text-[15px] font-semibold text-slate-900" numberOfLines={2}>
          {title}
        </Text>
        {detail ? (
          <Text className="text-[13px] text-slate-500" numberOfLines={2}>
            {detail}
          </Text>
        ) : null}
        {children}
      </View>
      {right}
      {badge ? <CountBadge count={badge} /> : null}
      {chevron && <Ionicons name="chevron-forward" size={18} color={colors.faint} />}
    </Pressable>
  );
}

/** A colourful round action with its label under it, for the quick-action grid. */
export function QuickAction({ icon, label, color, onPress, count = 0 }: { icon: IconName; label: string; color: TileColor; onPress?: () => void; count?: number }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={count > 0 ? `${label}, ${count}` : label}
      onPress={onPress}
      disabled={!onPress}
      className="w-1/4 items-center gap-1.5 rounded-xl py-2 active:bg-slate-100">
      <View>
        <IconTile icon={icon} color={color} size={48} solid />
        <View className="absolute -right-2 -top-1.5">
          <CountBadge count={count} />
        </View>
      </View>
      <Text className="text-center text-xs font-medium text-slate-700" numberOfLines={2}>
        {label}
      </Text>
    </Pressable>
  );
}

/** Summary panel in the brand blue. Children set their own text colors (white / brand-200 read well on it). */
export function Hero({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <View className={`bg-brand-600 p-5 ${className}`}>{children}</View>;
}

/** A small figure on a Hero: label above, value below. */
export function HeroStat({ label, value, align = 'left' }: { label: string; value: string; align?: 'left' | 'right' }) {
  return (
    <View className={`flex-1 rounded-md bg-brand-700 px-3 py-2.5 ${align === 'right' ? 'items-end' : ''}`}>
      <Text className="text-xs font-medium text-brand-100">{label}</Text>
      <Text className="mt-0.5 text-base font-bold text-white" numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

/** Page heading for the tab screens. */
export function ScreenTitle({ title, subtitle, right }: { title: string; subtitle?: string; right?: ReactNode }) {
  return (
    <View className="flex-row items-end justify-between gap-3">
      <View className="flex-1">
        <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
          {title}
        </Text>
        {subtitle && <Text className="mt-0.5 text-base text-slate-500">{subtitle}</Text>}
      </View>
      {right}
    </View>
  );
}

export function SectionTitle({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <View className="mb-2 flex-row items-center justify-between">
      <Text accessibilityRole="header" className="text-base font-semibold text-slate-800">{children}</Text>
      {right}
    </View>
  );
}

export function EmptyState({
  icon,
  title,
  message,
  children,
}: {
  icon: IconName;
  title: string;
  message?: string;
  children?: ReactNode;
}) {
  return (
    <View className="flex-1 items-center justify-center gap-3 px-8 py-12">
      <View className="h-20 w-20 items-center justify-center rounded-full bg-brand-50">
        <View className="h-14 w-14 items-center justify-center rounded-full bg-brand-100">
          <Ionicons name={icon} size={28} color={colors.brand[600]} />
        </View>
      </View>
      <Text className="text-center text-lg font-semibold text-slate-800">{title}</Text>
      {message && <Text className="text-center text-base text-slate-500">{message}</Text>}
      {children}
    </View>
  );
}

const bannerTone = {
  info: { box: 'bg-brand-50 border-brand-200', text: 'text-brand-900', icon: 'information-circle', color: colors.brand[700] },
  warning: { box: 'bg-amber-50 border-amber-200', text: 'text-amber-900', icon: 'warning', color: colors.warning },
  error: { box: 'bg-red-50 border-red-200', text: 'text-red-800', icon: 'alert-circle', color: colors.dangerDark },
  success: { box: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-900', icon: 'checkmark-circle', color: colors.successDark },
} as const;

export function Banner({
  tone = 'info',
  children,
  className = '',
}: {
  tone?: keyof typeof bannerTone;
  children: ReactNode;
  className?: string;
}) {
  const t = bannerTone[tone];
  return (
    <View
      accessibilityRole="alert"
      className={`flex-row items-start gap-2 rounded-xl border px-3 py-2.5 ${t.box} ${className}`}>
      <Ionicons name={t.icon} size={18} color={t.color} style={{ marginTop: 1 }} />
      <Text className={`flex-1 text-sm ${t.text}`}>{children}</Text>
    </View>
  );
}

export function Row({ label, value, strong, tone = '' }: { label: string; value: string; strong?: boolean; tone?: string }) {
  return (
    <View className="flex-row items-center justify-between py-1">
      <Text className={`text-base ${strong ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>{label}</Text>
      <Text className={`${strong ? 'text-xl font-bold text-brand-900' : 'text-base font-medium text-slate-900'} ${tone}`}>
        {value}
      </Text>
    </View>
  );
}

export function Divider() {
  return <View className="my-2 h-px bg-slate-200" />;
}
