import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';

import { buckets } from '@/collect/overview';
import { colors } from '@/lib/theme';
import { useCollect } from '@/store/collect';

/** Where a fieldworker goes: what to do next, assigned work, forms, their numbers, and sending. */
export default function TabsLayout() {
  const items = useCollect((s) => s.items);
  const b = buckets(items);
  const toSend = b.waiting.length + b.refused.length;

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.brand[700],
        tabBarInactiveTintColor: colors.muted,
        tabBarLabelStyle: { fontSize: 12, fontWeight: '600' },
        tabBarStyle: { backgroundColor: colors.white, borderTopColor: colors.line, minHeight: 60 },
        sceneStyle: { backgroundColor: colors.canvas },
      }}>
      <Tabs.Screen
        name="home"
        options={{ title: 'Home', tabBarIcon: ({ color, size }) => <Ionicons name="home-outline" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="tasks"
        options={{
          title: 'Tasks',
          tabBarIcon: ({ color, size }) => <Ionicons name="checkbox-outline" color={color} size={size} />,
          tabBarBadge: b.corrections.length > 0 ? b.corrections.length : undefined,
        }}
      />
      <Tabs.Screen
        name="forms"
        options={{ title: 'Forms', tabBarIcon: ({ color, size }) => <Ionicons name="document-text-outline" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="insights"
        options={{ title: 'Insights', tabBarIcon: ({ color, size }) => <Ionicons name="stats-chart-outline" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="sync"
        options={{
          title: 'Sync',
          tabBarIcon: ({ color, size }) => <Ionicons name="sync-outline" color={color} size={size} />,
          tabBarBadge: toSend > 0 ? toSend : undefined,
          tabBarBadgeStyle: { backgroundColor: colors.warning },
        }}
      />
    </Tabs>
  );
}
