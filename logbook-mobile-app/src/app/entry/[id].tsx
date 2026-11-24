import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EntryBadges, when } from '@/components/entries';
import { EntryForm, Input } from '@/components/entry-form';
import { LocationControl } from '@/components/location-control';
import { ActivityEnd, CalculationCard, EstimateCard, ReadingPicker, ShiftPicker } from '@/components/recording-controls';
import { ScreenHeader } from '@/components/screen-header';
import { TripStops } from '@/components/trip-stops';
import { Button } from '@/components/ui/button';
import { Banner, Card, SectionTitle } from '@/components/ui/misc';
import { estimate, phoneCalculation, readingAt, readingChoices, recordingOf, shiftAt } from '@/logbook/activity';
import { assetProblem, offered, readingsForAsset, subjectOf } from '@/logbook/assets';
import { nowFor, text } from '@/logbook/fields';
import { editable, fileCounts } from '@/logbook/sync';
import { reviews, type EntryDetail } from '@/logbook/transport';
import type { EntryLocation, Item, LocalFile, StoredVersion, Values } from '@/logbook/types';
import { store, useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';
import { useAuth } from '@/store/auth';
import { useTrips } from '@/store/trips';
import { useWork } from '@/store/work';
import { assignedOnly, dispatchOf, legs, localDay, stopProblems, stopsSetting, type Stop } from '@/logbook/vehicle-trips';

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

  // ADR-0030: how the sheet is recorded. Absent from older servers: nothing new is shown or sent.
  const recording = recordingOf(kit, item.sheet.id);
  const kitSheet = kit?.sheets.find((s) => s.id === item.sheet.id);
  const me = useAuth((s) => s.me?.user.id ?? null);
  const due = useWork((s) => s.due);
  const missedReadings = useWork((s) => s.missed);
  // ADR-0031: the asset this entry is about narrows the readings it can answer.
  const subject = subjectOf(kit, item.sheet.id);
  const chosenAsset = subject && typeof values[subject] === 'string' ? (values[subject] as string) : null;
  const choices =
    recording?.mode === 'scheduled' || item.occurrenceId
      ? readingsForAsset(readingChoices([...due, ...missedReadings], item.sheet.id, item.occurrenceId ?? null), chosenAsset, item.occurrenceId ?? null)
      : [];
  const isEvent = recording?.mode === 'event';
  const [ended, setEnded] = useState(item.activityEndedAt ? localTime(item.activityEndedAt) : '');
  const startedIso = parseLocal(occurred) ?? item.occurredAt;
  const endedIso = ended.trim() === '' ? null : parseLocal(ended);
  // ADR-0032: on an "assigned only" sheet, the vehicles assigned to the driver on the trip's day.
  const tripDay = assignedOnly(kit, item.sheet.id) ? localDay(startedIso) : undefined;
  const assetIssue = assetProblem(kit, item.sheet.id, version.fields, values, tripDay);
  const plannedTrip = dispatchOf(kit, item.dispatchId);
  const stopsOn = isEvent ? stopsSetting(kit, item.sheet.id) : 'off';
  const [stops, setStops] = useState<Stop[]>(item.stops ?? []);
  const reading_ = (field: string | null | undefined) => (field && values[field] !== undefined && values[field] !== '' && !Number.isNaN(Number(values[field])) ? Number(values[field]) : null);
  const odometer = recording?.activity?.measure === 'odometer' ? { start: reading_(recording.activity.start_field), end: reading_(recording.activity.end_field) } : { start: null, end: null };
  const [reading, setReading] = useState<string | null>(() => item.occurrenceId ?? (item.scheduleLink === 'extra' ? null : readingAt(choices, item.occurredAt)));
  const [extra, setExtra] = useState(item.scheduleLink === 'extra');
  const shifts = recording && recording.shifts !== 'off' ? (kit?.shifts ?? []).filter((s) => s.logbookId === null || s.logbookId === kitSheet?.logbook.id) : [];
  const [shiftId, setShiftId] = useState<string | null>(() => item.shiftId ?? (shifts.length > 0 ? shiftAt(shifts, kitSheet?.logbook.id ?? null, item.occurredAt, me) : null));
  const preview = recording?.activity ? estimate(recording.activity, kit?.resources ?? [], values, startedIso, endedIso) : null;
  const recorded = (): Partial<Item> => ({
    ...(choices.length > 0 || item.occurrenceId ? { occurrenceId: extra ? null : reading, scheduleLink: extra ? ('extra' as const) : reading ? ('named' as const) : null } : {}),
    ...(shifts.length > 0 ? { shiftId } : {}),
    ...(isEvent ? { activityStartedAt: startedIso, activityEndedAt: endedIso } : {}),
    ...(recording?.activity ? { calculation: phoneCalculation(preview) } : {}),
    ...(stopsOn !== 'off' ? { stops } : {}),
  });
  const latest = useRef(item);
  useEffect(() => {
    latest.current = item;
  }, [item]);

  // Kept on the phone as it is typed: closing the app loses nothing.
  const dirty = useRef(false);
  useEffect(() => {
    if (!dirty.current) return;
    const timer = setTimeout(() => {
      void saveDraft(latest.current, { values, files, removedFiles: removed, occurredAt: parseLocal(occurred) ?? latest.current.occurredAt, ...place, ...recorded() });
    }, 600);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [values, files, removed, occurred, saveDraft, location, trackId, reading, extra, shiftId, ended, stops]);
  const touch = () => {
    dirty.current = true;
  };

  const save = async () => {
    const occurredAt = parseLocal(occurred);
    if (!occurredAt) {
      setErrors((e) => ({ ...e, occurred_at: 'Enter when it happened (YYYY-MM-DD HH:MM).' }));
      return null;
    }
    return saveDraft(latest.current, { values, files, removedFiles: removed, occurredAt, ...place, ...recorded() });
  };

  const send = async () => {
    setBusy(true);
    try {
      if (settings?.required && !location && !trackId) {
        setErrors((e) => ({ ...e, location: settings.mode === 'route' ? 'Take the location, or link the entry to the trip in progress.' : 'Take the location for this entry.' }));
        toast('This sheet needs a location.', 'error');
        return;
      }
      const own: Record<string, string> = {};
      // Never sent with an asset the sheet no longer offers; never moved to another one.
      if (assetIssue && subject) own[subject] = 'Choose an asset this log sheet records.';
      if (shifts.length > 0 && recording?.shifts === 'required' && !shiftId) own.shift_id = 'Choose the shift.';
      if (isEvent && endedIso === null) own.activity_ended_at = ended.trim() === '' ? 'Enter when the activity ended.' : 'Enter when it ended (YYYY-MM-DD HH:MM).';
      else if (isEvent && endedIso && endedIso < startedIso) own.activity_ended_at = 'The end is before the start.';
      if (preview?.endBelowStart && recording?.activity?.end_field) own[recording.activity.end_field] = 'The end reading is below the start.';
      // ADR-0032 §5: the server's stop checks, done here so they can be fixed offline.
      Object.assign(own, stopProblems(stops, { startedAt: startedIso, endedAt: endedIso, odometerStart: odometer.start, odometerEnd: odometer.end }, stopsOn));
      if (Object.keys(own).length > 0) {
        await save();
        setErrors((e) => ({ ...e, ...own }));
        toast('Some answers need attention.', 'error');
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
          {assetIssue && <Banner tone="warning">{assetIssue}</Banner>}
          {plannedTrip && (
            <Banner tone="info">
              Planned trip: {plannedTrip.resource ?? 'the vehicle'} on {plannedTrip.day}
              {plannedTrip.time ? ` at ${plannedTrip.time}` : ''}
              {[plannedTrip.destination, plannedTrip.purpose].filter(Boolean).length ? ` · ${[plannedTrip.destination, plannedTrip.purpose].filter(Boolean).join(' · ')}` : ''}. Record it with this vehicle.
            </Banner>
          )}

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

          {isEvent && (
            <ActivityEnd
              started={occurred}
              value={ended}
              error={errors.activity_ended_at}
              onChange={(t) => {
                touch();
                setEnded(t);
                setErrors(({ activity_ended_at: _gone, ...rest }) => rest);
              }}
            />
          )}

          {choices.length > 0 && (
            <ReadingPicker
              choices={choices}
              value={reading}
              extra={extra}
              onChange={(next) => {
                touch();
                setReading(next.occurrenceId);
                setExtra(next.extra);
              }}
            />
          )}

          {shifts.length > 0 && (
            <ShiftPicker
              shifts={shifts}
              value={shiftId}
              required={recording?.shifts === 'required'}
              error={errors.shift_id}
              onChange={(id) => {
                touch();
                setShiftId(id);
                setErrors(({ shift_id: _gone, ...rest }) => rest);
              }}
            />
          )}

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
            offeredFor={(field) => offered(kit ?? null, item.sheet.id, field, tripDay)}
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

          {stopsOn !== 'off' && (
            <TripStops
              stops={stops}
              required={stopsOn === 'required'}
              odometerStart={odometer.start}
              errors={errors}
              onChange={(next) => {
                touch();
                setStops(next);
                setErrors((e) => Object.fromEntries(Object.entries(e).filter(([k]) => !k.startsWith('stops'))));
              }}
            />
          )}

          <EstimateCard estimate={preview} />

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
  // ADR-0030: the server's own fuel figure and shift, read when online. Offline, the phone's figure stays.
  const [server, setServer] = useState<EntryDetail | null>(null);
  useEffect(() => {
    if (!item.serverId || item.serverStatus === 'draft') return;
    let live = true;
    reviews
      .entry(item.serverId)
      .then((e) => live && setServer(e))
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, [item.serverId, item.serverStatus, item.revision]);
  const shiftName = server?.shift ?? kit?.shifts?.find((s) => s.id === item.shiftId)?.name ?? (item.shiftId ? 'recorded' : null);

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
          {item.activityEndedAt && <Text className="text-sm text-slate-500">Activity ended {when(item.activityEndedAt)}</Text>}
          {shiftName && <Text className="text-sm text-slate-500">Shift: {shiftName}</Text>}
          {server?.activityEntry && <Text className="text-sm text-slate-500">Recorded during {server.activityEntry.label}</Text>}
          {item.scheduleLink === 'extra' && <Text className="text-sm text-slate-500">An extra reading</Text>}
          {item.calculation && !server?.calculation && <Text className="text-sm text-slate-500">Fuel estimated on this phone: {item.calculation.estimated_l} L (the server keeps its own figure)</Text>}
        </Card>

        {server?.calculation && <CalculationCard calculation={server.calculation} />}

        {(item.stops ?? []).length > 0 && <StopsRead stops={item.stops ?? []} />}

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

/** ADR-0032 §5: the trip's stops, read only. */
function StopsRead({ stops }: { stops: Stop[] }) {
  const km = legs(stops, null);
  return (
    <>
      <SectionTitle>Stops ({stops.length})</SectionTitle>
      <Card className="gap-3">
        {stops.map((s, i) => (
          <View key={i} className="gap-0.5">
            <Text className="text-base font-medium text-slate-900">
              {i + 1}. {s.place}
              {s.purpose ? ` · ${s.purpose}` : ''}
            </Text>
            <Text className="text-sm text-slate-500">
              {when(s.arrived_at)}
              {s.departed_at ? ` to ${when(s.departed_at)}` : ''}
              {s.odometer !== null ? ` · ${s.odometer} km` : ''}
              {km[i] !== null && i > 0 ? ` · ${km[i]} km from the previous stop` : ''}
              {s.note ? ` · ${s.note}` : ''}
            </Text>
          </View>
        ))}
      </Card>
    </>
  );
}
