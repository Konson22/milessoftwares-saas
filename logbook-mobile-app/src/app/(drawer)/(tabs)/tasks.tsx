import { router, useFocusEffect } from 'expo-router';
import { useCallback } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { taskStatus } from '@/components/task-status';
import { Badge, Banner, EmptyState, ListGroup, ListItem } from '@/components/ui/misc';
import type { TileColor } from '@/lib/theme';
import type { overlay } from '@/logbook/work';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useWork } from '@/store/work';

type Task = ReturnType<typeof overlay>[number];

/** The person's tasks and the inspections due today; readable offline, work sent when connected. */
export default function TasksScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const { tasks, due, loading, error, lastAt, refresh } = useWork();
  const sheets = useLogbook((s) => s.kit?.sheets ?? []);

  useFocusEffect(
    useCallback(() => {
      void refresh();
    }, [refresh]),
  );

  const open = tasks.filter((t) => ['pending', 'in_progress', 'returned'].includes(t.status));
  const rest = tasks.filter((t) => !open.includes(t));

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="Tasks" subtitle={`${open.length} to do${lastAt ? ` · updated ${when(lastAt)}` : ''}`} />
      <ScrollView
        contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void refresh()} />}>
        {!reachable && <Banner tone="info">Offline: these are your tasks as last fetched. Work you start or complete is sent when you are back online.</Banner>}
        {error && reachable && <Banner tone="error">{error}</Banner>}

        {due.length > 0 && (
          <ListGroup title="Due today">
            {due.map((o) => {
              const sheet = sheets.find((s) => s.id === o.sheet.id);
              return (
                <ListItem
                  key={o.id}
                  icon="time"
                  color={o.state === 'overdue' ? 'red' : o.state === 'due' ? 'amber' : 'slate'}
                  title={`${o.sheet.code} · ${o.sheet.name}`}
                  detail={`${o.logbook} · due ${when(o.dueAt)}`}
                  a11y={`${o.sheet.name}, due ${when(o.dueAt)}`}
                  chevron={false}
                  right={<Badge label={o.state === 'overdue' ? 'Overdue' : o.state === 'due' ? 'Due now' : 'Later'} tone={o.state === 'overdue' ? 'red' : o.state === 'due' ? 'blue' : 'neutral'} />}
                  onPress={
                    sheet
                      ? () =>
                          useLogbook
                            .getState()
                            .start(sheet, { occurrenceId: o.id })
                            .then((item) => router.push({ pathname: '/entry/[id]', params: { id: item.id } }))
                            .catch((e: unknown) => toast(e instanceof Error ? e.message : 'Could not open the sheet.', 'error'))
                      : undefined
                  }
                />
              );
            })}
          </ListGroup>
        )}

        {open.length > 0 && <ListGroup title="To do">{open.map((t) => <TaskRow key={t.id} task={t} />)}</ListGroup>}
        {rest.length > 0 && <ListGroup title="Recently">{rest.map((t) => <TaskRow key={t.id} task={t} />)}</ListGroup>}
        {tasks.length === 0 && due.length === 0 && !loading && <EmptyState icon="checkbox-outline" title="Nothing to do" message="Tasks given to you and scheduled inspections appear here." />}
      </ScrollView>
    </View>
  );
}

const TASK_COLOR: Record<string, TileColor> = { red: 'red', amber: 'amber', green: 'green', blue: 'blue', neutral: 'slate' };

function TaskRow({ task: t }: { task: Task }) {
  const [label, tone] = taskStatus(t.status, t.overdue);
  return (
    <ListItem
      icon={t.status === 'completed' ? 'checkmark-circle' : t.status === 'returned' ? 'arrow-undo' : 'clipboard'}
      color={TASK_COLOR[tone] ?? 'blue'}
      title={t.title}
      detail={[t.reference, t.resource, t.sheet?.code, t.dueAt ? `due ${when(t.dueAt)}` : null].filter(Boolean).join(' · ')}
      onPress={() => router.push({ pathname: '/task/[id]', params: { id: t.id } })}
      right={<Badge label={label} tone={tone} />}>
      {t.local === 'completing' || t.local === 'starting' ? <Text className="text-xs font-medium text-amber-700">Waiting to send</Text> : null}
      {t.local === 'refused' ? <Text className="text-xs font-medium text-red-700">{t.localMessage}</Text> : null}
    </ListItem>
  );
}
