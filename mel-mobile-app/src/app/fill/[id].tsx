import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams, useNavigation } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { answerText, errorText, FormRenderer } from '@/components/form-renderer';
import { KeyboardScroll } from '@/components/keyboard-scroll';
import { RecordBadges } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { flatten, isAnswerable, type Answers, type Definition } from '@/collect/definition';
import { actionText } from '@/collect/labels';
import { newerVersion, pagesOf, progressOf, visiblePages } from '@/collect/overview';
import { evaluate } from '@/collect/rules';
import { localDay } from '@/collect/sync';
import type { Item, PhotoFile, StoredVersion } from '@/collect/types';
import { useStartRecord } from '@/hooks/use-start';
import { confirm } from '@/lib/feedback';
import { colors, webNoOutline } from '@/lib/theme';
import { store, useCollect } from '@/store/collect';
import { toast } from '@/store/toast';

/** Whether the form asks for consent before the first question (Submissions::asksConsent). */
export function asksConsent(definition: Definition): boolean {
  const consent = definition.settings.consent as { text?: unknown; required?: unknown } | undefined;
  return typeof consent?.text === 'string' && consent.text.trim() !== '' && consent.required !== false;
}

/**
 * One record: filled in as a draft (saved as it is typed), one section at
 * a time, checked on a review page, then queued; corrected when a reviewer
 * asks; read once it is sent. Answers stay as typed while moving between
 * sections, and hidden answers are dropped only when the record is
 * finished, by the same rules the server applies.
 */
export default function FillScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const scope = useCollect((s) => s.scope);
  const kit = useCollect((s) => s.kit);
  const saveDraft = useCollect((s) => s.saveDraft);
  const finish = useCollect((s) => s.finish);
  const correct = useCollect((s) => s.correct);
  const discardDraft = useCollect((s) => s.discardDraft);
  const { begin, busy: starting } = useStartRecord();
  const insets = useSafeAreaInsets();
  const navigation = useNavigation();

  const [item, setItem] = useState<Item | null>(null);
  const [version, setVersion] = useState<StoredVersion | null>(null);
  const [missing, setMissing] = useState(false);
  const [answers, setAnswers] = useState<Answers>({});
  const [photos, setPhotos] = useState<Record<string, PhotoFile>>({});
  const [consent, setConsent] = useState<boolean | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [pageIndex, setPageIndex] = useState(0);
  const [reviewing, setReviewing] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const dirty = useRef(false);
  const scroller = useRef<ScrollView>(null);

  useEffect(() => {
    if (!scope || !id) return;
    void (async () => {
      const found = await store.item(scope, id);
      if (!found) return setMissing(true);
      const v = await store.version(scope, found.formVersionId);
      setItem(found);
      setVersion(v);
      setAnswers(found.pendingCorrection?.answers ?? found.answers);
      setPhotos(found.photos);
      setConsent(found.consentGiven);
    })();
  }, [scope, id]);

  const editing = item?.status === 'draft';
  const correcting = item?.status === 'action_required' && item.correction !== null;
  const evaluation = useMemo(() => (version ? evaluate(version.definition, answers, localDay(new Date())) : null), [version, answers]);
  const pages = useMemo(() => (version && evaluation ? visiblePages(pagesOf(version.definition), evaluation) : []), [version, evaluation]);
  const paged = (editing || correcting) && pages.length > 1;
  const page = paged ? pages[Math.min(pageIndex, pages.length - 1)] : null;

  // A draft is saved as it is typed: a crash or a dead battery loses at most a second.
  useEffect(() => {
    if (!editing || !item || !dirty.current) return;
    const timer = setTimeout(() => {
      void saveDraft(item, answers, photos, consent).then((saved) => {
        dirty.current = false;
        setItem(saved);
      });
    }, 800);
    return () => clearTimeout(timer);
  }, [answers, photos, consent, editing, item, saveDraft]);

  // Leaving saves at once.
  useEffect(
    () =>
      navigation.addListener('beforeRemove', () => {
        if (editing && item && dirty.current) void saveDraft(item, answers, photos, consent);
        void useCollect.getState().reload();
      }),
    [navigation, editing, item, answers, photos, consent, saveDraft],
  );

  if (missing) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title="Record" icon="arrow-back" onIcon={() => router.back()} />
        <Banner tone="error">This record is not on this phone.</Banner>
      </View>
    );
  }
  if (!item || !evaluation || !version) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title={item?.formName ?? 'Record'} icon="arrow-back" onIcon={() => router.back()} />
        {item && !version && <Banner tone="error">The form for this record is missing on this phone. Connect, then open Sync and update the forms.</Banner>}
      </View>
    );
  }

  const definition = version.definition;
  const needsConsent = editing && asksConsent(definition) && consent !== true;
  const progress = progressOf(definition, answers, evaluation);
  const newer = newerVersion(item, kit);
  const offerRestart = newer !== null && newer.canStart && (editing || item.error?.code === 'version_closed');

  function change(name: string, value: unknown, photo?: PhotoFile | null) {
    dirty.current = true;
    setAnswers((current) => {
      const next = { ...current };
      if (value === undefined) delete next[name];
      else next[name] = value;
      return next;
    });
    if (photo !== undefined) {
      setPhotos((current) => {
        const next = { ...current };
        if (photo === null) delete next[name];
        else next[name] = photo;
        return next;
      });
    }
  }

  const top = () => scroller.current?.scrollTo({ y: 0, animated: false });

  function goTo(index: number) {
    if (page) setChecked((c) => new Set(c).add(page.key));
    setReviewing(false);
    setPageIndex(Math.max(0, Math.min(index, pages.length - 1)));
    top();
  }

  function review() {
    if (page) setChecked((c) => new Set(c).add(page.key));
    setShowErrors(true);
    setReviewing(true);
    top();
  }

  async function saveForLater() {
    if (!item) return;
    await saveDraft(item, answers, photos, consent);
    dirty.current = false;
    toast('Draft saved on this phone. Continue it from Forms › Drafts.', 'success');
    router.back();
  }

  async function submit() {
    if (!item) return;
    setBusy(true);
    setShowErrors(true);
    try {
      const saved = await saveDraft(item, answers, photos, consent);
      dirty.current = false;
      const errors = await finish(saved);
      if (Object.keys(errors).length > 0) {
        setMessage(`${Object.keys(errors).length} ${Object.keys(errors).length === 1 ? 'answer needs' : 'answers need'} attention.`);
        setReviewing(true);
        return;
      }
      toast('Saved and queued. It is sent when there is a connection.', 'success');
      router.back();
    } finally {
      setBusy(false);
    }
  }

  async function sendCorrection() {
    if (!item) return;
    if (reason.trim() === '') return setMessage('Say what you changed, for the reviewer.');
    setBusy(true);
    setShowErrors(true);
    try {
      const errors = await correct(item, answers, reason.trim());
      if (Object.keys(errors).length > 0) return setMessage('Some answers need attention.');
      toast('Correction queued. It is sent when there is a connection.', 'success');
      router.back();
    } finally {
      setBusy(false);
    }
  }

  async function discard() {
    if (!item) return;
    if (!(await confirm('Delete this draft?', 'Its answers and photos are removed from this phone.', 'Delete', true))) return;
    dirty.current = false;
    await discardDraft(item);
    router.back();
  }

  const pageErrors = page && (showErrors || checked.has(page.key));

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={item.formName} subtitle={`Version ${version.number}${paged && !reviewing ? ` · section ${pageIndex + 1} of ${pages.length}` : reviewing ? ' · review' : ''}`} icon="arrow-back" onIcon={() => router.back()}>
        {(editing || correcting) && progress.total > 0 && (
          <View className="mx-4 mt-2 gap-1" accessible accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: progress.total, now: progress.answered }} accessibilityLabel="Questions answered">
            <View className="h-1.5 overflow-hidden bg-white/20">
              <View className="h-1.5 bg-accent" style={{ width: `${Math.round((progress.answered / progress.total) * 100)}%` }} />
            </View>
            <Text className="text-xs text-brand-100">
              {progress.answered} of {progress.total} answered
            </Text>
          </View>
        )}
      </ScreenHeader>
      <KeyboardScroll ref={scroller}contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
        {!editing && <RecordBadges item={item} />}
        {item.status === 'action_required' && <Banner tone="warning">{actionText(item)}</Banner>}
        {item.status === 'failed' && item.error && <Banner tone="info">{item.error.message} It is retried by itself.</Banner>}
        {item.status === 'synced' && <Banner tone="info">Uploaded. A reviewer checks it next; you are told here if a correction is needed.</Banner>}
        {offerRestart && newer && (
          <View className="gap-2 border border-amber-200 bg-amber-50 p-3">
            <Text className="text-sm text-amber-900">
              This record uses version {version.number}. The form is now version {newer.version?.number}. {editing ? 'You can still finish it on its own version. ' : ''}To use the new version, start again: this record is kept until you delete it, and no answer is copied.
            </Text>
            <Button title={`Start again on version ${newer.version?.number}`} variant="outline" icon="refresh" loading={starting === newer.id} onPress={() => void begin(newer)} />
          </View>
        )}
        {message && <Banner tone="error">{message}</Banner>}

        {needsConsent ? (
          <View className="gap-4 border border-slate-200 bg-white p-4">
            <Text accessibilityRole="header" className="text-lg font-bold text-slate-800">
              Consent
            </Text>
            <Text className="text-base text-slate-700">{definition.settings.consent?.text}</Text>
            <Button
              title="The respondent agrees"
              icon="checkmark"
              size="lg"
              onPress={() => {
                dirty.current = true;
                setConsent(true);
              }}
            />
            <Button title="The respondent does not agree" variant="outline" size="lg" onPress={() => void discard()} />
            <Text className="text-sm text-slate-500">Without consent, no answer is kept: the record is deleted.</Text>
          </View>
        ) : reviewing && editing ? (
          <ReviewList definition={definition} answers={answers} evaluation={evaluation} pages={pages} onOpen={goTo} />
        ) : (
          <View pointerEvents={editing || correcting ? 'auto' : 'none'}>
            <FormRenderer
              definition={definition}
              answers={answers}
              evaluation={evaluation}
              photos={photos}
              photoMaxKb={kit?.settings.photoMaxKb ?? 800}
              showErrors={showErrors || !!pageErrors}
              notes={item.correction?.notes}
              photosLocked={!editing}
              only={page?.names}
              onChange={change}
              onError={(m) => toast(m, 'error')}
            />
          </View>
        )}

        {editing && !needsConsent && (
          <View className="gap-2 pt-2">
            {reviewing ? (
              <>
                {Object.keys(evaluation.errors).length > 0 && <Text className="text-sm text-red-700">Fix the answers marked in red before submitting. Tap one to go to it.</Text>}
                <Button title="Submit" icon="checkmark-done" size="lg" loading={busy} onPress={() => void submit()} />
                <Button title="Back to the questions" variant="outline" size="lg" onPress={() => goTo(paged ? pages.length - 1 : 0)} />
              </>
            ) : paged && pageIndex < pages.length - 1 ? (
              <View className="flex-row gap-2">
                <Button title="Back" icon="chevron-back" variant="outline" size="lg" className="flex-1" disabled={pageIndex === 0} onPress={() => goTo(pageIndex - 1)} />
                <Button title="Next" size="lg" className="flex-1" onPress={() => goTo(pageIndex + 1)} />
              </View>
            ) : (
              <View className="flex-row gap-2">
                {paged && <Button title="Back" icon="chevron-back" variant="outline" size="lg" className="flex-1" onPress={() => goTo(pageIndex - 1)} />}
                <Button title="Review answers" icon="list" size="lg" className="flex-1" onPress={review} />
              </View>
            )}
            <Button title="Save and continue later" icon="save-outline" variant="secondary" size="lg" onPress={() => void saveForLater()} />
            <Text className="text-center text-sm text-slate-500">Saved on this phone as you type.</Text>
            <Button title="Delete draft" variant="ghost" onPress={() => void discard()} />
          </View>
        )}

        {correcting && (
          <View className="gap-2 pt-2">
            {paged && (
              <View className="flex-row gap-2">
                <Button title="Back" icon="chevron-back" variant="outline" size="lg" className="flex-1" disabled={pageIndex === 0} onPress={() => goTo(pageIndex - 1)} />
                <Button title="Next" variant="outline" size="lg" className="flex-1" disabled={pageIndex >= pages.length - 1} onPress={() => goTo(pageIndex + 1)} />
              </View>
            )}
            <Text className="text-base font-medium text-slate-800">What did you change?</Text>
            <TextInput
              value={reason}
              onChangeText={setReason}
              multiline
              placeholderTextColor={colors.faint}
              placeholder="For the reviewer"
              accessibilityLabel="What did you change?"
              className="min-h-20 border border-slate-300 bg-white px-3 py-2 text-base text-slate-900"
              style={[webNoOutline, { textAlignVertical: 'top' }]}
            />
            <Button title="Send the correction" icon="send" size="lg" loading={busy} onPress={() => void sendCorrection()} />
          </View>
        )}
      </KeyboardScroll>
    </View>
  );
}

/** Every visible answer, by section, with what is missing or wrong; tap a row to go back to it. */
function ReviewList({
  definition,
  answers,
  evaluation,
  pages,
  onOpen,
}: {
  definition: Definition;
  answers: Answers;
  evaluation: NonNullable<ReturnType<typeof evaluate>>;
  pages: ReturnType<typeof pagesOf>;
  onOpen: (page: number) => void;
}) {
  const visible = new Set(evaluation.visible);
  const required = new Set(evaluation.required);
  const questions = flatten(definition.items).filter((q) => isAnswerable(q.type) && visible.has(q.name));
  const pageOf = (name: string) => Math.max(0, pages.findIndex((p) => p.names.includes(name)));

  return (
    <View className="gap-4">
      <Text accessibilityRole="header" className="text-lg font-bold text-slate-900">
        Review your answers
      </Text>
      {pages.map((p) => {
        const mine = questions.filter((q) => p.names.includes(q.name));
        if (mine.length === 0) return null;
        return (
          <View key={p.key} className="border border-slate-200 bg-white">
            {p.title && <Text className="border-b border-slate-200 px-3 py-2 text-sm font-bold uppercase tracking-wider text-slate-600">{p.title}</Text>}
            {mine.map((q) => {
              const error = evaluation.errors[q.name];
              const text = answerText(definition, q, answers[q.name]);
              return (
                <Pressable key={q.name} accessibilityRole="button" onPress={() => onOpen(pageOf(q.name))} className="min-h-12 flex-row items-start gap-2 border-b border-slate-100 px-3 py-2.5 active:bg-brand-50">
                  <View className="flex-1 gap-0.5">
                    <Text className="text-sm text-slate-600">
                      {q.label}
                      {required.has(q.name) ? <Text className="text-red-600"> *</Text> : null}
                    </Text>
                    <Text className={`text-base ${text ? 'text-slate-900' : 'italic text-slate-400'}`}>{text || 'Not answered'}</Text>
                    {error && <Text className="text-sm text-red-700">{errorText(error)}</Text>}
                  </View>
                  <Ionicons name={error ? 'alert-circle' : 'create-outline'} size={18} color={error ? colors.dangerDark : colors.faint} />
                </Pressable>
              );
            })}
          </View>
        );
      })}
    </View>
  );
}
