import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { FlatList, RefreshControl, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { SearchBox, Segments, today } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Badge, EmptyState } from '@/components/ui/misc';
import { filterTasks, purposeLabel, TASK_STATE_LABEL, taskRows, type TaskFilter, type TaskRow, type TaskState } from '@/collect/overview';
import { useStartRecord } from '@/hooks/use-start';
import { useCollect } from '@/store/collect';

const STATE_TONE: Record<TaskState, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
  open: 'green',
  not_started: 'neutral',
  closing: 'amber',
  ended: 'neutral',
  not_downloaded: 'red',
};

/**
 * Assigned field work. A task is an assignment from Tatook M&E (a form, for
 * a programme unit, between two dates): the phone keeps no task list of
 * its own.
 */
export default function TasksScreen() {
  const insets = useSafeAreaInsets();
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const downloaded = useCollect((s) => s.downloaded);
  const syncing = useCollect((s) => s.syncing);
  const refresh = useCollect((s) => s.refresh);
  const [filter, setFilter] = useState<TaskFilter>('all');
  const [query, setQuery] = useState('');

  const rows = useMemo(() => taskRows(kit, items, new Set(downloaded), today()), [kit, items, downloaded]);
  const shown = filterTasks(rows, filter, query);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Tasks" subtitle={`${rows.length} assigned`} />
      <FlatList
        data={shown}
        keyExtractor={(r) => r.assignment.id}
        contentContainerStyle={{ padding: 16, gap: 12, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}
        keyboardShouldPersistTaps="handled"
        ListHeaderComponent={
          <View className="gap-3">
            <SearchBox value={query} onChange={setQuery} placeholder="Search tasks, places, programmes" />
            <Segments
              value={filter}
              onChange={setFilter}
              options={[
                { value: 'all', label: 'All' },
                { value: 'open', label: 'Open' },
                { value: 'due_soon', label: 'Due this week' },
                { value: 'attention', label: 'Needs attention' },
              ]}
            />
          </View>
        }
        ListEmptyComponent={
          <EmptyState
            icon="checkbox-outline"
            title={rows.length ? 'No task matches' : kit ? 'No tasks assigned' : 'No tasks on this phone yet'}
            message={rows.length ? 'Try another filter or search.' : kit ? 'Your coordinator assigns field work to you in Tatook M&E.' : 'Connect to the internet once to download your tasks.'}
          />
        }
        renderItem={({ item }) => <TaskCard row={item} />}
      />
    </View>
  );
}

function TaskCard({ row }: { row: TaskRow }) {
  const { begin, busy } = useStartRecord();
  const a = row.assignment;
  const canStart = row.state === 'open' || (row.state === 'closing' && a.canStart);
  const due =
    row.daysLeft === null ? null : row.daysLeft < 0 ? `Ended ${a.endsOn}` : row.daysLeft === 0 ? 'Due today' : `Due ${a.endsOn} (${row.daysLeft} ${row.daysLeft === 1 ? 'day' : 'days'})`;

  return (
    <View className="gap-2 border border-slate-200 bg-white p-4">
      <View className="flex-row items-start gap-2">
        <View className="flex-1 gap-0.5">
          <Text className="text-xs font-bold uppercase tracking-wider text-brand-700">{purposeLabel(a.form.purpose)}</Text>
          <Text className="text-base font-semibold text-slate-900">{a.form.name}</Text>
        </View>
        <Badge label={TASK_STATE_LABEL[row.state]} tone={STATE_TONE[row.state]} />
      </View>

      <View className="gap-0.5">
        {a.form.programme && <Meta label="Programme" value={a.form.programme} />}
        {a.unit && <Meta label="Where" value={a.unit.label} />}
        {due && <Meta label="When" value={a.startsOn && row.state === 'not_started' ? `Starts ${a.startsOn}` : due} />}
        <Meta label="Form" value={a.version ? `Version ${a.version.number}${row.state === 'not_downloaded' ? ' (not on the phone yet)' : ''}` : 'Not published'} />
      </View>

      <View className="flex-row flex-wrap gap-1.5">
        {row.drafts > 0 && <Badge label={`${row.drafts} ${row.drafts === 1 ? 'draft' : 'drafts'}`} tone="amber" />}
        {row.sent > 0 && <Badge label={`${row.sent} uploaded`} tone="blue" />}
        {row.approved > 0 && <Badge label={`${row.approved} approved`} tone="green" />}
        {row.corrections > 0 && <Badge label={`${row.corrections} to correct`} tone="red" />}
        {a.form.containsPersonalData && <Badge label="Personal data" tone="purple" />}
      </View>

      {a.form.description && <Text className="text-sm text-slate-600">{a.form.description}</Text>}

      <View className="flex-row gap-2 pt-1">
        {canStart && <Button title="New record" icon="add" size="lg" className="flex-1" loading={busy === a.id} onPress={() => void begin(a)} />}
        {(row.drafts > 0 || row.corrections > 0 || row.sent > 0) && (
          <Button title="Records" variant="outline" size="lg" className="flex-1" onPress={() => router.push({ pathname: '/forms', params: { form: a.form.id } })} />
        )}
      </View>
      {a.status === 'closing' && a.closesAt && <Text className="text-xs text-amber-800">Closing: records finished before then are accepted until {new Date(a.closesAt).toLocaleDateString()}.</Text>}
    </View>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <Text className="text-sm text-slate-700">
      <Text className="text-slate-500">{label}: </Text>
      {value}
    </Text>
  );
}
