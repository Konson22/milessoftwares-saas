import { Tabs } from 'expo-router';

import { BottomBar } from '@/components/bottom-bar';
import { colors } from '@/lib/theme';
import { buckets } from '@/logbook/sync';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { useWork } from '@/store/work';

/** Daily work. Reviews, sending, notifications and account are in the side menu. */
export default function TabsLayout() {
  const items = useLogbook((s) => s.items);
  const can = useAuth((s) => s.me?.can);
  const b = buckets(items);
  const needYou = b.returned.length + b.refused.length;
  const tasks = useWork((s) => s.tasks);
  const toDo = tasks.filter((t) => ['pending', 'in_progress', 'returned'].includes(t.status)).length;

  return (
    <Tabs tabBar={(props) => <BottomBar {...props} />} screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.canvas } }}>
      <Tabs.Screen name="home" options={{ title: 'Home' }} />
      <Tabs.Screen name="entries" options={{ title: 'Entries', tabBarBadge: needYou > 0 ? needYou : undefined }} />
      <Tabs.Screen name="new" options={{ title: 'New', href: can?.write === false ? null : undefined }} />
      <Tabs.Screen name="tasks" options={{ title: 'Tasks', tabBarBadge: toDo > 0 ? toDo : undefined }} />
      <Tabs.Screen
        name="more"
        options={{ title: 'More' }}
        listeners={({ navigation }) => ({
          tabPress: (e) => {
            e.preventDefault();
            (navigation.getParent() as { openDrawer?: () => void } | undefined)?.openDrawer?.();
          },
        })}
      />
    </Tabs>
  );
}
