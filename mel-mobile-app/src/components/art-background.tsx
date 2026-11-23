import { Image } from 'expo-image';
import type { ReactNode } from 'react';
import { StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BRAND } from '@/lib/brand';

/** Deep teal backdrop with soft circles, for the screens before signing in. */
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

/**
 * The sign-in screen's header: the field-worker photo across the top, the
 * form under it. The file's own fade (a light scrim under the status bar,
 * deep teal at the bottom) carries it into the backdrop, see
 * assets/images/login-bg.jpg.
 */
export function PhotoHeader() {
  const { width, height } = useWindowDimensions();
  // The photo's own shape (941 x 1035), but never more than half the screen,
  // so the fields and Sign in still fit under it.
  const size = Math.min(width * (1035 / 941), height * 0.5);
  return (
    <View style={{ height: size }}>
      <Image
        source={require('../../assets/images/login-bg.jpg')}
        contentFit="cover"
        contentPosition="top"
        style={StyleSheet.absoluteFill}
        accessible={false}
      />
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
