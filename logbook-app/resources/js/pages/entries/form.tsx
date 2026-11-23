import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, MapPin } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { ActionButton } from '@/components/actions';
import type { Values } from '@/components/entry-fields';
import { EntryFields } from '@/components/entry-fields';
import { Errors, Field, SelectField, Status } from '@/components/fields';
import type { FieldDef, FileRef, Option, ResourceOption } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import { destroy, show, store, update } from '@/routes/entries';
import { show as sheetShow } from '@/routes/sheets';
import { useConfirm } from '@/components/dialogs';
import type { EntryLocation, LocationSettings } from '@/lib/tracks';

type Props = {
    sheet: {
        id: string;
        name: string;
        code: string;
        instructions: string | null;
        reviewRequired: boolean;
        logbook: { id: string; name: string };
    };
    fields: FieldDef[];
    version: number;
    entry: {
        id: string;
        reference: string | null;
        status: string;
        revision: number;
        returnComment: string | null;
        returnedBy: string | null;
    } | null;
    values: Values;
    occurredAt: string;
    idempotencyKey: string | null;
    /** The expected entry this one answers, when opened from its reminder. */
    occurrence?: {
        id: string;
        dueAt: string;
        closesAt: string;
        status: string;
        /** Already answered by another entry. */
        taken: boolean;
    } | null;
    /** ADR-0030: a scheduled sheet; the author says which reading this is. */
    scheduled?: boolean;
    readings?: Reading[];
    members: Option[];
    resources: ResourceOption[];
    files: Record<string, FileRef[]>;
    /** GPS (ADR-0028) for this version; null: no location is taken. */
    locationSettings?: LocationSettings | null;
    location?: EntryLocation | null;
    /** ADR-0030 §4.5: whether entries name a shift, and the logbook's shifts. */
    shiftSetting?: 'off' | 'optional' | 'required';
    shifts?: ShiftOption[];
    shiftId?: string | null;
    /** ADR-0030 §4.4, §4.7: the activity and its fuel estimate. */
    recordingMode?: 'on_demand' | 'scheduled' | 'event';
    activity?: ActivitySettings | null;
    fuelRates?: Record<string, FuelRateRow[]>;
    activityEndedAt?: string | null;
    activityEntry?: { id: string; label: string } | null;
};

type ActivitySettings = {
    resource_field: string;
    measure: 'odometer' | 'hour_meter' | 'duration';
    start_field: string | null;
    end_field: string | null;
    actual_field: string | null;
    refill_field: string | null;
};

type FuelRateRow = {
    id: string;
    value: number;
    unit: string;
    method: 'distance' | 'hours';
    effective_from: string;
};

/**
 * The estimate as the server will work it out at submission: the rate in
 * force when the activity started, km × rate ÷ 100 or h × rate. Shown
 * only as a preview.
 */
function previewEstimate(
    activity: ActivitySettings,
    rates: Record<string, FuelRateRow[]>,
    values: Values,
    startedAt: string,
    endedAt: string,
) {
    const num = (field: string | null) => {
        const v = field ? values[field] : undefined;
        return v === undefined || v === null || v === '' || isNaN(Number(v))
            ? null
            : Number(v);
    };
    const resourceId = values[activity.resource_field];
    if (typeof resourceId !== 'string' || resourceId === '') {
        return null;
    }
    let quantity: number | null;
    if (activity.measure === 'duration') {
        const ms = new Date(endedAt).getTime() - new Date(startedAt).getTime();
        quantity = endedAt && ms >= 0 ? Math.round(ms / 36000) / 100 : null;
    } else {
        const start = num(activity.start_field);
        const end = num(activity.end_field);
        quantity =
            start !== null && end !== null
                ? Math.round((end - start) * 10) / 10
                : null;
    }
    const method = activity.measure === 'odometer' ? 'distance' : 'hours';
    const at = new Date(startedAt).getTime();
    const rate =
        (rates[resourceId] ?? [])
            .filter((r) => new Date(r.effective_from).getTime() <= at)
            .at(-1) ?? null;
    const estimated =
        quantity !== null && quantity >= 0 && rate && rate.method === method
            ? Math.round(
                  (method === 'distance'
                      ? (quantity * rate.value) / 100
                      : quantity * rate.value) * 100,
              ) / 100
            : null;
    const actual = num(activity.actual_field);
    return {
        quantity,
        unit: method === 'distance' ? 'km' : 'h',
        rate,
        mismatch: rate !== null && rate.method !== method,
        estimated,
        actual,
        refill: num(activity.refill_field),
        difference:
            estimated !== null && actual !== null
                ? Math.round((actual - estimated) * 100) / 100
                : null,
    };
}

type ShiftOption = {
    value: string;
    label: string;
    starts: string;
    ends: string;
    mine: boolean;
};

/** The shift whose hours hold a local "YYYY-MM-DDTHH:MM", else the person's own. */
function shiftAt(shifts: ShiftOption[], at: string): string {
    const hm = at.slice(11, 16);
    const hit = shifts.find((s) =>
        s.ends < s.starts
            ? hm >= s.starts || hm < s.ends
            : hm >= s.starts && hm < s.ends,
    );
    const mine = shifts.filter((s) => s.mine);
    return hit?.value ?? (mine.length === 1 ? mine[0].value : '');
}

type Reading = {
    id: string;
    opensAt: string;
    dueAt: string;
    closesAt: string;
    status: string;
};

/** The reading whose window holds the time, else none. */
function readingAt(readings: Reading[], at: string): string | null {
    const t = new Date(at).getTime();
    const hit = readings.find(
        (r) =>
            new Date(r.opensAt).getTime() <= t &&
            new Date(r.closesAt).getTime() >= t,
    );
    return hit?.id ?? null;
}

/**
 * Writing an entry: the sheet's published template, rendered from its
 * fields. Save as draft (files can then be attached) or submit.
 */
export default function EntryForm({
    sheet,
    fields,
    version,
    entry,
    values: initial,
    occurredAt: initialOccurredAt,
    idempotencyKey,
    occurrence = null,
    scheduled = false,
    readings = [],
    locationSettings = null,
    location: initialLocation = null,
    shiftSetting = 'off',
    shifts = [],
    shiftId = null,
    recordingMode = 'on_demand',
    activity = null,
    fuelRates = {},
    activityEndedAt = null,
    activityEntry = null,
    members,
    resources,
    files,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<Values>(initial);
    const [occurredAt, setOccurredAt] = useState(initialOccurredAt);
    const [busy, setBusy] = useState(false);
    const [place, setPlace] = useState<EntryLocation | null>(initialLocation);
    // The reading this entry is for, or 'extra' (ADR-0030 §4.3).
    const [reading, setReading] = useState<string>(
        () =>
            (occurrence && !occurrence.taken ? occurrence.id : null) ??
            readingAt(readings, initialOccurredAt) ??
            'extra',
    );
    // The shift: kept as chosen; until then, suggested from the time.
    const [shift, setShift] = useState<string>(
        () => shiftId ?? (entry ? '' : shiftAt(shifts, initialOccurredAt)),
    );
    const [shiftTouched, setShiftTouched] = useState(shiftId !== null);
    const shiftData = shiftSetting === 'off' ? {} : { shift_id: shift || null };
    // ADR-0030 §4.4: an event runs from "when it happened" to its end.
    const isEvent = recordingMode === 'event';
    const [endedAt, setEndedAt] = useState(activityEndedAt ?? '');
    const activityData = {
        ...(isEvent
            ? {
                  activity_started_at: occurredAt,
                  activity_ended_at: endedAt || null,
              }
            : {}),
        ...(activityEntry ? { activity_entry_id: activityEntry.id } : {}),
    };
    const estimate = activity
        ? previewEstimate(activity, fuelRates, values, occurredAt, endedAt)
        : null;
    // Only on sheets that take a location; otherwise nothing is sent.
    const placeData = locationSettings ? { location: place } : {};
    const ask = useConfirm();

    const send = async (submit: boolean) => {
        if (
            submit &&
            !(await ask(
                sheet.reviewRequired
                    ? {
                          title: 'Submit this entry for review?',
                          message:
                              'You cannot change it unless it is returned.',
                          confirmLabel: 'Submit',
                      }
                    : {
                          title: 'Record this entry?',
                          message: 'It cannot be changed afterwards.',
                          confirmLabel: 'Record',
                      },
            ))
        ) {
            return;
        }
        setBusy(true);
        const data = {
            occurred_at: occurredAt,
            values,
            submit,
            ...placeData,
            ...shiftData,
            ...activityData,
        };
        const options = {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        };
        if (entry) {
            router.put(update(entry.id).url, data as RequestPayload, options);
        } else {
            router.post(
                store().url,
                {
                    occurred_at: occurredAt,
                    values,
                    submit,
                    sheet_id: sheet.id,
                    idempotency_key: idempotencyKey,
                    ...(scheduled
                        ? {
                              schedule_link:
                                  reading === 'extra' ? 'extra' : 'named',
                              schedule_occurrence_id:
                                  reading === 'extra' ? null : reading,
                          }
                        : {
                              schedule_occurrence_id:
                                  occurrence && !occurrence.taken
                                      ? occurrence.id
                                      : null,
                          }),
                    ...placeData,
                    ...shiftData,
                    ...activityData,
                } as RequestPayload,
                options,
            );
        }
    };

    const generalErrors = Object.fromEntries(
        Object.entries(errors).filter(
            ([key]) =>
                !key.startsWith('values.') &&
                key !== 'occurred_at' &&
                key !== 'file',
        ),
    );

    return (
        <>
            <Head title={entry?.reference ?? `New ${sheet.name} entry`} />
            <PageHeader
                title={
                    entry?.reference
                        ? `${entry.reference} · ${sheet.name}`
                        : `New entry · ${sheet.name}`
                }
                description={
                    <>
                        <Link
                            href={sheetShow(sheet.id)}
                            className="hover:underline"
                        >
                            {sheet.logbook.name} · {sheet.code}
                        </Link>{' '}
                        · template version {version} ·{' '}
                        {sheet.reviewRequired
                            ? 'reviewed by a supervisor after submission'
                            : 'recorded on submission (no review step)'}
                    </>
                }
                badges={entry && <Status value={entry.status} />}
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    {shiftSetting !== 'off' && (
                        <SelectField
                            label={
                                shiftSetting === 'required'
                                    ? 'Shift'
                                    : 'Shift (optional)'
                            }
                            value={shift}
                            onChange={(next) => {
                                setShift(next);
                                setShiftTouched(true);
                            }}
                            options={shifts}
                            empty={
                                shifts.length === 0
                                    ? 'No shifts set up for this logbook'
                                    : 'No shift'
                            }
                            error={errors.shift_id}
                            hint={
                                !shiftTouched && shift !== ''
                                    ? 'Suggested from the time; change it if it is wrong.'
                                    : undefined
                            }
                            className="max-w-md"
                        />
                    )}
                    {!entry && scheduled && (
                        <ReadingPicker
                            readings={readings}
                            value={reading}
                            onChange={setReading}
                            takenNote={occurrence?.taken ?? false}
                        />
                    )}
                    {!entry && !scheduled && occurrence && (
                        <div className="flex gap-3 rounded-md border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900">
                            <CalendarClock className="size-5 shrink-0" />
                            <div>
                                {occurrence.taken ? (
                                    <p className="font-medium">
                                        This expected entry was already written.
                                        A new entry is recorded on its own.
                                    </p>
                                ) : (
                                    <>
                                        <p className="font-medium">
                                            Expected entry due{' '}
                                            {formatDateTime(occurrence.dueAt)}
                                        </p>
                                        <p className="mt-1 text-xs">
                                            Submitting by{' '}
                                            {formatDateTime(
                                                occurrence.closesAt,
                                            )}{' '}
                                            counts it against this schedule;
                                            after the due time it counts as
                                            late.
                                        </p>
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                    {entry?.status === 'returned' && (
                        <div className="flex gap-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                            <AlertTriangle className="size-5 shrink-0" />
                            <div>
                                <p className="font-medium">
                                    Returned for correction
                                    {entry.returnedBy &&
                                        ` by ${entry.returnedBy}`}
                                </p>
                                <p className="mt-1 whitespace-pre-line">
                                    {entry.returnComment}
                                </p>
                                <p className="mt-1 text-xs">
                                    Revision {entry.revision} is kept as it was
                                    submitted; your corrections become revision{' '}
                                    {entry.revision + 1}.
                                </p>
                            </div>
                        </div>
                    )}
                    {locationSettings && (
                        <LocationCapture
                            settings={locationSettings}
                            value={place}
                            onChange={setPlace}
                            error={errors.location}
                        />
                    )}
                    <Panel>
                        <PanelBody className="grid gap-5">
                            <Errors errors={generalErrors} />
                            {activityEntry && (
                                <p className="text-sm text-muted-foreground">
                                    Part of {activityEntry.label}
                                </p>
                            )}
                            <Field
                                label={
                                    isEvent
                                        ? 'When did it start?'
                                        : 'When did this happen?'
                                }
                                error={errors.occurred_at}
                                hint="The time of the activity itself, not when you write it."
                            >
                                <Input
                                    type="datetime-local"
                                    value={occurredAt}
                                    onChange={(e) => {
                                        setOccurredAt(e.target.value);
                                        if (!shiftTouched) {
                                            setShift(
                                                shiftAt(shifts, e.target.value),
                                            );
                                        }
                                    }}
                                    className="max-w-xs"
                                    required
                                />
                            </Field>
                            {isEvent && (
                                <Field
                                    label="When did it end?"
                                    error={errors.activity_ended_at}
                                    hint="Leave empty while it is still going on, and save a draft. It is recorded once it has ended."
                                >
                                    <Input
                                        type="datetime-local"
                                        value={endedAt}
                                        onChange={(e) =>
                                            setEndedAt(e.target.value)
                                        }
                                        className="max-w-xs"
                                    />
                                </Field>
                            )}
                            <EntryFields
                                fields={fields}
                                values={values}
                                onChange={(name, value) =>
                                    setValues((v) => ({ ...v, [name]: value }))
                                }
                                errors={errors}
                                members={members}
                                resources={resources}
                                files={files}
                                entryId={entry?.id ?? null}
                            />
                        </PanelBody>
                    </Panel>
                    {estimate && <EstimatePreview estimate={estimate} />}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button disabled={busy} onClick={() => send(true)}>
                            {sheet.reviewRequired
                                ? 'Submit for review'
                                : 'Submit and record'}
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => send(false)}
                        >
                            Save draft
                        </Button>
                        {entry && (
                            <Button asChild variant="ghost">
                                <Link href={show(entry.id)}>View entry</Link>
                            </Button>
                        )}
                        {entry?.status === 'draft' && entry.revision === 0 && (
                            <ActionButton
                                url={destroy(entry.id).url}
                                method="delete"
                                label="Discard draft"
                                variant="destructive"
                                confirm="Discard this draft and its files? This cannot be undone."
                            />
                        )}
                    </div>
                </div>
                {sheet.instructions && (
                    <Panel className="h-fit">
                        <PanelHeader title="Instructions" />
                        <PanelBody>
                            <p className="text-sm whitespace-pre-line">
                                {sheet.instructions}
                            </p>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}

/**
 * The entry's location from the browser, only when the person asks for it.
 * Nothing is made up: without a reading there is no location.
 */
function LocationCapture({
    settings,
    value,
    onChange,
    error,
}: {
    settings: LocationSettings;
    value: EntryLocation | null;
    onChange: (value: EntryLocation | null) => void;
    error?: string;
}) {
    const [asking, setAsking] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const take = () => {
        if (!('geolocation' in navigator)) {
            setProblem('This browser cannot give a location.');
            return;
        }
        setAsking(true);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                setAsking(false);
                setProblem(null);
                onChange({
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy_m: Number.isFinite(pos.coords.accuracy)
                        ? pos.coords.accuracy
                        : null,
                    captured_at: new Date(pos.timestamp).toISOString(),
                    source: 'browser',
                });
            },
            (e) => {
                setAsking(false);
                setProblem(
                    e.code === e.PERMISSION_DENIED
                        ? 'Location was refused. Allow it for this site in the browser, then try again.'
                        : 'No location could be found. Try again near a window or outside.',
                );
            },
            { enableHighAccuracy: true, timeout: 20_000, maximumAge: 0 },
        );
    };

    const rough =
        value?.accuracy_m != null &&
        settings.accuracy_m != null &&
        value.accuracy_m > settings.accuracy_m;

    return (
        <Panel>
            <PanelBody className="grid gap-2 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex items-center gap-2 font-medium">
                        <MapPin className="size-4" />
                        Location
                        {settings.required ? ' (required)' : ' (optional)'}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={take}
                            disabled={asking}
                        >
                            {asking
                                ? 'Finding…'
                                : value
                                  ? 'Take again'
                                  : 'Take my location'}
                        </Button>
                        {value && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => onChange(null)}
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                </div>
                {value ? (
                    <p>
                        {value.lat.toFixed(5)}, {value.lng.toFixed(5)}
                        {value.accuracy_m != null &&
                            ` · ±${Math.round(value.accuracy_m)} m`}{' '}
                        · taken {formatDateTime(value.captured_at)}
                        {value.source === 'phone' && ' on the phone'}
                    </p>
                ) : (
                    <p className="text-muted-foreground">
                        Not taken. Your location is only read when you press the
                        button.
                    </p>
                )}
                {rough && (
                    <p className="text-xs text-amber-800">
                        Less accurate than the {settings.accuracy_m} m this
                        sheet asks for. Try again if you can.
                    </p>
                )}
                {problem && (
                    <p className="text-xs text-destructive">{problem}</p>
                )}
                {error && <p className="text-xs text-destructive">{error}</p>}
            </PanelBody>
        </Panel>
    );
}

/** Which expected reading this entry answers, or an extra one between readings. */
function ReadingPicker({
    readings,
    value,
    onChange,
    takenNote,
}: {
    readings: Reading[];
    value: string;
    onChange: (value: string) => void;
    takenNote: boolean;
}) {
    const time = (at: string) =>
        new Date(at).toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        });

    return (
        <fieldset className="grid gap-2 rounded-md border border-[#c9d6f2] bg-[#f3f6fd] p-4 text-sm">
            <legend className="flex items-center gap-2 px-1 font-medium text-[#1d4396]">
                <CalendarClock className="size-4" /> Which reading is this?
            </legend>
            {takenNote && (
                <p className="text-xs text-[#1d4396]">
                    The reading you opened was already written.
                </p>
            )}
            {readings.map((r) => (
                <label
                    key={r.id}
                    className="flex cursor-pointer items-center gap-2 rounded-md bg-card px-3 py-2 shadow-[0_0_0_1px_#dfe2e5]"
                >
                    <input
                        type="radio"
                        name="reading"
                        checked={value === r.id}
                        onChange={() => onChange(r.id)}
                    />
                    <span className="font-medium">
                        Due {formatDateTime(r.dueAt)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        opens {time(r.opensAt)} · counts until{' '}
                        {time(r.closesAt)}
                        {r.status === 'missed' && ' · marked missed'}
                    </span>
                </label>
            ))}
            <label className="flex cursor-pointer items-center gap-2 rounded-md bg-card px-3 py-2 shadow-[0_0_0_1px_#dfe2e5]">
                <input
                    type="radio"
                    name="reading"
                    checked={value === 'extra'}
                    onChange={() => onChange('extra')}
                />
                <span className="font-medium">Extra reading</span>
                <span className="text-xs text-muted-foreground">
                    recorded on its own; counts for no expected reading
                </span>
            </label>
            {readings.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No expected reading is open for you now.
                </p>
            )}
        </fieldset>
    );
}

function EstimatePreview({
    estimate,
}: {
    estimate: NonNullable<ReturnType<typeof previewEstimate>>;
}) {
    const litres = (n: number | null) => (n === null ? '—' : `${n} L`);

    return (
        <Panel>
            <PanelBody className="grid gap-2 text-sm">
                <p className="font-medium">Fuel estimate (preview)</p>
                <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">
                            {estimate.unit === 'km'
                                ? 'Distance'
                                : 'Running time'}
                        </dt>
                        <dd>
                            {estimate.quantity === null
                                ? '—'
                                : `${estimate.quantity} ${estimate.unit}`}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Rate</dt>
                        <dd>
                            {estimate.rate
                                ? `${estimate.rate.value} ${estimate.rate.unit}`
                                : 'Not set for this resource'}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-2">
                        <dt className="text-muted-foreground">Estimated</dt>
                        <dd className="font-medium">
                            {litres(estimate.estimated)}
                        </dd>
                    </div>
                    {estimate.actual !== null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">
                                Fuel measured
                            </dt>
                            <dd>{litres(estimate.actual)}</dd>
                        </div>
                    )}
                    {estimate.difference !== null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">
                                Difference from estimate
                            </dt>
                            <dd>
                                {estimate.difference > 0 ? '+' : ''}
                                {estimate.difference} L
                            </dd>
                        </div>
                    )}
                    {estimate.refill !== null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">Refill</dt>
                            <dd>{litres(estimate.refill)}</dd>
                        </div>
                    )}
                </dl>
                {estimate.mismatch && (
                    <p className="text-xs text-muted-foreground">
                        This resource's rate is set for another measure, so no
                        estimate is made.
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    Worked out again when the entry is recorded, with the rate
                    in force when the activity started. An estimate, not a
                    measurement.
                </p>
            </PanelBody>
        </Panel>
    );
}
