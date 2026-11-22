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
import { Errors, Field, Status } from '@/components/fields';
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
    members: Option[];
    resources: ResourceOption[];
    files: Record<string, FileRef[]>;
    /** GPS (ADR-0028) for this version; null: no location is taken. */
    locationSettings?: LocationSettings | null;
    location?: EntryLocation | null;
};

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
    locationSettings = null,
    location: initialLocation = null,
    members,
    resources,
    files,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<Values>(initial);
    const [occurredAt, setOccurredAt] = useState(initialOccurredAt);
    const [busy, setBusy] = useState(false);
    const [place, setPlace] = useState<EntryLocation | null>(initialLocation);
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
                    schedule_occurrence_id:
                        occurrence && !occurrence.taken ? occurrence.id : null,
                    ...placeData,
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
                    {!entry && occurrence && (
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
                            <Field
                                label="When did this happen?"
                                error={errors.occurred_at}
                                hint="The time of the activity itself, not when you write it."
                            >
                                <Input
                                    type="datetime-local"
                                    value={occurredAt}
                                    onChange={(e) =>
                                        setOccurredAt(e.target.value)
                                    }
                                    className="max-w-xs"
                                    required
                                />
                            </Field>
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
