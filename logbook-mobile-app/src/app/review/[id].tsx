import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { Input } from '@/components/entry-form';
import { CalculationCard } from '@/components/recording-controls';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, Card, SectionTitle } from '@/components/ui/misc';
import { ApiError, fileSource } from '@/lib/api/client';
import { reviews, type EntryDetail } from '@/logbook/transport';
import { toast } from '@/store/toast';

/**
 * Reviewing one entry, online. The decision carries the revision the
 * reviewer read: if the author changed it meanwhile, the server refuses
 * and the screen reloads the entry as it is now.
 */
export default function ReviewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const insets = useSafeAreaInsets();
  const [entry, setEntry] = useState<EntryDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState<'reviewed' | 'returned' | null>(null);
  const [commentError, setCommentError] = useState<string | null>(null);

  const load = useCallback(() => {
    reviews
      .entry(id)
      .then((e) => {
        setEntry(e);
        setError(null);
      })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Could not load the entry.'));
  }, [id]);

  useEffect(load, [load]);

  const decide = (decision: 'reviewed' | 'returned') => {
    if (!entry) return;
    const text = comment.trim();
    if (decision === 'returned' && !text) {
      setCommentError('Say what to correct.');
      return;
    }
    setBusy(decision);
    reviews
      .decide(entry.id, { decision, comment: text || null, revision: entry.revision })
      .then(() => {
        toast(decision === 'reviewed' ? 'Marked reviewed.' : 'Returned to the author.');
        router.back();
      })
      .catch((e: unknown) => {
        if (e instanceof ApiError && e.code === 'stale') {
          toast('The entry changed. Here it is as it is now.', 'warning');
          load();
        } else {
          toast(e instanceof Error ? e.message : 'The decision was not saved.', 'error');
        }
      })
      .finally(() => setBusy(null));
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={entry?.reference ?? 'Review'} subtitle={entry ? `${entry.sheet.code} · ${entry.logbook}` : undefined} icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
        {error && <Banner tone="error">{error}</Banner>}
        {entry && (
          <>
            <Card className="gap-1">
              <Text className="text-base font-semibold text-slate-900">{entry.creator}</Text>
              <Text className="text-sm text-slate-500">
                Happened {when(entry.occurredAt)} · submitted {when(entry.submittedAt)} · revision {entry.revision}
              </Text>
              {entry.activityEndedAt && <Text className="text-sm text-slate-500">Activity ended {when(entry.activityEndedAt)}</Text>}
              {entry.shift && <Text className="text-sm text-slate-500">Shift: {entry.shift}</Text>}
              {entry.activityEntry && <Text className="text-sm text-slate-500">Recorded during {entry.activityEntry.label}</Text>}
            </Card>

            {entry.calculation && <CalculationCard calculation={entry.calculation} />}

            {entry.reviews.length > 0 && (
              <View className="gap-2">
                <SectionTitle>Earlier decisions</SectionTitle>
                {entry.reviews.map((r, i) => (
                  <Text key={i} className="text-sm text-slate-700">
                    Revision {r.revision}: {r.decision === 'reviewed' ? 'reviewed' : 'returned'} by {r.reviewer}
                    {r.comment ? `, "${r.comment}"` : ''}
                  </Text>
                ))}
              </View>
            )}

            <SectionTitle>Answers</SectionTitle>
            <Card className="gap-3">
              {entry.answers.map((a) => (
                <View key={a.name} className="gap-1">
                  <Text className="text-sm font-medium text-slate-500">{a.label}</Text>
                  {a.type === 'file' ? (
                    a.files.filter((f) => !f.removed).length === 0 ? (
                      <Text className="text-base text-slate-900">—</Text>
                    ) : (
                      a.files.filter((f) => !f.removed).map((f) => <Attachment key={f.id} file={f} />)
                    )
                  ) : (
                    <View className="gap-1">
                      <Text className={a.outOfRange ? 'text-base font-semibold text-amber-900' : 'text-base text-slate-900'}>
                        {a.text === null ? '—' : `${a.text}${a.unit ? ` ${a.unit}` : ''}`}
                      </Text>
                      {a.outOfRange ? (
                        <Text className="self-start border border-amber-300 bg-amber-50 px-2 py-0.5 text-sm font-medium text-amber-900">
                          {a.outOfRange.direction === 'low' ? 'Below' : 'Above'} the normal range ({a.outOfRange.range})
                        </Text>
                      ) : null}
                    </View>
                  )}
                </View>
              ))}
            </Card>

            {entry.canReview ? (
              <View className="gap-3">
                <SectionTitle>Your decision</SectionTitle>
                <Input
                  value={comment}
                  multiline
                  placeholder="Comment (required to return)"
                  onChange={(t) => {
                    setComment(t);
                    setCommentError(null);
                  }}
                />
                {commentError && <Text className="text-sm text-red-700">{commentError}</Text>}
                <View className="flex-row gap-2">
                  <View className="flex-1">
                    <Button title="Return" icon="arrow-undo-outline" variant="outline" loading={busy === 'returned'} disabled={busy !== null} onPress={() => decide('returned')} />
                  </View>
                  <View className="flex-1">
                    <Button title="Mark reviewed" icon="checkmark" loading={busy === 'reviewed'} disabled={busy !== null} onPress={() => decide('reviewed')} />
                  </View>
                </View>
              </View>
            ) : (
              <Banner tone="info">{entry.status === 'submitted' ? 'You cannot review this entry (your own, or not on your sheets).' : `This entry is ${entry.status}.`}</Banner>
            )}
          </>
        )}
      </ScrollView>
    </View>
  );
}

function Attachment({ file }: { file: { id: string; name: string; size: number; mime: string | null } }) {
  const [source, setSource] = useState<{ uri: string; headers: Record<string, string> } | null>(null);
  const image = file.mime?.startsWith('image/') ?? false;
  useEffect(() => {
    if (image) void fileSource(`v1/attachments/${file.id}`).then(setSource);
  }, [file.id, image]);

  return (
    <View className="gap-1">
      {image && source && <Image source={source} accessibilityLabel={file.name} style={{ width: '100%', aspectRatio: 4 / 3 }} contentFit="contain" />}
      <Text className="text-sm text-slate-700">
        {file.name} · {Math.max(1, Math.round(file.size / 1024))} KB
      </Text>
    </View>
  );
}
