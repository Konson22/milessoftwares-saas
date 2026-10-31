import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IconButton, type IconName } from '@/components/ui/button';

/**
 * Title bar, in the icon's red, for the screens that sit above the tabs (checkout, receipt, returns, account).
 * Owns the top safe-area inset so the blue runs up under the status bar.
 */
export function ScreenHeader({
  title,
  subtitle,
  icon,
  onIcon,
  iconLabel,
  right,
  children,
}: {
  title: string;
  subtitle?: string;
  /** Leading button (back / close); omit for none. */
  icon?: IconName;
  onIcon?: () => void;
  iconLabel?: string;
  right?: ReactNode;
  /** Extra content under the title row, e.g. a progress bar. */
  children?: ReactNode;
}) {
  const insets = useSafeAreaInsets();
  return (
    <View className="bg-brand-600 pb-2" style={{ paddingTop: insets.top + 4 }}>
      <View className="flex-row items-center gap-1 px-2">
        {icon ? <IconButton icon={icon} label={iconLabel ?? 'Back'} onPress={onIcon} onDark /> : <View className="w-3" />}
        <View className="flex-1">
          <Text accessibilityRole="header" className="text-lg font-bold text-white" numberOfLines={1}>
            {title}
          </Text>
          {subtitle && (
            <Text className="text-sm text-brand-100" numberOfLines={1}>
              {subtitle}
            </Text>
          )}
        </View>
        {right}
      </View>
      {children}
    </View>
  );
}
