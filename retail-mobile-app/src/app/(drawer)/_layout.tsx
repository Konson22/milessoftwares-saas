import { Drawer } from 'expo-router/drawer';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';

import { DrawerMenu } from '@/components/drawer-menu';
import { PosHeader } from '@/components/pos-header';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import { loadAccess, useAccess } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

export const unstable_settings = { initialRouteName: '(tabs)' };

/**
 * The signed-in app: the counter's everyday screens on tabs (sell,
 * sales, shift), and every section of the shop in a drawer from the right.
 */
export default function DrawerLayout() {
  const branchId = useAuth((s) => s.branchId);
  const counter = useAuth((s) => s.counter);
  const refreshCounter = useAuth((s) => s.refreshCounter);
  const refreshTill = useAuth((s) => s.refreshTill);
  const signOut = useAuth((s) => s.signOut);
  const [error, setError] = useState<string | null>(null);

  // The counter (what this member may do here, and its rules) and their shift.
  const load = useCallback(async () => {
    setError(null);
    try {
      await Promise.all([refreshCounter(), refreshTill()]);
    } catch (e) {
      setError(errorMessage(e));
    }
  }, [refreshCounter, refreshTill]);

  useEffect(() => {
    Promise.all([refreshCounter(), refreshTill()])
      .then(() => setError(null))
      .catch((e) => setError(errorMessage(e)));
  }, [refreshCounter, refreshTill, branchId]);

  // The sections this member may open here (from the shop's dashboard).
  useEffect(() => {
    useAccess.getState().clear();
    void loadAccess();
  }, [branchId]);

  if (!counter) {
    return (
      <View className="flex-1 bg-canvas">
        <PosHeader />
        {error ? (
          <EmptyState icon="cloud-offline-outline" title="Could not open the counter" message={error}>
            <Button title="Try again" icon="refresh" onPress={load} />
            <Button title="Sign out" variant="ghost" onPress={signOut} />
          </EmptyState>
        ) : (
          <View className="flex-1 items-center justify-center">
            <ActivityIndicator size="large" color={colors.brand[600]} />
          </View>
        )}
      </View>
    );
  }

  return (
    <Drawer
      drawerContent={(props) => <DrawerMenu {...props} />}
      screenOptions={({ navigation }) => ({
        header: () => <PosHeader onMenu={() => navigation.toggleDrawer()} />,
        drawerType: 'front',
        drawerPosition: 'right',
        drawerStyle: { width: 300, backgroundColor: colors.white },
        sceneStyle: { backgroundColor: colors.canvas },
      })}>
      <Drawer.Screen name="(tabs)" options={{ title: 'Counter' }} />
      <Drawer.Screen name="p/[section]" options={{ title: 'Shop' }} />
    </Drawer>
  );
}
