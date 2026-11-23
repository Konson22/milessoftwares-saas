import { Ionicons } from '@expo/vector-icons';
import type { Tabs } from 'expo-router';
import type { ComponentProps } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

type TabBarProps = Parameters<NonNullable<ComponentProps<typeof Tabs>['tabBar']>>[0];
type IconName = ComponentProps<typeof Ionicons>['name'];

const ICONS: Record<string, [IconName, IconName]> = {
  home: ['home', 'home-outline'],
  entries: ['document-text', 'document-text-outline'],
  tasks: ['checkbox', 'checkbox-outline'],
  more: ['grid', 'grid-outline'],
};

/**
 * The bottom bar: four tabs around a raised New button. It pads itself by
 * the phone's bottom inset (gesture bar or buttons), so labels are never
 * hidden under the system bar.
 */
export function BottomBar({ state, descriptors, navigation }: TabBarProps) {
  const insets = useSafeAreaInsets();
  const canWrite = useAuth((s) => s.me?.can.write) !== false;

  return (
    <View
      className="flex-row items-end border-t border-slate-200 bg-white px-2"
      style={{ paddingTop: 6, paddingBottom: Math.max(insets.bottom, 8) }}
      accessibilityRole="tablist">
      {state.routes.map((route, index) => {
        if (route.name === 'new' && !canWrite) {
          return null;
        }
        const { options } = descriptors[route.key];
        const focused = state.index === index;
        const label = typeof options.title === 'string' ? options.title : route.name;
        const badge = options.tabBarBadge;

        const press = () => {
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) {
            navigation.navigate(route.name, route.params);
          }
        };

        if (route.name === 'new') {
          return (
            <Pressable
              key={route.key}
              accessibilityRole="tab"
              accessibilityState={{ selected: focused }}
              accessibilityLabel="New entry"
              onPress={press}
              className="flex-1 items-center">
              {({ pressed }) => (
                <>
                  <View
                    className={`-mt-7 h-14 w-14 items-center justify-center rounded-full border-4 border-white ${pressed || focused ? 'bg-brand-700' : 'bg-brand-600'}`}
                    style={{ shadowColor: '#9aa6c4', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 1, shadowRadius: 4, elevation: 5 }}>
                    <Ionicons name="add" color={colors.white} size={30} />
                  </View>
                  <Text className={`mt-0.5 text-[11px] font-semibold ${focused ? 'text-brand-700' : 'text-slate-500'}`} numberOfLines={1}>
                    New
                  </Text>
                </>
              )}
            </Pressable>
          );
        }

        const [on, off] = ICONS[route.name] ?? ['ellipse', 'ellipse-outline'];

        return (
          <Pressable
            key={route.key}
            accessibilityRole="tab"
            accessibilityState={{ selected: focused }}
            accessibilityLabel={badge ? `${label}, ${badge}` : label}
            onPress={press}
            className="flex-1 items-center gap-1 py-1">
            {({ pressed }) => (
              <>
                <View className={`h-8 w-14 items-center justify-center rounded-full ${focused ? 'bg-brand-50' : pressed ? 'bg-slate-100' : ''}`}>
                  <Ionicons name={focused ? on : off} size={22} color={focused ? colors.brand[600] : colors.faint} />
                  {badge !== undefined && (
                    <View className="absolute -top-1 right-1.5 min-w-[18px] items-center rounded-full border-2 border-white bg-red-600 px-1">
                      <Text className="text-[10px] font-bold text-white">{typeof badge === 'number' && badge > 99 ? '99+' : badge}</Text>
                    </View>
                  )}
                </View>
                <Text className={`text-[11px] ${focused ? 'font-bold text-brand-700' : 'font-semibold text-slate-500'}`} numberOfLines={1}>
                  {label}
                </Text>
              </>
            )}
          </Pressable>
        );
      })}
    </View>
  );
}
