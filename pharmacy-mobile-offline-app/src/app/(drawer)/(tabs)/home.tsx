import { Ionicons } from '@expo/vector-icons';
import { router, type Href } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';

import { StatTile } from '@/components/pages/kit';
import { STATUS_BADGE } from '@/components/sale-status';
import { Button, type IconName } from '@/components/ui/button';
import { Badge, Banner, Card, Hero, HeroStat, SectionTitle } from '@/components/ui/misc';
import { useFocusedQuery } from '@/hooks/use-async';
import { api } from '@/lib/api';
import { fullDate, isoDate, money, time } from '@/lib/format';
import { sectionOf } from '@/lib/navigation';
import { getPage } from '@/lib/pages';
import { colors } from '@/lib/theme';
import { useAuth, useOwner } from '@/store/auth';
import { useCart } from '@/store/cart';

/** The dashboard's figures (src/local/db/pages.ts home), each null where this member may not see it. */
interface Dashboard {
  stock: { low: number; expiring: number; expired: number } | null;
  awaitingVerification: number | null;
  toDispense: number | null;
  billsDue: number | null;
  expiringDays: number;
}

const RECENT = 5;
const section = (page: string) => ({ pathname: '/p/[section]' as const, params: { section: sectionOf(page) } });

function greeting(now = new Date()) {
  const hour = now.getHours();
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * Home: today at this counter. The day's takings, the shift, shortcuts to
 * the everyday jobs, what needs attention and the latest sales.
 */
export default function HomeScreen() {
  const owner = useOwner();
  const me = useAuth((s) => s.me);
  const till = useAuth((s) => s.till);
  const counter = useAuth((s) => s.counter);
  const held = useCart((s) => s.held.filter((h) => h.owner === owner).length);
  const today = isoDate();

  const { data, loading, reload } = useFocusedQuery(async () => {
    const [sales, dashboard] = await Promise.all([api.sales(today), getPage<Dashboard>('').then((p) => p.props)]);
    return { sales, dashboard };
  }, `${owner}${today}`);

  const sales = data?.sales.sales ?? [];
  const kept = sales.filter((s) => s.status === 'completed');
  const total = kept.reduce((sum, s) => sum + s.total_minor, 0);
  const count = kept.length;
  const dashboard = data?.dashboard ?? null;
  const can = counter?.can;

  const actions: { icon: IconName; label: string; href: Href; show?: boolean }[] = [
    { icon: 'cart', label: 'New sale', href: '/sell' },
    { icon: 'barcode-outline', label: 'Scan', href: '/scan' },
    { icon: 'document-text', label: 'Prescriptions', href: '/rx', show: !!can?.view_prescriptions },
    { icon: 'pause-circle', label: held ? `Held (${held})` : 'Held carts', href: '/held' },
    { icon: 'cash', label: 'Shift', href: '/shift' },
    { icon: 'archive', label: 'Receive stock', href: section('receipts/create'), show: !!dashboard?.stock },
  ];

  return (
    <ScrollView
      contentContainerClassName="w-full max-w-3xl self-center gap-4 p-4 pb-8"
      refreshControl={<RefreshControl refreshing={loading && !!data} onRefresh={reload} />}>
      <View>
        <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
          {greeting()}
          {me?.user.name ? `, ${me.user.name.split(' ')[0]}` : ''}
        </Text>
        <Text className="text-base text-slate-500">{fullDate(today)}</Text>
      </View>


      <Hero className="gap-3">
        <Text className="text-sm font-medium text-brand-100">{data?.sales.everyone ? 'Branch sales today' : 'My sales today'}</Text>
        <Text accessibilityLabel={`Sales today ${money(total)}`} className="text-4xl font-bold text-white">
          {money(total)}
        </Text>
        <View className="flex-row gap-2">
          <HeroStat label="Sales" value={String(count)} />
          <HeroStat label="Average" value={count ? money(Math.round(total / count), false) : '—'} />
        </View>
      </Hero>

      {till ? (
        <Card className="flex-row items-center gap-3">
          <View className="h-11 w-11 items-center justify-center bg-accent-50">
            <Ionicons name="lock-open" size={20} color={colors.accentDark} />
          </View>
          <View className="flex-1">
            <Text className="text-base font-semibold text-slate-900">Shift {till.number} open</Text>
            <Text className="text-sm text-slate-500">
              Since {time(till.opened_at)}
              {till.blind ? '' : ` · drawer ${money(till.expected_cash_minor)}`}
            </Text>
          </View>
          <Button title="View" variant="outline" onPress={() => router.navigate('/shift')} />
        </Card>
      ) : counter?.counter.shifts_required ? (
        <Card className="gap-3">
          <Text className="text-base font-semibold text-slate-900">No shift open</Text>
          <Text className="text-sm text-slate-500">This branch sells within a shift. Count the drawer and start yours.</Text>
          <Button title="Start shift" icon="lock-open" onPress={() => router.navigate('/shift')} />
        </Card>
      ) : null}

      <View>
        <SectionTitle>Shortcuts</SectionTitle>
        <View className="flex-row flex-wrap gap-2">
          {actions
            .filter((a) => a.show !== false)
            .map((a) => (
              <Pressable
                key={a.label}
                accessibilityRole="button"
                accessibilityLabel={a.label}
                onPress={() => router.navigate(a.href)}
                className="min-w-[30%] flex-1 items-center gap-2 border border-slate-200 bg-white px-2 py-4 active:bg-brand-50">
                <View className="h-11 w-11 items-center justify-center bg-brand-50">
                  <Ionicons name={a.icon} size={22} color={colors.brand[600]} />
                </View>
                <Text className="text-center text-sm font-semibold text-slate-800" numberOfLines={1}>
                  {a.label}
                </Text>
              </Pressable>
            ))}
        </View>
      </View>

      {dashboard && (
        <View>
          <SectionTitle>Needs attention</SectionTitle>
          <View className="flex-row flex-wrap gap-2">
            {dashboard.stock && (
              <>
                <StatTile
                  icon="trending-down-outline"
                  label="Low on stock"
                  value={String(dashboard.stock.low)}
                  tone={dashboard.stock.low ? 'amber' : 'green'}
                  onPress={() => router.navigate(section('stock'))}
                />
                <StatTile
                  icon="time-outline"
                  label={`Expiring in ${dashboard.expiringDays} days`}
                  value={String(dashboard.stock.expiring)}
                  tone={dashboard.stock.expiring ? 'red' : 'green'}
                  onPress={() => router.navigate(section('stock'))}
                />
                {dashboard.stock.expired > 0 && (
                  <StatTile
                    icon="alert-circle-outline"
                    label="With expired stock"
                    value={String(dashboard.stock.expired)}
                    tone="red"
                    onPress={() => router.navigate(section('batches'))}
                  />
                )}
              </>
            )}
            {dashboard.awaitingVerification !== null && (
              <StatTile
                icon="document-text-outline"
                label="Prescriptions to verify"
                value={String(dashboard.awaitingVerification)}
                tone={dashboard.awaitingVerification ? 'amber' : 'green'}
                onPress={() => router.navigate('/rx')}
              />
            )}
            {dashboard.toDispense !== null && (
              <StatTile
                icon="medkit-outline"
                label="Ready to dispense"
                value={String(dashboard.toDispense)}
                tone={dashboard.toDispense ? 'brand' : 'green'}
                onPress={() => router.navigate('/rx')}
              />
            )}
            {dashboard.billsDue !== null && (
              <StatTile
                icon="wallet-outline"
                label="Bills due"
                value={String(dashboard.billsDue)}
                tone={dashboard.billsDue ? 'amber' : 'green'}
                onPress={() => router.navigate(section('expenses'))}
              />
            )}
          </View>
        </View>
      )}

      <View>
        <SectionTitle
          right={
            sales.length > RECENT ? (
              <Pressable accessibilityRole="link" onPress={() => router.navigate('/sales')} hitSlop={8}>
                <Text className="text-sm font-semibold text-brand-700">See all</Text>
              </Pressable>
            ) : undefined
          }>
          Latest sales
        </SectionTitle>
        {sales.length === 0 ? (
          <Card>
            <Text className="text-base text-slate-500">{loading ? 'Loading…' : 'No sales yet today.'}</Text>
          </Card>
        ) : (
          <Card className="gap-0 p-0">
            {sales.slice(0, RECENT).map((s, i) => {
              const status = STATUS_BADGE[s.status];
              return (
                <Pressable
                  key={s.id}
                  accessibilityRole="button"
                  accessibilityLabel={`${s.number}, ${money(s.total_minor)}, ${status.label}`}
                  onPress={() => router.push({ pathname: '/receipt/[id]', params: { id: s.id } })}
                  className={`flex-row items-center gap-3 px-4 py-3 active:bg-brand-50 ${i ? 'border-t border-slate-100' : ''}`}>
                  <Text className="w-14 text-sm font-semibold text-brand-700">{time(s.sold_at)}</Text>
                  <View className="flex-1">
                    <Text className="text-base font-semibold text-slate-900">{s.number}</Text>
                    <Text className="text-sm text-slate-500" numberOfLines={1}>
                      {s.customer_name ?? 'Walk-in'} · {s.payment_method_label}
                    </Text>
                  </View>
                  <View className="items-end gap-1">
                    <Text className="text-base font-bold text-brand-900">{money(s.total_minor)}</Text>
                    {s.status !== 'completed' && <Badge label={status.label} tone={status.tone} />}
                  </View>
                </Pressable>
              );
            })}
          </Card>
        )}
      </View>
    </ScrollView>
  );
}
