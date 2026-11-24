import { MapPin, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/fields';

/** A stop as the form edits it: workspace-local times, text numbers. */
export type StopInput = {
    place: string;
    arrived_at: string;
    departed_at: string;
    odometer: string;
    purpose: string;
    note: string;
    pin: { lat: number; lng: number; accuracy_m: number | null } | null;
};

/** A stop as the entry page shows it. */
export type StopRow = {
    place: string;
    arrivedAt: string;
    departedAt: string | null;
    odometer: number | null;
    legKm: number | null;
    purpose: string | null;
    note: string | null;
    pin: { lat: number; lng: number; accuracy_m: number | null } | null;
    minutes: number | null;
};

/** The device's local time, as datetime-local wants it. */
function nowLocal(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const EMPTY: Omit<StopInput, 'arrived_at'> = {
    place: '',
    departed_at: '',
    odometer: '',
    purpose: '',
    note: '',
    pin: null,
};

/**
 * The points a trip stopped at (ADR-0032 §5), in the order they happened.
 * Each is checked against the trip's start, end and odometer readings
 * when the trip is recorded.
 */
export function StopsEditor({
    stops,
    onChange,
    required,
    errors,
}: {
    stops: StopInput[];
    onChange: (stops: StopInput[]) => void;
    required: boolean;
    errors: Record<string, string>;
}) {
    const [locating, setLocating] = useState<number | null>(null);
    const patch = (i: number, p: Partial<StopInput>) =>
        onChange(stops.map((s, j) => (j === i ? { ...s, ...p } : s)));
    const pin = (i: number) => {
        if (!navigator.geolocation) {
            return;
        }
        setLocating(i);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                patch(i, {
                    pin: {
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy_m: Math.round(pos.coords.accuracy),
                    },
                });
                setLocating(null);
            },
            () => setLocating(null),
            { enableHighAccuracy: true, timeout: 15000 },
        );
    };

    return (
        <Panel>
            <PanelHeader
                title={`Stops (${stops.length})`}
                description={
                    required
                        ? 'Every point the vehicle stopped at, in order. At least one is needed to record the trip.'
                        : 'Every point the vehicle stopped at, in order.'
                }
            />
            <PanelBody className="grid gap-4">
                {errors.stops && (
                    <p className="text-sm text-destructive">{errors.stops}</p>
                )}
                {stops.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No stops yet. Add one when you arrive somewhere.
                    </p>
                )}
                <ol className="grid gap-4">
                    {stops.map((s, i) => {
                        const e = (key: string) => errors[`stops.${i}.${key}`];

                        return (
                            <li
                                key={i}
                                className="grid gap-3 rounded-md border p-4 sm:grid-cols-2"
                            >
                                <div className="flex items-center justify-between sm:col-span-2">
                                    <p className="text-sm font-medium">
                                        Stop {i + 1}
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        aria-label={`Remove stop ${i + 1}`}
                                        onClick={() =>
                                            onChange(
                                                stops.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                                <Field label="Place" error={e('place')}>
                                    <Input
                                        value={s.place}
                                        onChange={(ev) =>
                                            patch(i, { place: ev.target.value })
                                        }
                                        maxLength={200}
                                    />
                                </Field>
                                <Field
                                    label="Purpose (optional)"
                                    error={e('purpose')}
                                >
                                    <Input
                                        value={s.purpose}
                                        onChange={(ev) =>
                                            patch(i, {
                                                purpose: ev.target.value,
                                            })
                                        }
                                        maxLength={200}
                                    />
                                </Field>
                                <Field label="Arrived" error={e('arrived_at')}>
                                    <Input
                                        type="datetime-local"
                                        value={s.arrived_at}
                                        onChange={(ev) =>
                                            patch(i, {
                                                arrived_at: ev.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Left (optional)"
                                    error={e('departed_at')}
                                >
                                    <div className="flex gap-2">
                                        <Input
                                            type="datetime-local"
                                            value={s.departed_at}
                                            onChange={(ev) =>
                                                patch(i, {
                                                    departed_at:
                                                        ev.target.value,
                                                })
                                            }
                                        />
                                        {s.departed_at === '' && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    patch(i, {
                                                        departed_at: nowLocal(),
                                                    })
                                                }
                                            >
                                                Now
                                            </Button>
                                        )}
                                    </div>
                                </Field>
                                <Field
                                    label="Odometer (optional, km)"
                                    error={e('odometer')}
                                >
                                    <Input
                                        inputMode="decimal"
                                        value={s.odometer}
                                        onChange={(ev) =>
                                            patch(i, {
                                                odometer: ev.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Location (optional)"
                                    error={e('pin')}
                                >
                                    <div className="flex items-center gap-2 text-sm">
                                        {s.pin ? (
                                            <>
                                                <MapPin className="size-4" />
                                                {s.pin.lat.toFixed(5)},{' '}
                                                {s.pin.lng.toFixed(5)}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        patch(i, { pin: null })
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={locating === i}
                                                onClick={() => pin(i)}
                                            >
                                                <MapPin />
                                                {locating === i
                                                    ? 'Finding you…'
                                                    : 'Add my location'}
                                            </Button>
                                        )}
                                    </div>
                                </Field>
                                <Field
                                    label="Note (optional)"
                                    error={e('note')}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        value={s.note}
                                        onChange={(ev) =>
                                            patch(i, { note: ev.target.value })
                                        }
                                        maxLength={500}
                                    />
                                </Field>
                            </li>
                        );
                    })}
                </ol>
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            onChange([
                                ...stops,
                                { ...EMPTY, arrived_at: nowLocal() },
                            ])
                        }
                    >
                        <Plus /> Add a stop
                    </Button>
                </div>
            </PanelBody>
        </Panel>
    );
}

/** The trip's stops on the entry page: a numbered timeline. */
export function StopsTimeline({ stops }: { stops: StopRow[] }) {
    return (
        <Panel>
            <PanelHeader title={`Stops (${stops.length})`} />
            <ol className="divide-y">
                {stops.map((s, i) => (
                    <li key={i} className="flex gap-3 px-4 py-3 text-sm">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#e3edfc] text-xs font-medium text-[#1d4fb8]">
                            {i + 1}
                        </span>
                        <div className="grid gap-0.5">
                            <p className="font-medium">
                                {s.place}
                                {s.purpose ? ` · ${s.purpose}` : ''}
                            </p>
                            <p className="text-muted-foreground">
                                {s.arrivedAt}
                                {s.departedAt
                                    ? ` to ${s.departedAt.slice(11)}`
                                    : ''}
                                {s.minutes !== null
                                    ? ` (${s.minutes} min)`
                                    : ''}
                                {s.odometer !== null
                                    ? ` · ${s.odometer} km`
                                    : ''}
                                {s.legKm !== null
                                    ? ` · ${s.legKm} km from the previous point`
                                    : ''}
                            </p>
                            {(s.note || s.pin) && (
                                <p className="text-muted-foreground">
                                    {s.note}
                                    {s.note && s.pin ? ' · ' : ''}
                                    {s.pin && (
                                        <a
                                            className="underline"
                                            href={`https://www.openstreetmap.org/?mlat=${s.pin.lat}&mlon=${s.pin.lng}#map=16/${s.pin.lat}/${s.pin.lng}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Location on the map
                                        </a>
                                    )}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}
