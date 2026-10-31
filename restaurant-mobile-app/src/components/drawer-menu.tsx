import { Ionicons } from '@expo/vector-icons';
import { router, useGlobalSearchParams, usePathname } from 'expo-router';
import { DrawerContentScrollView, type DrawerContentComponentProps } from 'expo-router/drawer';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAccess } from '@/hooks/use-page';
import { initials } from '@/lib/format';
import { visibleNavigation, type NavItem } from '@/lib/navigation';
import { colors } from '@/lib/theme';
import { useAuth, useBranch } from '@/store/auth';

/** The drawer: who is signed in where, then the sections this member may use, as on the web. */
export function DrawerMenu({ navigation }: DrawerContentComponentProps) {
  const insets = useSafeAreaInsets();
  const user = useAuth((s) => s.me?.user);
  const organization = useAuth((s) => s.me?.organization.name);
  const mode = useAuth((s) => s.mode);
  const branch = useBranch();
  const shared = useAccess((s) => s.shared);
  const pathname = usePathname();
  const { section } = useGlobalSearchParams<{ section?: string }>();
  const groups = visibleNavigation(shared, mode);

  const active = (item: NavItem) => (item.tab ? pathname === item.tab : item.screen ? pathname === item.screen : pathname.startsWith('/p') && section === item.path);

  const open = (item: NavItem) => {
    navigation.closeDrawer();
    if (item.tab) router.navigate(item.tab as never);
    else if (item.screen) router.push(item.screen as never);
    else router.navigate({ pathname: '/p/[section]', params: { section: item.path } });
  };

  return (
    <View className="flex-1 bg-white">
      <View className="bg-brand-600 px-5 pb-5" style={{ paddingTop: insets.top + 16 }}>
        <View className="flex-row items-center gap-3">
          <View className="h-12 w-12 items-center justify-center rounded-full border border-white/25 bg-white/15">
            <Text className="text-base font-bold text-white">{user ? initials(user.name) : '?'}</Text>
          </View>
          <View className="flex-1">
            <Text className="text-base font-bold text-white" numberOfLines={1}>
              {user?.name ?? 'Signed in'}
            </Text>
            <Text className="text-sm text-brand-100" numberOfLines={1}>
              {[organization, branch && branch.name !== organization ? branch.name : null].filter(Boolean).join(' · ')}
            </Text>
          </View>
        </View>
      </View>

      <DrawerContentScrollView contentContainerStyle={{ paddingTop: 8, paddingBottom: 16 }}>
        {groups.map((group) => (
          <View key={group.title} className="px-3 pb-2">
            <Text className="px-3 pb-1 pt-3 text-xs font-bold uppercase tracking-wider text-slate-500">{group.title}</Text>
            {group.items.map((item) => {
              const on = active(item);
              return (
                <Pressable
                  key={item.title}
                  accessibilityRole="button"
                  accessibilityState={{ selected: on }}
                  onPress={() => open(item)}
                  className={`min-h-12 flex-row items-center gap-3 px-3 ${on ? 'bg-brand-50' : 'active:bg-slate-100'}`}>
                  <Ionicons name={item.icon} size={21} color={on ? colors.brand[700] : colors.muted} />
                  <Text className={`flex-1 text-base ${on ? 'font-bold text-brand-800' : 'font-medium text-slate-800'}`}>{item.title}</Text>
                </Pressable>
              );
            })}
          </View>
        ))}
      </DrawerContentScrollView>

      <View className="border-t border-slate-100 px-3 pt-2" style={{ paddingBottom: insets.bottom + 8 }}>
        <Pressable
          accessibilityRole="button"
          onPress={() => {
            navigation.closeDrawer();
            router.push('/account');
          }}
          className="min-h-12 flex-row items-center gap-3 px-3 active:bg-slate-100">
          <Ionicons name="person-circle-outline" size={21} color={colors.muted} />
          <Text className="flex-1 text-base font-medium text-slate-800">Account</Text>
          <Text className="text-xs font-semibold uppercase text-slate-400">{mode}</Text>
        </Pressable>
      </View>
    </View>
  );
}
