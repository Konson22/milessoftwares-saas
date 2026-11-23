import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { Input } from '@/components/entry-form';
import { ScreenHeader } from '@/components/screen-header';
import { taskStatus } from '@/components/task-status';
import { Button } from '@/components/ui/button';
import { Badge, Banner, Card, Row, SectionTitle } from '@/components/ui/misc';
import { choosePhoto, takePhoto } from '@/logbook/capture';
import { operations } from '@/logbook/transport';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useWork } from '@/store/work';

/**
 * One task. Starting and completing work are kept on the phone and sent
 * when connected, with the time they were done. Evidence is a photo sent
 * online; a task that asks for evidence is completed once it has some.
 */
export default function TaskScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const fileMaxKb = useLogbook((s) => s.kit?.limits.fileMaxKb ?? 10240);
  const { tasks, actions, start, complete, dismiss, refresh } = useWork();
  const task = tasks.find((t) => t.id === id);
  const [notes, setNotes] = useState('');
  const [uploading, setUploading] = useState(false);

  if (!task) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title="Task" icon="arrow-back" onIcon={() => router.back()} />
        <View className="p-4">
          <Banner tone="info">This task is not on the phone. Pull down on Tasks to fetch it.</Banner>
        </View>
      </View>
    );
  }

  const [label, tone] = taskStatus(task.status, task.overdue);
  const refused = actions.find((a) => a.taskId === task.id && a.refused !== null);
  const evidence = task.files.filter((f) => f.kind === 'evidence');
  const needsEvidence = task.evidenceRequired && evidence.length === 0;

  const addEvidence = async (fromCamera: boolean) => {
    const file = fromCamera ? await takePhoto('evidence', fileMaxKb) : await choosePhoto('evidence', fileMaxKb);
    if (!file) return;
    setUploading(true);
    try {
      await operations.evidence(task.id, { uri: file.uri, name: file.name, mime: file.mime ?? 'image/jpeg' });
      toast('Evidence added.');
      await refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : 'The photo was not sent.', 'error');
    } finally {
      setUploading(false);
    }
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={task.reference} subtitle={task.title} icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
        {refused && (
          <Banner tone="error">
            {`Not sent: ${refused.refused!.message}`}
          </Banner>
        )}
        {refused && <Button title="Dismiss" variant="outline" onPress={() => void dismiss(refused.id)} />}
        {(task.local === 'completing' || task.local === 'starting') && <Banner tone="info">Saved on the phone. It is sent when you are online.</Banner>}
        {task.status === 'returned' && task.completion.returnComment && <Banner tone="warning">{`Returned: ${task.completion.returnComment}`}</Banner>}

        <Card className="gap-2">
          <View className="flex-row items-center gap-2">
            <Text className="flex-1 text-lg font-semibold text-slate-900">{task.title}</Text>
            <Badge label={label} tone={tone} />
          </View>
          {task.description ? <Text className="text-base text-slate-700">{task.description}</Text> : null}
          <Row label="Due" value={task.dueAt ? when(task.dueAt) : 'No due date'} tone={task.overdue ? 'text-red-700' : ''} />
          {task.startsAt ? <Row label="Starts" value={when(task.startsAt)} /> : null}
          <Row label="Given by" value={task.creator} />
          {task.resource ? <Row label="Resource" value={task.resource} /> : null}
          {task.sheet ? <Row label="Log sheet" value={`${task.sheet.code} · ${task.sheet.name}`} /> : null}
          {task.logbook ? <Row label="Logbook" value={task.logbook} /> : null}
          {task.priority === 'high' || task.priority === 'urgent' ? <Row label="Priority" value={task.priority === 'urgent' ? 'Urgent' : 'High'} strong /> : null}
          {task.verificationRequired ? <Text className="text-sm text-slate-500">Someone else verifies the work.</Text> : null}
        </Card>

        {task.can.start && <Button title="Start" variant="outline" size="lg" icon="play-outline" onPress={() => void start(task)} />}

        {task.can.complete && (
          <View className="gap-3">
            <SectionTitle>Evidence</SectionTitle>
            {evidence.map((f) => (
              <Text key={f.id} className="text-sm text-slate-700">
                {f.name}
              </Text>
            ))}
            {needsEvidence && <Text className="text-sm text-amber-800">This task asks for evidence before it is completed.</Text>}
            {reachable ? (
              <View className="flex-row gap-2">
                <Button title="Take photo" variant="outline" icon="camera-outline" loading={uploading} onPress={() => void addEvidence(true)} className="flex-1" />
                <Button title="Choose" variant="outline" icon="image-outline" disabled={uploading} onPress={() => void addEvidence(false)} className="flex-1" />
              </View>
            ) : (
              <Text className="text-sm text-slate-500">Photos are sent online: connect to add evidence.</Text>
            )}

            <SectionTitle>Complete</SectionTitle>
            <Input value={notes} onChange={setNotes} multiline placeholder="What was done (optional)" />
            <Button
              title={task.verificationRequired ? 'Send for verification' : 'Mark complete'}
              size="lg"
              icon="checkmark-circle-outline"
              disabled={needsEvidence}
              onPress={() => {
                void complete(task, notes.trim() || null).then(() => {
                  toast(reachable ? 'Sending…' : 'Saved. Sent when you are online.');
                  router.back();
                });
              }}
            />
          </View>
        )}

        {task.completion.notes && !task.can.complete ? (
          <Card className="gap-1">
            <Text className="text-sm font-medium text-slate-500">What was done</Text>
            <Text className="text-base text-slate-900">{task.completion.notes}</Text>
            {task.completedAt ? <Text className="text-sm text-slate-500">{when(task.completedAt)}</Text> : null}
          </Card>
        ) : null}
      </ScrollView>
    </View>
  );
}
