import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors } from '@/lib/theme';
import { SyncBar } from './sync-bar';
import { useAuth, useBranch } from '@/store/auth';

/** The app bar: logo, the restaurant and branch, whether the restaurant answers, and the menu. */
export function AppHeader({ onMenu }: { onMenu?: () => void }) {
  const insets = useSafeAreaInsets();
  const branch = useBranch();
  const organization = useAuth((s) => s.me?.organization.name);
  const lost = !useAuth((s) => s.reachable);

  return (
    <View className="bg-brand-logo" style={{ paddingTop: insets.top + 8 }}>
      <View className="flex-row items-center gap-3 px-3 pb-2.5">
        <Image source={require('../../assets/images/logo-light.png')} contentFit="contain" style={{ width: 32, height: 32 }} accessible={false} />
        <View className="flex-1">
          <Text className="text-base font-bold text-white" numberOfLines={1}>
            {organization ?? 'Mataem Plus'}
          </Text>
          <View className="flex-row items-center gap-1">
            <Ionicons name={lost ? 'cloud-offline-outline' : 'cloud-done-outline'} size={12} color={colors.brand[100]} />
            <Text className="text-xs text-brand-100" numberOfLines={1}>
              {`${branch && branch.name !== organization ? `${branch.name} · ` : ''}${lost ? 'no connection' : 'online'}`}
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
      <SyncBar />
    </View>
  );
}
