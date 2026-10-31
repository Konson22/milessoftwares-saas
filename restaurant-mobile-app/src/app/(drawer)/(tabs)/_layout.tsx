import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import type { ColorValue } from 'react-native';

import { BottomNav } from '@/components/bottom-nav';
import type { IconName } from '@/components/ui/button';
import { useAccess } from '@/hooks/use-page';
import { allowed, navItem } from '@/lib/navigation';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

export const unstable_settings = { initialRouteName: 'home' };

/** Outline icon at rest, filled when the tab is active; BottomNav draws the pill behind it. */
function tabIcon(outline: IconName, filled: IconName) {
  return function TabIcon({ color, size, focused }: { color: ColorValue; size: number; focused: boolean }) {
    return <Ionicons name={focused ? filled : outline} size={size} color={color as string} />;
  };
}

/**
 * The restaurant's everyday screens, a tap away: those this member may use
 * (a cook sees the kitchen, a waiter the floor). The rest is in the drawer.
 */
export default function TabsLayout() {
  const shared = useAccess((s) => s.shared);
  const mode = useAuth((s) => s.mode);
  const shown = (path: string) => {
    const item = navItem(path);
    return item !== null && allowed(item, shared, mode) ? undefined : null;
  };

  return (
    <Tabs tabBar={(props) => <BottomNav {...props} />} screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.canvas } }}>
      <Tabs.Screen name="home" options={{ title: 'Home', tabBarIcon: tabIcon('home-outline', 'home') }} />
      <Tabs.Screen name="floor" options={{ title: 'Floor', tabBarIcon: tabIcon('grid-outline', 'grid'), href: shown('floor') }} />
      <Tabs.Screen name="kitchen" options={{ title: 'Kitchen', tabBarIcon: tabIcon('flame-outline', 'flame'), href: shown('kitchen') }} />
      <Tabs.Screen name="bills" options={{ title: 'Bills', tabBarIcon: tabIcon('receipt-outline', 'receipt'), href: shown('bills') }} />
      <Tabs.Screen name="tills" options={{ title: 'Shift', tabBarIcon: tabIcon('wallet-outline', 'wallet'), href: shown('tills') }} />
    </Tabs>
  );
}
