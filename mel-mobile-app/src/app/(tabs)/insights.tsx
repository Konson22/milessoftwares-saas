import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { RefreshControl, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { BarChart, PieChart } from 'react-native-gifted-charts';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { today } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { Card, EmptyState, Hero, HeroStat, SectionTitle } from '@/components/ui/misc';
import { insightsOf, OUTCOMES, type FormInsight } from '@/collect/insights';
import { colors } from '@/lib/theme';
import { useCollect } from '@/store/collect';

/*
 * The collector's own numbers: what they sent over the last 30 days, how
 * its review stands, and each task against its target. Every chart also
 * shows its figures as text, and colour is never the only cue.
 */

function dayLabel(date: string, long = false): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(undefined, long ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short' });
}

export default function InsightsScreen() {
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const syncing = useCollect((s) => s.syncing);
  const refresh = useCollect((s) => s.refresh);
  const [picked, setPicked] = useState<number | null>(null);

  const now = today();
  const i = useMemo(() => insightsOf(kit, items, now), [kit, items, now]);

  // Card content width: the screen less 16 px padding each side and the card's own 16 px.
  const chartWidth = width - 32 - 32 - 28;
  const slot = chartWidth / i.activity.length;
  const barWidth = Math.max(3, Math.floor(slot * 0.62));
  const spacing = Math.max(1, slot - barWidth);
  const maxDay = Math.max(...i.activity.map((a) => a.count), 0);
  const day = picked !== null ? i.activity[picked] : null;

  const bars = i.activity.map((a, index) => ({
    value: a.count,
    label: index % 7 === 1 || index === i.activity.length - 1 ? dayLabel(a.date) : '',
    frontColor: picked === index ? colors.accentDark : a.date === now ? colors.brand[500] : colors.brand[700],
  }));

  const mix = OUTCOMES.map((o) => ({ ...o, value: i.totals[o.key] }));
  const noData = i.totals.received === 0 && i.activityTotal === 0;

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Insights" subtitle="Your collection at a glance" />
      <ScrollView
        contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}>
        {noData ? (
          <EmptyState icon="stats-chart-outline" title="Nothing to show yet" message="Your numbers appear here once you have sent your first form. Pull down to refresh." />
        ) : (
          <>
            <Hero>
              <Text className="text-xs font-semibold text-brand-100">Last 30 days</Text>
              <Text className="mt-1 text-4xl font-bold text-white">{i.activityTotal}</Text>
              <Text className="text-sm text-brand-100">forms sent</Text>
              <View className="mt-4 flex-row gap-2">
                <HeroStat label="Active days" value={String(i.activeDays)} />
                <HeroStat label="Streak" value={`${i.streak} day${i.streak === 1 ? '' : 's'}`} />
                <HeroStat label="Accepted" value={i.acceptanceRate === null ? '—' : `${i.acceptanceRate}%`} align="right" />
              </View>
            </Hero>

            <View>
              <SectionTitle>Forms sent per day</SectionTitle>
              <Card>
                <View className="mb-3 min-h-10">
                  {day ? (
                    <>
                      <Text className="text-xs text-slate-500">{dayLabel(day.date, true)}</Text>
                      <Text className="text-lg font-bold text-slate-900">
                        {day.count} form{day.count === 1 ? '' : 's'}
                      </Text>
                    </>
                  ) : (
                    <>
                      <Text className="text-xs text-slate-500">Busiest day</Text>
                      <Text className="text-lg font-bold text-slate-900">
                        {maxDay} form{maxDay === 1 ? '' : 's'}
                        <Text className="text-sm font-normal text-slate-500"> · tap a bar for its day</Text>
                      </Text>
                    </>
                  )}
                </View>
                <BarChart
                  data={bars}
                  width={chartWidth}
                  height={150}
                  barWidth={barWidth}
                  spacing={spacing}
                  initialSpacing={spacing / 2}
                  endSpacing={0}
                  barBorderTopLeftRadius={Math.min(4, barWidth / 2)}
                  barBorderTopRightRadius={Math.min(4, barWidth / 2)}
                  noOfSections={Math.min(4, Math.max(1, maxDay))}
                  maxValue={Math.max(4, maxDay)}
                  yAxisThickness={0}
                  yAxisLabelWidth={28}
                  yAxisTextStyle={{ color: colors.faint, fontSize: 10 }}
                  xAxisColor={colors.line}
                  xAxisLabelTextStyle={{ color: colors.muted, fontSize: 10, width: 48, marginLeft: -18 }}
                  labelWidth={48}
                  rulesColor={colors.line}
                  rulesType="solid"
                  disableScroll
                  isAnimated
                  animationDuration={500}
                  onPress={(_: unknown, index: number) => setPicked(picked === index ? null : index)}
                />
                {!i.fromServer && <Text className="mt-2 text-xs text-slate-500">From the records on this phone. Connect to see everything you sent.</Text>}
              </Card>
            </View>

            <View>
              <SectionTitle>Review of what you sent</SectionTitle>
              <Card>
                <View className="flex-row items-center gap-4">
                  <PieChart
                    data={mix.filter((m) => m.value > 0).map((m) => ({ value: m.value, color: m.color }))}
                    donut
                    radius={64}
                    innerRadius={44}
                    strokeColor={colors.white}
                    strokeWidth={2}
                    centerLabelComponent={() => (
                      <View className="items-center">
                        <Text className="text-xl font-bold text-slate-900">{i.totals.received}</Text>
                        <Text className="text-xs text-slate-500">sent</Text>
                      </View>
                    )}
                  />
                  <View className="flex-1 gap-2">
                    {mix.map((m) => (
                      <View key={m.key} className="flex-row items-center gap-2" accessible accessibilityLabel={`${m.label}: ${m.value}`}>
                        <View className="h-3 w-3 rounded-sm" style={{ backgroundColor: m.color }} />
                        <Text className="flex-1 text-sm text-slate-600">{m.label}</Text>
                        <Text className="text-sm font-bold text-slate-900">{m.value}</Text>
                      </View>
                    ))}
                  </View>
                </View>
              </Card>
            </View>

            {i.forms.length > 0 && (
              <View>
                <SectionTitle>By task</SectionTitle>
                <View className="gap-2">
                  {i.forms.map((f) => (
                    <TaskProgress key={f.assignmentId} form={f} />
                  ))}
                </View>
              </View>
            )}
          </>
        )}
      </ScrollView>
    </View>
  );
}

/** One task: its target (when set) and its records by review outcome. */
function TaskProgress({ form }: { form: FormInsight }) {
  const percent = form.expected ? Math.min(100, Math.round((form.done / form.expected) * 100)) : null;
  const met = form.expected !== null && form.done >= form.expected;

  return (
    <Card>
      <View className="flex-row items-start gap-2">
        <View className="flex-1">
          <Text className="text-base font-semibold text-slate-800" numberOfLines={1}>
            {form.formName}
          </Text>
          {form.unit && (
            <Text className="text-xs text-slate-500" numberOfLines={1}>
              {form.unit}
            </Text>
          )}
        </View>
        {met ? (
          <View className="flex-row items-center gap-1">
            <Ionicons name="checkmark-circle" size={16} color={colors.successDark} />
            <Text className="text-xs font-bold text-emerald-800">Target met</Text>
          </View>
        ) : (
          <Text className="text-sm font-bold text-slate-900">{form.expected ? `${form.done} / ${form.expected}` : `${form.received} sent`}</Text>
        )}
      </View>

      {percent !== null && (
        <View className="mt-3" accessible accessibilityRole="progressbar" accessibilityLabel={`${form.done} of ${form.expected} expected`} accessibilityValue={{ min: 0, max: 100, now: percent }}>
          <View className="h-2.5 overflow-hidden rounded-full bg-slate-100">
            <View className="h-full rounded-full" style={{ width: `${percent}%`, backgroundColor: met ? colors.successDark : colors.brand[500] }} />
          </View>
          <Text className="mt-1 text-xs text-slate-500">{met ? `${form.done} sent, ${form.expected} expected` : `${form.expected! - form.done} more to reach your target`}</Text>
        </View>
      )}

      {form.received > 0 && (
        <View className="mt-3 gap-1.5">
          <View className="h-2 flex-row gap-0.5 overflow-hidden rounded-full">
            {OUTCOMES.filter((o) => form.counts[o.key] > 0).map((o) => (
              <View key={o.key} style={{ flex: form.counts[o.key], backgroundColor: o.color }} />
            ))}
          </View>
          <Text className="text-xs text-slate-500">
            {OUTCOMES.filter((o) => form.counts[o.key] > 0)
              .map((o) => `${form.counts[o.key]} ${o.label.toLowerCase()}`)
              .join(' · ')}
          </Text>
        </View>
      )}
    </Card>
  );
}
