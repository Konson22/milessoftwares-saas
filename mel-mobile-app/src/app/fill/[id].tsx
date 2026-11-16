import { router, useLocalSearchParams, useNavigation } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { FormRenderer } from '@/components/form-renderer';
import { KeyboardScroll } from '@/components/keyboard-scroll';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import type { Answers, Definition } from '@/collect/definition';
import { actionText, SERVER_LABEL, STATUS_LABEL } from '@/collect/labels';
import { evaluate } from '@/collect/rules';
import { localDay } from '@/collect/sync';
import type { Item, PhotoFile, StoredVersion } from '@/collect/types';
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
 * One record: filled in as a draft (saved as it is typed), corrected when a
 * reviewer asks, or read once it is sent.
 */
export default function FillScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const scope = useCollect((s) => s.scope);
  const kit = useCollect((s) => s.kit);
  const saveDraft = useCollect((s) => s.saveDraft);
  const finish = useCollect((s) => s.finish);
  const correct = useCollect((s) => s.correct);
  const discardDraft = useCollect((s) => s.discardDraft);
  const insets = useSafeAreaInsets();
  const navigation = useNavigation();

  const [item, setItem] = useState<Item | null>(null);
  const [version, setVersion] = useState<StoredVersion | null>(null);
  const [missing, setMissing] = useState(false);
  const [answers, setAnswers] = useState<Answers>({});
  const [photos, setPhotos] = useState<Record<string, PhotoFile>>({});
  const [consent, setConsent] = useState<boolean | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const dirty = useRef(false);

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
  if (!item || !evaluation) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title={item?.formName ?? 'Record'} icon="arrow-back" onIcon={() => router.back()} />
        {item && !version && <Banner tone="error">The form for this record is missing on this phone. Connect to download it again.</Banner>}
      </View>
    );
  }

  const definition = version!.definition;
  const needsConsent = editing && asksConsent(definition) && consent !== true;

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
        return;
      }
      toast('Saved. It is sent when there is a connection.', 'success');
      router.back();
    } finally {
      setBusy(false);
    }
  }

  async function sendCorrection() {
    if (!item) return;
    if (reason.trim() === '') return setMessage('Say what you changed.');
    setBusy(true);
    setShowErrors(true);
    try {
      const errors = await correct(item, answers, reason.trim());
      if (Object.keys(errors).length > 0) return setMessage('Some answers need attention.');
      toast('Correction saved. It is sent when there is a connection.', 'success');
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

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        title={item.formName}
        subtitle={item.serverStatus ? (SERVER_LABEL[item.serverStatus] ?? item.serverStatus) : STATUS_LABEL[item.status]}
        icon="arrow-back"
        onIcon={() => router.back()}
      />
      <KeyboardScroll contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
        {item.status === 'action_required' && <Banner tone="warning">{actionText(item)}</Banner>}
        {item.status === 'failed' && item.error && <Banner tone="info">{item.error.message} It is retried by itself.</Banner>}
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
              onPress={() => {
                dirty.current = true;
                setConsent(true);
              }}
            />
            <Button title="The respondent does not agree" variant="outline" onPress={() => void discard()} />
            <Text className="text-sm text-slate-500">Without consent, no answer is kept: the record is deleted.</Text>
          </View>
        ) : (
          <View pointerEvents={editing || correcting ? 'auto' : 'none'}>
            <FormRenderer
              definition={definition}
              answers={answers}
              evaluation={evaluation}
              photos={photos}
              photoMaxKb={kit?.settings.photoMaxKb ?? 800}
              showErrors={showErrors}
              notes={item.correction?.notes}
              photosLocked={!editing}
              onChange={change}
              onError={(m) => toast(m, 'error')}
            />
          </View>
        )}

        {editing && !needsConsent && (
          <View className="gap-2 pt-2">
            {showErrors && Object.keys(evaluation.errors).length > 0 && (
              <Text className="text-sm text-red-700">Check the answers marked in red above.</Text>
            )}
            <Button title="Finish and send" icon="checkmark-done" size="lg" loading={busy} onPress={() => void submit()} />
            <Text className="text-center text-sm text-slate-500">Saved on this phone as you go.</Text>
            <Button title="Delete draft" variant="ghost" onPress={() => void discard()} />
          </View>
        )}

        {correcting && (
          <View className="gap-2 pt-2">
            <Text className="text-base font-medium text-slate-800">What did you change?</Text>
            <TextInput
              value={reason}
              onChangeText={setReason}
              multiline
              placeholderTextColor={colors.faint}
              placeholder="For the reviewer"
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
