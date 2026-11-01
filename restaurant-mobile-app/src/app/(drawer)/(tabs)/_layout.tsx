import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import type { ColorValue } from 'react-native';

import { BottomNav } from '@/components/bottom-nav';
import type { IconName } from '@/components/ui/button';
import { useAccess } from '@/hooks/use-page';
import { allowed, navItem } from '@/lib/navigation';
import { colors } from '@/lib/theme';

export const unstable_settings = { initialRouteName: 'home' };

/** Outline icon at rest, filled when the tab is active; BottomNav draws the pill behind it. */
function tabIcon(outline: IconName, filled: IconName) {
  return function TabIcon({ color, size, focused }: { color: ColorValue; size: number; focused: boolean }) {
    return <Ionicons name={focused ? filled : outline} size={size} color={color as string} />;
  };
}

/**
 * The restaurant's everyday screens, a tap away: those this member may use
 * (a waiter sees orders, a manager tables too). The rest is in the drawer.
 */
export default function TabsLayout() {
  const shared = useAccess((s) => s.shared);
  const shown = (path: string) => {
    const item = navItem(path);
    return item !== null && allowed(item, shared) ? undefined : null;
  };

  return (
    <Tabs tabBar={(props) => <BottomNav {...props} />} screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.canvas } }}>
      <Tabs.Screen name="home" options={{ title: 'Home', tabBarIcon: tabIcon('home-outline', 'home') }} />
      <Tabs.Screen name="tables" options={{ title: 'Tables', tabBarIcon: tabIcon('apps-outline', 'apps'), href: shown('tables') === undefined ? undefined : shown('floor') }} />
      <Tabs.Screen name="orders" options={{ title: 'Orders', tabBarIcon: tabIcon('list-outline', 'list'), href: shown('orders') }} />
      <Tabs.Screen name="bills" options={{ title: 'Bills', tabBarIcon: tabIcon('receipt-outline', 'receipt'), href: shown('bills') }} />
      <Tabs.Screen name="menu" options={{ title: 'Menu', tabBarIcon: tabIcon('restaurant-outline', 'restaurant'), href: shown('menu') }} />
    </Tabs>
  );
}
