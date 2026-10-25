import { Ionicons } from '@expo/vector-icons';
import type { ReactNode } from 'react';
import { Text, View } from 'react-native';

import { colors } from '@/lib/theme';

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
    <View className={`rounded-md px-1.5 py-0.5 ${bg}`} accessible accessibilityLabel={a11y ?? label}>
      <Text className={`text-xs font-bold ${fg}`}>{label}</Text>
    </View>
  );
}

/** Rx / CD markers with spoken labels for screen readers. */
export function RxBadges({ rx, cd }: { rx?: boolean; cd?: boolean }) {
  return (
    <>
      {rx && <Badge label="Rx" tone="blue" a11y="Prescription required" />}
      {cd && <Badge label="CD" tone="purple" a11y="Controlled drug" />}
    </>
  );
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <View className={`border border-slate-200 bg-white p-4 shadow-sm shadow-brand-900/10 ${className}`}>
      {children}
    </View>
  );
}

/** Summary panel in the logo's blue. Children set their own text colors (white / brand-200 read well on it). */
export function Hero({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <View className={`border-l-4 border-accent bg-brand-600 p-5 ${className}`}>{children}</View>;
}

/** A small figure on a Hero: label above, value below. */
export function HeroStat({ label, value, align = 'left' }: { label: string; value: string; align?: 'left' | 'right' }) {
  return (
    <View className={`flex-1 bg-white/10 px-3 py-2.5 ${align === 'right' ? 'items-end' : ''}`}>
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
      <View className="flex-row items-center gap-2">
        <View className="h-3.5 w-1 bg-accent" />
        <Text className="text-xs font-bold uppercase tracking-wider text-slate-600">{children}</Text>
      </View>
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
      className={`flex-row items-start gap-2 border px-3 py-2.5 ${t.box} ${className}`}>
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
