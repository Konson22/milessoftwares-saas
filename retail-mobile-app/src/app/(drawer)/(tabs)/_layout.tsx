import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import type { ColorValue } from 'react-native';

import { BottomNav } from '@/components/bottom-nav';
import type { IconName } from '@/components/ui/button';
import { colors } from '@/lib/theme';
import { useCart } from '@/store/cart';

export const unstable_settings = { initialRouteName: 'home' };

/** Outline icon at rest, filled when the tab is active; BottomNav draws the pill behind it. */
function tabIcon(outline: IconName, filled: IconName) {
  return function TabIcon({ color, size, focused }: { color: ColorValue; size: number; focused: boolean }) {
    return <Ionicons name={focused ? filled : outline} size={size} color={color as string} />;
  };
}

/** The counter's everyday screens, a tap away; the rest of the shop is in the drawer. */
export default function TabsLayout() {
  const cartCount = useCart((s) => s.lines.length);

  return (
    <Tabs
      tabBar={(props) => <BottomNav {...props} />}
      screenOptions={{
        // The drawer's header (branch, shift, menu) sits above the tabs.
        headerShown: false,
        sceneStyle: { backgroundColor: colors.canvas },
      }}>
      <Tabs.Screen name="home" options={{ title: 'Home', tabBarIcon: tabIcon('home-outline', 'home') }} />
      <Tabs.Screen
        name="sell"
        options={{
          title: 'Sell',
          tabBarIcon: tabIcon('cart-outline', 'cart'),
          tabBarBadge: cartCount > 0 ? (cartCount > 99 ? '99+' : cartCount) : undefined,
        }}
      />
      <Tabs.Screen name="sales" options={{ title: 'Sales', tabBarIcon: tabIcon('receipt-outline', 'receipt') }} />
      <Tabs.Screen name="shift" options={{ title: 'Shift', tabBarIcon: tabIcon('cash-outline', 'cash') }} />
    </Tabs>
  );
}
