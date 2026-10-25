import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { router, usePathname } from 'expo-router';
import { useEffect } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors } from '@/lib/theme';
import { useAuth, useBranch } from '@/store/auth';
import { useNotices } from '@/store/notices';

/** The counter's app bar: logo, the pharmacy and the open shift, notifications; `onMenu` opens the drawer. */
export function PosHeader({ onMenu }: { onMenu?: () => void }) {
  const insets = useSafeAreaInsets();
  const branch = useBranch();
  const shift = useAuth((s) => s.till);
  const count = useNotices((s) => s.notices.length);
  const refresh = useNotices((s) => s.refresh);
  const pathname = usePathname();

  // Counted again on every screen change: a sale or a delivery changes what needs attention.
  useEffect(() => {
    void refresh();
  }, [pathname, refresh]);

  return (
    <View className="bg-brand-600 px-3 pb-2.5" style={{ paddingTop: insets.top + 8 }}>
      <View className="flex-row items-center gap-3">
        <Image
          source={require('../../assets/images/logo-mark.png')}
          contentFit="contain"
          style={{ width: 34, height: 34 }}
          accessibilityIgnoresInvertColors
          accessible={false}
        />
        <View className="flex-1">
          <Text className="text-base font-bold text-white" numberOfLines={1}>
            {branch?.name ?? 'No branch'}
          </Text>
          {shift && (
            <Text className="text-xs text-brand-100" numberOfLines={1}>
              Shift {shift.number}
            </Text>
          )}
        </View>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={count ? `Notifications, ${count} new` : 'Notifications'}
          onPress={() => router.push('/notifications')}
          hitSlop={4}
          className="h-11 w-11 items-center justify-center active:bg-white/15">
          <Ionicons name={count ? 'notifications' : 'notifications-outline'} size={24} color={colors.white} />
          {count > 0 && (
            <View className="absolute right-1 top-1 h-[18px] min-w-[18px] items-center justify-center bg-accent-700 px-1">
              <Text className="text-[11px] font-bold text-white">{count > 9 ? '9+' : count}</Text>
            </View>
          )}
        </Pressable>
        {/* The drawer opens from the right, so its button sits on that side. */}
        {onMenu && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Open menu"
            onPress={onMenu}
            hitSlop={6}
            className="-mr-1 h-11 w-11 items-center justify-center active:bg-white/15">
            <Ionicons name="menu" size={26} color={colors.white} />
          </Pressable>
        )}
      </View>
    </View>
  );
}
