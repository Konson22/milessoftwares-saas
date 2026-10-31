import { Image } from 'expo-image';
import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/** Deep red backdrop with soft glass circles, for the screens before signing in. */
export function ArtBackground({ children }: { children: ReactNode }) {
  return (
    <View className="flex-1 overflow-hidden bg-brand-950">
      <View pointerEvents="none" className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-brand-600/40" />
      <View pointerEvents="none" className="absolute -left-20 top-1/3 h-64 w-64 rounded-full bg-accent/10" />
      <View pointerEvents="none" className="absolute -bottom-28 right-6 h-72 w-72 rounded-full bg-brand-700/30" />
      {children}
    </View>
  );
}

/** The app's emblem and name, at the top of the screens before signing in. */
export function BrandMark({ compact }: { compact?: boolean }) {
  const insets = useSafeAreaInsets();
  const size = compact ? 56 : 80;
  return (
    <View
      className="items-center gap-3 pb-6"
      style={{ paddingTop: insets.top + (compact ? 16 : 36) }}
      accessible
      accessibilityRole="header"
      accessibilityLabel="Miles Restaurant, by Miles Softwares">
      <Image source={require('../../assets/images/logo-light.png')} contentFit="contain" style={{ width: size, height: size }} accessibilityIgnoresInvertColors />
      <View className="items-center">
        <Text className={`font-bold text-white ${compact ? 'text-2xl' : 'text-3xl'}`}>Miles Restaurant</Text>
        <Text className="text-sm font-semibold uppercase tracking-[4px] text-brand-200">Tables · kitchen · bills</Text>
      </View>
    </View>
  );
}
