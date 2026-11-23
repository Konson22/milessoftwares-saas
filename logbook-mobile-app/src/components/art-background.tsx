import { Image } from 'expo-image';
import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BRAND } from '@/lib/brand';

/** Deep blue backdrop ruled like a log book's page, for the screens before signing in. Solid colours only. */
export function ArtBackground({ children }: { children: ReactNode }) {
  return (
    <View className="flex-1 overflow-hidden bg-brand-950">
      <View pointerEvents="none" className="absolute bottom-0 left-10 top-0 w-0.5 bg-accent-700" />
      {Array.from({ length: 16 }, (_, i) => (
        <View key={i} pointerEvents="none" className="absolute left-0 right-0 h-px bg-brand-900" style={{ top: 64 + i * 64 }} />
      ))}
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
      accessibilityLabel={`${BRAND.name}, ${BRAND.subtitle}`}>
      <Image source={require('../../assets/images/logo-light.png')} contentFit="contain" style={{ width: size, height: size }} accessibilityIgnoresInvertColors />
      <View className="items-center gap-0.5">
        <Text className={`font-bold text-white ${compact ? 'text-2xl' : 'text-3xl'}`}>{BRAND.name}</Text>
        <Text className="text-sm font-semibold text-brand-200">{BRAND.subtitle}</Text>
      </View>
    </View>
  );
}
