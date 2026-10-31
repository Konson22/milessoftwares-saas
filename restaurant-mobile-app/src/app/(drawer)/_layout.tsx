import { Drawer } from 'expo-router/drawer';
import { useEffect } from 'react';

import { AppHeader } from '@/components/app-header';
import { DrawerMenu } from '@/components/drawer-menu';
import { loadAccess, useAccess } from '@/hooks/use-page';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

export const unstable_settings = { initialRouteName: '(tabs)' };

/**
 * The signed-in app: the everyday screens on tabs (home, floor, kitchen,
 * bills, shift), and every section of the restaurant in a drawer from the
 * right, as the web's menu.
 */
export default function DrawerLayout() {
  const branchId = useAuth((s) => s.branchId);
  const userId = useAuth((s) => s.me?.user.id);

  // The sections this member may open here (from the restaurant's home page).
  useEffect(() => {
    useAccess.getState().clear();
    void loadAccess();
  }, [branchId, userId]);

  return (
    <Drawer
      drawerContent={(props) => <DrawerMenu {...props} />}
      screenOptions={({ navigation }) => ({
        header: () => <AppHeader onMenu={() => navigation.toggleDrawer()} />,
        drawerType: 'front',
        drawerPosition: 'right',
        drawerStyle: { width: 300, backgroundColor: colors.white },
        sceneStyle: { backgroundColor: colors.canvas },
      })}>
      <Drawer.Screen name="(tabs)" options={{ title: 'Restaurant' }} />
      <Drawer.Screen name="p/[section]" options={{ title: 'Restaurant' }} />
    </Drawer>
  );
}
