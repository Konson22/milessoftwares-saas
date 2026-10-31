import type { BottomTabBarProps } from 'expo-router/tabs';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { haptic } from '@/lib/feedback';
import { colors } from '@/lib/theme';

/**
 * The app's bottom navigation. Drawn here rather than by React Navigation, whose fixed icon
 * box clipped the active pill into the labels; this bar sizes itself and owns the bottom inset,
 * so it always clears the home indicator / Android navigation bar.
 */
export function BottomNav({ state, descriptors, navigation }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();

  // `href: null` tabs (a section this member may not use) arrive hidden via their item style.
  const routes = state.routes.filter((route) => {
    const style = StyleSheet.flatten(descriptors[route.key].options.tabBarItemStyle);
    return style?.display !== 'none';
  });

  return (
    <View
      accessibilityRole="tablist"
      className="border-t border-slate-200 bg-white px-2 pt-1.5 shadow-lg shadow-brand-900/10"
      style={{ paddingBottom: Math.max(insets.bottom, 8) }}>
      <View className="w-full max-w-xl flex-row self-center">
        {routes.map((route) => {
          const { options } = descriptors[route.key];
          const focused = state.routes[state.index].key === route.key;
          const label = options.title ?? route.name;
          const color = focused ? colors.brand[700] : colors.muted;
          const badge = options.tabBarBadge;

          const onPress = () => {
            const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!focused && !event.defaultPrevented) {
              haptic.tap();
              navigation.navigate(route.name, route.params);
            }
          };

          return (
            <Pressable
              key={route.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: focused }}
              accessibilityLabel={badge !== undefined ? `${label}, ${badge}` : label}
              onPress={onPress}
              onLongPress={() => navigation.emit({ type: 'tabLongPress', target: route.key })}
              className="min-h-14 flex-1 items-center justify-center gap-1 py-1">
              <View className={`h-8 w-14 items-center justify-center rounded-full ${focused ? 'bg-brand-100' : ''}`}>
                {options.tabBarIcon?.({ focused, color, size: 22 })}
                {badge !== undefined && (
                  <View className="absolute -top-1 right-1.5 h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-red-600 px-1">
                    <Text className="text-[10px] font-bold leading-3 text-white">{badge}</Text>
                  </View>
                )}
              </View>
              <Text
                numberOfLines={1}
                className={`text-xs ${focused ? 'font-bold text-brand-800' : 'font-semibold text-slate-500'}`}>
                {label}
              </Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}
