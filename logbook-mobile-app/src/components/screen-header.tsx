import { useNavigation } from 'expo-router';
import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IconButton, type IconName } from '@/components/ui/button';

type DrawerParent = {
  openDrawer?: () => void;
  getParent?: () => DrawerParent | undefined;
};

/** Opens the side menu, including from a screen nested under it (the tabs). */
export function useOpenDrawer(): () => void {
  const navigation = useNavigation() as DrawerParent;
  return () => {
    let current: DrawerParent | undefined = navigation;
    while (current) {
      if (typeof current.openDrawer === 'function') {
        current.openDrawer();
        return;
      }
      current = current.getParent?.();
    }
  };
}

/**
 * Title bar in the brand's deep blue, for every signed-in screen.
 * Owns the top safe-area inset so the colour runs up under the status bar.
 */
export function ScreenHeader({
  title,
  subtitle,
  icon,
  onIcon,
  iconLabel,
  menu,
  right,
  children,
}: {
  title: string;
  subtitle?: string;
  /** Leading button (back / close); omit for none. Ignored when `menu` is set. */
  icon?: IconName;
  onIcon?: () => void;
  iconLabel?: string;
  /** Leading button that opens the side menu. */
  menu?: boolean;
  right?: ReactNode;
  /** Extra content under the title row, e.g. a progress bar. */
  children?: ReactNode;
}) {
  const insets = useSafeAreaInsets();
  const openMenu = useOpenDrawer();
  const leadingIcon = menu ? 'menu' : icon;
  const leadingPress = menu ? openMenu : onIcon;
  const leadingLabel = menu ? 'Menu' : (iconLabel ?? 'Back');
  return (
    <View className="bg-brand-logo pb-3" style={{ paddingTop: insets.top + 6 }}>
      <View className="flex-row items-center gap-1 px-2">
        {leadingIcon ? <IconButton icon={leadingIcon} label={leadingLabel} onPress={leadingPress} onDark /> : <View className="w-3" />}
        <View className="flex-1">
          <Text accessibilityRole="header" className="text-[19px] font-bold text-white" numberOfLines={1}>
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
