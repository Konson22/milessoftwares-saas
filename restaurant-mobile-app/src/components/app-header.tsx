import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors } from '@/lib/theme';
import { useAuth, useBranch } from '@/store/auth';

/** The app bar: logo, the restaurant and branch, how it works (online, offline), and the menu. */
export function AppHeader({ onMenu }: { onMenu?: () => void }) {
  const insets = useSafeAreaInsets();
  const branch = useBranch();
  const organization = useAuth((s) => s.me?.organization.name);
  const mode = useAuth((s) => s.mode);
  const reachable = useAuth((s) => s.reachable);
  const lost = mode === 'online' && !reachable;

  return (
    <View className="bg-brand-600" style={{ paddingTop: insets.top + 8 }}>
      <View className="flex-row items-center gap-3 px-3 pb-2.5">
        <Image source={require('../../assets/images/logo-light.png')} contentFit="contain" style={{ width: 32, height: 32 }} accessible={false} />
        <View className="flex-1">
          <Text className="text-base font-bold text-white" numberOfLines={1}>
            {organization ?? 'Miles Restaurant'}
          </Text>
          <View className="flex-row items-center gap-1">
            <Ionicons name={mode === 'offline' ? 'phone-portrait-outline' : lost ? 'cloud-offline-outline' : 'cloud-done-outline'} size={12} color={colors.brand[100]} />
            <Text className="text-xs text-brand-100" numberOfLines={1}>
              {mode === 'offline' ? 'Offline · on this phone' : `${branch && branch.name !== organization ? `${branch.name} · ` : ''}${lost ? 'no connection' : 'online'}`}
            </Text>
          </View>
        </View>
        {/* The drawer opens from the right, so its button sits on that side. */}
        {onMenu ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Open menu"
            onPress={onMenu}
            hitSlop={6}
            className="-mr-1 h-11 w-11 items-center justify-center active:bg-white/15">
            <Ionicons name="menu" size={26} color={colors.white} />
          </Pressable>
        ) : null}
      </View>
      {lost ? (
        <View className="flex-row items-center gap-2 bg-amber-500 px-4 py-1.5">
          <Ionicons name="warning-outline" size={14} color={colors.white} />
          <Text className="flex-1 text-xs font-semibold text-white">No connection to the restaurant: what you see may be out of date.</Text>
        </View>
      ) : null}
    </View>
  );
}
