import { Ionicons } from '@expo/vector-icons';
import { forwardRef, useState, type ComponentRef, type ReactNode } from 'react';
import { Text, TextInput, View, type TextInputProps } from 'react-native';

import { colors, webNoOutline } from '@/lib/theme';

import type { IconName } from './button';

interface Props extends TextInputProps {
  label?: string;
  error?: string | null;
  hint?: string;
  prefix?: string;
  /** Leading icon inside the input. */
  icon?: IconName;
  /** Rendered after the input, e.g. a show/hide password toggle. */
  trailing?: ReactNode;
  /** Glass styling for the dark artwork screens (login, branch selection). */
  dark?: boolean;
  className?: string;
}

export const Field = forwardRef<ComponentRef<typeof TextInput>, Props>(function Field(
  { label, error, hint, prefix, icon, trailing, dark, className = '', onFocus, onBlur, ...input },
  ref,
) {
  const [focused, setFocused] = useState(false);

  const box = dark
    ? `bg-white/10 ${error ? 'border-red-300' : focused ? 'border-white' : 'border-white/20'}`
    : `bg-white ${error ? 'border-red-400' : focused ? 'border-brand-500' : 'border-slate-300'}`;

  return (
    <View className={`gap-1.5 ${className}`}>
      {label && <Text className={`text-sm font-medium ${dark ? 'text-brand-100' : 'text-slate-700'}`}>{label}</Text>}
      <View className={`min-h-12 flex-row items-center rounded-2xl border px-3 ${trailing ? 'pr-1' : ''} ${box}`}>
        {icon && <Ionicons name={icon} size={20} color={dark ? colors.brand[200] : colors.muted} style={{ marginRight: 8 }} />}
        {prefix && <Text className={`mr-2 text-base ${dark ? 'text-brand-100' : 'text-slate-500'}`}>{prefix}</Text>}
        <TextInput
          ref={ref}
          placeholderTextColor={dark ? 'rgba(217, 233, 255, 0.5)' : colors.faint}
          selectionColor={dark ? colors.brand[300] : colors.brand[500]}
          className={`min-w-0 flex-1 py-3 text-base ${dark ? 'text-white' : 'text-slate-900'}`}
          accessibilityLabel={label}
          style={webNoOutline}
          onFocus={(e) => {
            setFocused(true);
            onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            onBlur?.(e);
          }}
          {...input}
        />
        {trailing}
      </View>
      {error ? (
        <Text className={`text-sm ${dark ? 'text-red-200' : 'text-red-600'}`}>{error}</Text>
      ) : hint ? (
        <Text className={`text-sm ${dark ? 'text-brand-100' : 'text-slate-500'}`}>{hint}</Text>
      ) : null}
    </View>
  );
});
