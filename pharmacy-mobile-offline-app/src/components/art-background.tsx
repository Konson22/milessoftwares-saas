import { Image } from 'expo-image';
import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/** Navy backdrop with soft glass circles, for the signed-out and branch screens. */
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

/** The app's emblem and name, at the top of the signed-out screens. */
export function BrandMark() {
  const insets = useSafeAreaInsets();
  return (
    <View
      className="items-center gap-3 pb-6"
      style={{ paddingTop: insets.top + 36 }}
      accessible
      accessibilityRole="header"
      accessibilityLabel="Miles Pharmacy POS, by Miles Softwares">
      <Image
        source={require('../../assets/images/splash-icon.png')}
        contentFit="contain"
        style={{ width: 88, height: 88 }}
        accessibilityIgnoresInvertColors
      />
      <View className="items-center">
        <Text className="text-3xl font-bold text-white">Miles Pharmacy</Text>
        <Text className="text-base font-semibold uppercase tracking-[4px] text-accent">Point of sale</Text>
      </View>
    </View>
  );
}
