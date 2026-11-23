import { Ionicons } from '@expo/vector-icons';
import { Drawer, type DrawerContentComponentProps } from 'expo-router/drawer';
import { useEffect } from 'react';
import { BackHandler, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { IconName } from '@/components/ui/button';
import { CountBadge, IconTile } from '@/components/ui/misc';
import { BRAND } from '@/lib/brand';
import { colors, tiles, type TileColor } from '@/lib/theme';
import { buckets } from '@/logbook/sync';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { useWork } from '@/store/work';

/**
 * Side menu, in groups with a coloured tile per place. Home opens the tabs (daily work);
 * reviews, sending, notifications and account live here. The More tab opens it too.
 */
export default function DrawerLayout() {
  return (
    <Drawer
      initialRouteName="(tabs)"
      drawerContent={(props) => <DrawerMenu {...props} />}
      screenOptions={{
        headerShown: false,
        drawerType: 'front',
        drawerStyle: { backgroundColor: colors.canvas, width: 312 },
        overlayColor: 'rgba(17, 24, 51, 0.45)',
        sceneStyle: { backgroundColor: colors.canvas },
      }}>
      <Drawer.Screen name="(tabs)" options={{ title: 'Home' }} />
      <Drawer.Screen name="reviews" options={{ title: 'Reviews' }} />
      <Drawer.Screen name="sync" options={{ title: 'Sync' }} />
      <Drawer.Screen name="notifications" options={{ title: 'Notifications' }} />
      <Drawer.Screen name="account" options={{ title: 'Account' }} />
    </Drawer>
  );
}

/** Back from a menu screen returns to the tabs. A screen pushed over the menu (an entry, a task) keeps its own back. */
function DrawerBack({ navigation, state }: DrawerContentComponentProps) {
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (!navigation.isFocused()) return false;
      const name = state.routes[state.index]?.name;
      if (name && name !== '(tabs)') {
        navigation.navigate('(tabs)');
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [navigation, state]);
  return null;
}

type MenuRow = { route: string; title: string; icon: IconName; color: TileColor; count?: number; hidden?: boolean; tab?: string };

function DrawerMenu(props: DrawerContentComponentProps) {
  const me = useAuth((s) => s.me);
  const workspace = useAuth((s) => s.workspace);
  const reachable = useAuth((s) => s.reachable);
  const items = useLogbook((s) => s.items);
  const actions = useWork((s) => s.actions);
  const unread = useWork((s) => s.unread);
  const toReview = useWork((s) => s.toReview);
  const tasks = useWork((s) => s.tasks);
  const insets = useSafeAreaInsets();
  const b = buckets(items);
  const waiting = b.waiting.length + actions.filter((a) => a.refused === null).length;
  const toDo = tasks.filter((t) => ['pending', 'in_progress', 'returned'].includes(t.status)).length;
  const current = props.state.routes[props.state.index]?.name;

  const groups: { title: string; rows: MenuRow[] }[] = [
    {
      title: 'Daily work',
      rows: [
        { route: '(tabs)', tab: 'home', title: 'Home', icon: 'home', color: 'blue' },
        { route: '(tabs)', tab: 'new', title: 'New entry', icon: 'add-circle', color: 'sky', hidden: me?.can.write === false },
        { route: '(tabs)', tab: 'entries', title: 'My entries', icon: 'document-text', color: 'teal', count: b.returned.length + b.refused.length },
        { route: '(tabs)', tab: 'tasks', title: 'Tasks', icon: 'checkbox', color: 'green', count: toDo },
      ],
    },
    {
      title: 'Records',
      rows: [
        { route: 'reviews', title: 'Reviews', icon: 'checkmark-done', color: 'violet', count: toReview ?? 0, hidden: !me?.can.review },
        { route: 'sync', title: 'Sync', icon: 'sync', color: 'orange', count: waiting },
        { route: 'notifications', title: 'Notifications', icon: 'notifications', color: 'amber', count: unread },
      ],
    },
    { title: 'You', rows: [{ route: 'account', title: 'Account', icon: 'person', color: 'slate' }] },
  ];

  const go = (row: MenuRow) => {
    if (row.tab) props.navigation.navigate('(tabs)', { screen: row.tab });
    else props.navigation.navigate(row.route);
    props.navigation.closeDrawer();
  };

  return (
    <View className="flex-1 bg-canvas">
      <DrawerBack {...props} />
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Account"
        onPress={() => props.navigation.navigate('account')}
        className="bg-brand-logo px-5 pb-5 active:bg-brand-900"
        style={{ paddingTop: insets.top + 20 }}>
        <View className="flex-row items-center gap-3">
          <View className="h-12 w-12 items-center justify-center rounded-full bg-accent">
            <Text className="text-lg font-bold text-brand-950">{(me?.user.name ?? 'L').trim().charAt(0).toUpperCase()}</Text>
          </View>
          <View className="flex-1">
            <Text className="text-lg font-bold text-white" numberOfLines={1}>
              {me?.user.name ?? 'Logbook'}
            </Text>
            <Text className="text-sm text-brand-200" numberOfLines={1}>
              {me?.user.email ?? workspace ?? ''}
            </Text>
          </View>
        </View>
        {me?.organization.name ? (
          <View className="mt-4 flex-row items-center gap-2 rounded-xl bg-brand-800 px-3 py-2.5">
            <Ionicons name="business" size={16} color={colors.brand[200]} />
            <Text className="flex-1 text-sm font-semibold text-white" numberOfLines={1}>
              {me.organization.name}
            </Text>
            <View className={`h-2 w-2 rounded-full ${reachable ? 'bg-emerald-400' : 'bg-amber-400'}`} />
            <Text className="text-xs text-brand-200">{reachable ? 'Online' : 'Offline'}</Text>
          </View>
        ) : null}
      </Pressable>
      <ScrollView contentContainerStyle={{ padding: 12, gap: 16, paddingBottom: insets.bottom + 16 }}>
        {groups.map((g) => {
          const rows = g.rows.filter((r) => !r.hidden);
          if (!rows.length) return null;
          return (
            <View key={g.title}>
              <Text className="mb-1.5 px-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{g.title}</Text>
              <View className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                {rows.map((row, i) => {
                  const active = !row.tab && current === row.route;
                  return (
                    <View key={row.title}>
                      {i > 0 && <View className="ml-14 h-px bg-slate-100" />}
                      <Pressable
                        accessibilityRole="button"
                        accessibilityState={{ selected: active }}
                        onPress={() => go(row)}
                        className={`min-h-14 flex-row items-center gap-3 px-3 py-2.5 ${active ? 'bg-brand-50' : 'active:bg-slate-50'}`}>
                        <IconTile icon={row.icon} color={row.color} size={34} solid />
                        <Text className={`flex-1 text-[15px] font-semibold ${active ? 'text-brand-700' : 'text-slate-800'}`} numberOfLines={1}>
                          {row.title}
                        </Text>
                        <CountBadge count={row.count ?? 0} color={row.route === 'sync' ? tiles.amber.solid : undefined} />
                        <Ionicons name="chevron-forward" size={16} color={colors.faint} />
                      </Pressable>
                    </View>
                  );
                })}
              </View>
            </View>
          );
        })}
        <Text className="px-2 text-center text-xs text-slate-400">
          {BRAND.name} · {BRAND.maker}
        </Text>
      </ScrollView>
    </View>
  );
}
