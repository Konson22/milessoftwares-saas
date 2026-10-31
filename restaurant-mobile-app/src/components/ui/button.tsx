import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { ActivityIndicator, Pressable, Text } from 'react-native';

import { colors } from '@/lib/theme';

type Variant = 'primary' | 'accent' | 'secondary' | 'outline' | 'danger' | 'ghost' | 'glass';

const container: Record<Variant, string> = {
  primary: 'bg-brand-600 active:bg-brand-700',
  // The icon's navy: taking payment.
  accent: 'bg-accent-700 active:bg-accent-600',
  secondary: 'bg-brand-50 active:bg-brand-100',
  outline: 'border border-brand-200 bg-white active:bg-brand-50',
  danger: 'bg-red-600 active:bg-red-700',
  ghost: 'active:bg-brand-50',
  // For use on the dark artwork / red surfaces.
  glass: 'border border-white/25 bg-white/10 active:bg-white/20',
};

const label: Record<Variant, string> = {
  primary: 'text-white',
  accent: 'text-white',
  secondary: 'text-brand-800',
  outline: 'text-brand-800',
  danger: 'text-white',
  ghost: 'text-brand-700',
  glass: 'text-white',
};

const iconColor: Record<Variant, string> = {
  primary: colors.white,
  accent: colors.white,
  secondary: colors.brand[800],
  outline: colors.brand[700],
  danger: colors.white,
  ghost: colors.brand[700],
  glass: colors.white,
};

export type IconName = ComponentProps<typeof Ionicons>['name'];

interface Props {
  title: string;
  onPress?: () => void;
  variant?: Variant;
  size?: 'md' | 'lg';
  icon?: IconName;
  loading?: boolean;
  disabled?: boolean;
  /** Kept for older call sites: every button is square now. */
  square?: boolean;
  className?: string;
  accessibilityLabel?: string;
}

export function Button({
  title,
  onPress,
  variant = 'primary',
  size = 'md',
  icon,
  loading,
  disabled,
  className = '',
  accessibilityLabel,
}: Props) {
  const inactive = disabled || loading;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      onPress={onPress}
      disabled={inactive}
      className={`flex-row items-center justify-center gap-1.5 px-3 ${size === 'lg' ? 'min-h-11' : 'min-h-9'} ${container[variant]} ${inactive ? 'opacity-50' : ''} ${className}`}>
      {loading ? (
        <ActivityIndicator color={iconColor[variant]} />
      ) : (
        icon && <Ionicons name={icon} size={size === 'lg' ? 18 : 16} color={iconColor[variant]} />
      )}
      <Text className={`font-semibold ${size === 'lg' ? 'text-base' : 'text-sm'} ${label[variant]}`}>{title}</Text>
    </Pressable>
  );
}

export function IconButton({
  icon,
  onPress,
  label: a11y,
  color,
  onDark,
  className = '',
  disabled,
}: {
  icon: IconName;
  onPress?: () => void;
  label: string;
  color?: string;
  /** Sits on a red surface: white icon with a translucent press state. */
  onDark?: boolean;
  className?: string;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={a11y}
      onPress={onPress}
      disabled={disabled}
      hitSlop={6}
      className={`h-10 w-10 items-center justify-center ${onDark ? 'active:bg-white/15' : 'active:bg-brand-50'} ${
        disabled ? 'opacity-40' : ''
      } ${className}`}>
      <Ionicons name={icon} size={22} color={color ?? (onDark ? colors.white : colors.ink)} />
    </Pressable>
  );
}
