import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EntryBadges, when } from '@/components/entries';
import { EntryForm, Input } from '@/components/entry-form';
import { LocationControl } from '@/components/location-control';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, Card, SectionTitle } from '@/components/ui/misc';
import { nowFor, text } from '@/logbook/fields';
import { editable, fileCounts } from '@/logbook/sync';
import type { EntryLocation, Item, LocalFile, StoredVersion, Values } from '@/logbook/types';
import { store, useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useTrips } from '@/store/trips';

/** "YYYY-MM-DD HH:MM" on the phone's clock, to and from the ISO time kept. */
const localTime = (iso: string) => nowFor('datetime', new Date(iso)).replace('T', ' ');
function parseLocal(value: string): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d)$/.exec(value.trim());
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
  return Number.isNaN(d.getTime()) || d.getMonth() !== Number(m[2]) - 1 ? null : d.toISOString();
}

/**
 * One entry. A draft, or an entry returned for correction, opens as its
 * form and is saved on the phone as it is typed; anything else reads.
 */
export default function EntryScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const item = useLogbook((s) => s.items.find((i) => i.id === id));
  const scope = useLogbook((s) => s.scope);
  const [version, setVersion] = useState<StoredVersion | null | undefined>(undefined);

  useEffect(() => {
    if (!scope || !item) return;
    let live = true;
    void store.version(scope, item.versionId).then((v) => live && setVersion(v));
    return () => {
      live = false;
    };
  }, [scope, item?.versionId, item]);

  if (!item) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title="Entry" icon="arrow-back" onIcon={() => router.back()} />
        <View className="p-4">
          <Banner tone="info">This entry is no longer on this phone.</Banner>
        </View>
      </View>
    );
  }

  if (version === undefined) return <View className="flex-1 bg-canvas" />;

  return editable(item) && version ? <EditEntry key={item.id} item={item} version={version} /> : <ReadEntry item={item} version={version} />;
}

function EditEntry({ item, version }: { item: Item; version: StoredVersion }) {
  const insets = useSafeAreaInsets();
  const kit = useLogbook((s) => s.kit);
  const saveDraft = useLogbook((s) => s.saveDraft);
  const finish = useLogbook((s) => s.finish);
  const discardDraft = useLogbook((s) => s.discardDraft);

  const [values, setValues] = useState<Values>(item.values);
  const [files, setFiles] = useState<LocalFile[]>(item.files);
  const [removed, setRemoved] = useState<string[]>(item.removedFiles);
  const [occurred, setOccurred] = useState(localTime(item.occurredAt));
  const [errors, setErrors] = useState<Record<string, string>>(item.error?.fields ?? {});
  const [busy, setBusy] = useState(false);
  // GPS (ADR-0028): only on sheets that take a location.
  const settings = kit?.sheets.find((s) => s.id === item.sheet.id)?.location ?? null;
  const openTrip = useTrips((s) => s.active);
  const trip = settings && openTrip && openTrip.logbook === item.logbook ? openTrip : null;
  const [location, setLocation] = useState<EntryLocation | null>(item.location ?? null);
  // A new entry on the trip's own sheet is linked to it unless the person unticks it.
  const [trackId, setTrackId] = useState<string | null>(() => item.trackId ?? (trip && item.serverId === null && trip.sheet.id === item.sheet.id && item.trackId === undefined ? trip.id : null));
  const place = settings ? { location, trackId } : {};
  const latest = useRef(item);
  useEffect(() => {
    latest.current = item;
  }, [item]);

  // Kept on the phone as it is typed: closing the app loses nothing.
  const dirty = useRef(false);
  useEffect(() => {
    if (!dirty.current) return;
    const timer = setTimeout(() => {
      void saveDraft(latest.current, { values, files, removedFiles: removed, occurredAt: parseLocal(occurred) ?? latest.current.occurredAt, ...place });
    }, 600);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [values, files, removed, occurred, saveDraft, location, trackId]);
  const touch = () => {
    dirty.current = true;
  };

  const save = async () => {
    const occurredAt = parseLocal(occurred);
    if (!occurredAt) {
      setErrors((e) => ({ ...e, occurred_at: 'Enter when it happened (YYYY-MM-DD HH:MM).' }));
      return null;
    }
    return saveDraft(latest.current, { values, files, removedFiles: removed, occurredAt, ...place });
  };

  const send = async () => {
    setBusy(true);
    try {
      if (settings?.required && !location && !trackId) {
        setErrors((e) => ({ ...e, location: settings.mode === 'route' ? 'Take the location, or link the entry to the trip in progress.' : 'Take the location for this entry.' }));
        toast('This sheet needs a location.', 'error');
        return;
      }
      const saved = await save();
      if (!saved) return;
      const problems = await finish(saved);
      setErrors(problems);
      if (Object.keys(problems).length > 0) {
        toast('Some answers need attention.', 'error');
        return;
      }
      toast('Queued. It is sent as soon as there is a connection.');
      router.back();
    } catch (e) {
      toast(e instanceof Error ? e.message : 'Could not finish the entry.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const returned = item.serverStatus === 'returned';
  const counts = fileCounts({ files, serverFiles: item.serverFiles, removedFiles: removed });

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        title={item.reference ?? item.sheet.name}
        subtitle={`${item.sheet.code} · ${item.logbook} · version ${version.version}`}
        icon="arrow-back"
        onIcon={() => {
          void save().finally(() => router.back());
        }}
      />
      <KeyboardAvoidingView behavior={Platform.OS === 'web' ? undefined : 'padding'} className="flex-1">
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 32 }}>
          {returned && (
            <Banner tone="warning">
              Returned by {item.lastReview?.reviewer ?? 'a reviewer'}: {item.lastReview?.comment ?? 'correct and send again.'}
            </Banner>
          )}
          {item.status === 'action_required' && item.error && !returned && <Banner tone="error">{item.error.message}</Banner>}

          <View className="gap-1.5">
            <Text className="text-base font-medium text-slate-800">
              When it happened<Text className="text-red-600"> *</Text>
            </Text>
            <View className="flex-row gap-2">
              <View className="flex-1">
                <Input
                  value={occurred}
                  placeholder="YYYY-MM-DD HH:MM"
                  keyboard="numbers-and-punctuation"
                  onChange={(t) => {
                    touch();
                    setOccurred(t);
                  }}
                />
              </View>
              <Button
                title="Now"
                variant="outline"
                size="lg"
                onPress={() => {
                  touch();
                  setOccurred(nowFor('datetime').replace('T', ' '));
                }}
              />
            </View>
            {errors.occurred_at ? <Text className="text-sm text-red-700">{errors.occurred_at}</Text> : null}
          </View>

          {settings && (
            <LocationControl
              settings={settings}
              value={location}
              trackId={trackId}
              trip={trip}
              error={errors.location}
              onChange={(next) => {
                touch();
                setLocation(next);
                setErrors(({ location: _gone, ...rest }) => rest);
              }}
              onTrack={(id) => {
                touch();
                setTrackId(id);
                setErrors(({ location: _gone, ...rest }) => rest);
              }}
            />
          )}

          <EntryForm
            fields={version.fields}
            values={values}
            errors={errors}
            members={kit?.members ?? []}
            resources={kit?.resources ?? []}
            files={files}
            serverFiles={item.serverFiles}
            removedFiles={removed}
            fileMaxKb={kit?.limits.fileMaxKb ?? 10240}
            filesPerField={kit?.limits.filesPerField ?? 10}
            onChange={(name, value) => {
              touch();
              setValues((v) => {
                const next = { ...v };
                if (value === undefined) delete next[name];
                else next[name] = value;
                return next;
              });
              setErrors((e) => {
                const { [name]: _gone, ...rest } = e;
                return rest;
              });
            }}
            onAddFile={(file) => {
              touch();
              setFiles((f) => [...f, file]);
            }}
            onRemoveFile={(target) => {
              touch();
              if ('uri' in target) setFiles((f) => f.filter((x) => x.uri !== target.uri));
              else setRemoved((r) => [...r, target.id]);
            }}
            onError={(message) => toast(message, 'error')}
          />

          <Text className="text-xs text-slate-500">
            {Object.values(counts).reduce((a, b) => a + b, 0)} files · saved on this phone as you type
          </Text>

          <Button title={returned ? 'Send correction' : 'Finish and send'} icon="paper-plane-outline" size="lg" loading={busy} onPress={() => void send()} />
          {item.serverId === null && item.status === 'draft' && (
            <Button
              title="Discard draft"
              icon="trash-outline"
              variant="ghost"
              disabled={busy}
              onPress={() => {
                void discardDraft(item).then(() => router.back());
              }}
            />
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

function ReadEntry({ item, version }: { item: Item; version: StoredVersion | null }) {
  const insets = useSafeAreaInsets();
  const kit = useLogbook((s) => s.kit);
  const people = Object.fromEntries([...(kit?.members ?? []).map((m) => [m.id, m.name]), ...(kit?.resources ?? []).map((r) => [r.id, `${r.code} · ${r.name}`])]);
  const files = [...item.serverFiles, ...item.files.map((f) => ({ id: f.uri, field: f.field, name: f.name, size: f.size, mime: f.mime }))];

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={item.reference ?? item.sheet.name} subtitle={`${item.sheet.code} · ${item.logbook}`} icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}>
        <EntryBadges item={item} />
        {item.status === 'failed' && item.error && <Banner tone="warning">{item.error.message} It is retried by itself.</Banner>}
        {item.status === 'action_required' && item.error && <Banner tone="error">{item.error.message}</Banner>}
        {item.lastReview && (
          <Banner tone={item.lastReview.decision === 'reviewed' ? 'success' : 'warning'}>
            {item.lastReview.decision === 'reviewed' ? 'Reviewed' : 'Returned'} by {item.lastReview.reviewer}
            {item.lastReview.decidedAt ? ` on ${when(item.lastReview.decidedAt)}` : ''}
            {item.lastReview.comment ? `: ${item.lastReview.comment}` : '.'}
          </Banner>
        )}

        <Card className="gap-1">
          <Text className="text-sm text-slate-500">Happened {when(item.occurredAt)}</Text>
          {item.submittedAt && <Text className="text-sm text-slate-500">Submitted {when(item.submittedAt)}</Text>}
          {item.syncedAt && <Text className="text-sm text-slate-500">Sent from this phone {when(item.syncedAt)}</Text>}
          {item.location && (
            <Text className="text-sm text-slate-500">
              Location {item.location.lat.toFixed(5)}, {item.location.lng.toFixed(5)}
              {item.location.accuracy_m != null ? ` (±${Math.round(item.location.accuracy_m)} m)` : ''}
            </Text>
          )}
          {item.trackId && <Text className="text-sm text-slate-500">Written on a recorded trip</Text>}
        </Card>

        <SectionTitle>Answers</SectionTitle>
        {!version ? (
          <Banner tone="info">The template of this entry is not on this phone. Its answers are on the web.</Banner>
        ) : (
          <Card className="gap-3">
            {version.fields.map((field) => {
              const fieldFiles = files.filter((f) => f.field === field.name);
              const shown = field.type === 'file' ? fieldFiles.map((f) => f.name).join(', ') : text(field, item.values[field.name], people);
              return (
                <View key={field.name} className="gap-0.5">
                  <Text className="text-sm font-medium text-slate-500">{field.label}</Text>
                  <Text className="text-base text-slate-900">{shown || '—'}</Text>
                </View>
              );
            })}
          </Card>
        )}
      </ScrollView>
    </View>
  );
}
