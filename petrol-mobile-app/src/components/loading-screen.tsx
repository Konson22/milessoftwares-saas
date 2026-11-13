import { Image } from 'expo-image';
import { useEffect } from 'react';
import { Text, View } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withRepeat, withSequence, withTiming } from 'react-native-reanimated';

import { colors } from '@/lib/theme';

import { ArtBackground } from './art-background';

const BAR = 160;

/** While the app opens (the database, the saved session): the emblem breathing and a bar sweeping under it. */
export function LoadingScreen() {
  const pulse = useSharedValue(0);
  const sweep = useSharedValue(0);

  useEffect(() => {
    pulse.value = withRepeat(withSequence(withTiming(1, { duration: 900, easing: Easing.inOut(Easing.quad) }), withTiming(0, { duration: 900, easing: Easing.inOut(Easing.quad) })), -1);
    sweep.value = withRepeat(withTiming(1, { duration: 1100, easing: Easing.inOut(Easing.cubic) }), -1);
  }, [pulse, sweep]);

  const logo = useAnimatedStyle(() => ({ transform: [{ scale: 1 + pulse.value * 0.06 }] }));
  const halo = useAnimatedStyle(() => ({ opacity: 0.15 + pulse.value * 0.25, transform: [{ scale: 1 + pulse.value * 0.25 }] }));
  const bar = useAnimatedStyle(() => ({ transform: [{ translateX: -BAR / 2 + sweep.value * BAR * 1.5 }] }));

  return (
    <ArtBackground>
      <View className="flex-1 items-center justify-center gap-8" accessible accessibilityRole="progressbar" accessibilityLabel="Miles Petrol is opening">
        <View className="items-center justify-center">
          <Animated.View style={[{ position: 'absolute', width: 150, height: 150, borderRadius: 75, backgroundColor: colors.brand[500] }, halo]} />
          <Animated.View style={logo}>
            <Image source={require('../../assets/images/logo-light.png')} contentFit="contain" style={{ width: 112, height: 112 }} accessible={false} />
          </Animated.View>
        </View>
        <View className="items-center gap-3">
          <Text className="text-2xl font-bold text-white">Miles Petrol</Text>
          <View className="h-1 overflow-hidden bg-white/15" style={{ width: BAR }}>
            <Animated.View className="h-1 bg-accent" style={[{ width: BAR / 2 }, bar]} />
          </View>
          <Text className="text-sm font-semibold uppercase tracking-[4px] text-brand-200">Opening</Text>
        </View>
      </View>
    </ArtBackground>
  );
}
