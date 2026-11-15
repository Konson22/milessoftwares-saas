import { useId } from 'react';
import type { ReactNode } from 'react';
import { choicesOf } from './definition';
import type { Answers, Definition, GeoPoint, Item } from './definition';
import { evaluate } from './rules';
import type { ErrorCode, Evaluation } from './rules';

/*
 * A form filled in on the web: the builder's preview, a published
 * version's page, and corrections. Visibility, requirements and errors
 * all come from the rules engine, so what shows here is what the field
 * app and the server decide.
 */

type Props = {
    definition: Definition;
    answers: Answers;
    onChange?: (answers: Answers) => void;
    /** The day the form is filled in (for "today" in date rules). */
    today: string;
    /** Show errors (after "Check answers", or always when correcting). */
    showErrors?: boolean;
    /** Photos already stored, by question name: shown instead of a file picker. */
    photoUrls?: Record<string, string>;
    /** Questions whose answers are hidden from this person. */
    hidden?: string[];
};

export function useEvaluation(
    definition: Definition,
    answers: Answers,
    today: string,
): Evaluation {
    return evaluate(definition, answers, today);
}

export function FormRenderer({
    definition,
    answers,
    onChange,
    today,
    showErrors = false,
    photoUrls = {},
    hidden = [],
}: Props) {
    const evaluation = evaluate(definition, answers, today);
    const visible = new Set(evaluation.visible);
    const required = new Set(evaluation.required);
    const readOnly = onChange === undefined;

    const set = (name: string, value: unknown) => {
        if (!onChange) {
            return;
        }
        const next = { ...answers };
        if (
            value === undefined ||
            value === '' ||
            (Array.isArray(value) && value.length === 0)
        ) {
            delete next[name];
        } else {
            next[name] = value;
        }
        onChange(next);
    };

    const render = (items: Item[]): ReactNode =>
        items
            .filter((item) => visible.has(item.name))
            .map((item) => {
                if (item.type === 'group') {
                    return (
                        <fieldset
                            key={item.name}
                            className="grid gap-4 border-l-2 pl-4"
                        >
                            <legend className="mb-2 text-base font-semibold">
                                {item.label}
                            </legend>
                            {item.hint && (
                                <p className="-mt-2 text-sm text-muted-foreground">
                                    {item.hint}
                                </p>
                            )}
                            {render(item.items ?? [])}
                        </fieldset>
                    );
                }

                if (item.type === 'note') {
                    return (
                        <div
                            key={item.name}
                            className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-line"
                        >
                            {item.label}
                            {item.hint && (
                                <p className="mt-1 text-muted-foreground">
                                    {item.hint}
                                </p>
                            )}
                        </div>
                    );
                }

                const error = showErrors
                    ? evaluation.errors[item.name]
                    : undefined;

                return (
                    <Question
                        key={item.name}
                        item={item}
                        required={required.has(item.name)}
                        error={error ? errorMessage(error, item) : undefined}
                    >
                        {hidden.includes(item.name) ? (
                            <p className="text-sm text-muted-foreground italic">
                                Hidden: personal data
                            </p>
                        ) : (
                            <Input
                                item={item}
                                definition={definition}
                                value={answers[item.name]}
                                onChange={(value) => set(item.name, value)}
                                readOnly={readOnly}
                                photoUrl={photoUrls[item.name]}
                                invalid={error !== undefined}
                            />
                        )}
                    </Question>
                );
            });

    return <div className="grid gap-5">{render(definition.items ?? [])}</div>;
}

function Question({
    item,
    required,
    error,
    children,
}: {
    item: Item;
    required: boolean;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5" data-question={item.name}>
            <div className="text-sm font-medium">
                {item.label}
                {required && (
                    <span className="text-destructive" aria-label="required">
                        {' '}
                        *
                    </span>
                )}
            </div>
            {item.hint && (
                <p className="text-xs text-muted-foreground">{item.hint}</p>
            )}
            {children}
            {error && (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    'h-10 w-full rounded-md border bg-transparent px-3 text-sm disabled:opacity-100 aria-[invalid=true]:border-destructive';

function Input({
    item,
    definition,
    value,
    onChange,
    readOnly,
    photoUrl,
    invalid,
}: {
    item: Item;
    definition: Definition;
    value: unknown;
    onChange: (value: unknown) => void;
    readOnly: boolean;
    photoUrl?: string;
    invalid: boolean;
}) {
    const id = useId();
    const common = {
        id,
        'aria-label': item.label,
        'aria-invalid': invalid ? true : undefined,
        disabled: readOnly,
    };

    switch (item.type) {
        case 'text':
            return (
                <input
                    {...common}
                    type="text"
                    className={inputClass}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );

        case 'textarea':
            return (
                <textarea
                    {...common}
                    rows={4}
                    className="w-full rounded-md border bg-transparent px-3 py-2 text-sm aria-[invalid=true]:border-destructive"
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            );

        case 'integer':
        case 'decimal':
            return (
                <input
                    {...common}
                    type="number"
                    inputMode={item.type === 'integer' ? 'numeric' : 'decimal'}
                    step={item.type === 'integer' ? 1 : 'any'}
                    className={`${inputClass} max-w-48`}
                    value={
                        typeof value === 'number' || typeof value === 'string'
                            ? value
                            : ''
                    }
                    onChange={(event) => {
                        const raw = event.target.value;
                        const number = Number(raw);
                        onChange(
                            raw === ''
                                ? undefined
                                : Number.isFinite(number)
                                  ? number
                                  : raw,
                        );
                    }}
                />
            );

        case 'select_one':
        case 'yes_no':
            return (
                <div
                    role="radiogroup"
                    aria-label={item.label}
                    className={
                        item.type === 'yes_no' ? 'flex gap-2' : 'grid gap-1'
                    }
                >
                    {choicesOf(definition, item).map((choice) => (
                        <label
                            key={choice.name}
                            className={`flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm ${value === choice.name ? 'border-primary bg-primary/5' : ''} ${readOnly ? 'cursor-default' : ''}`}
                        >
                            <input
                                type="radio"
                                name={`${id}-${item.name}`}
                                checked={value === choice.name}
                                disabled={readOnly}
                                onChange={() => onChange(choice.name)}
                                onClick={() => {
                                    // A second click clears an optional answer.
                                    if (value === choice.name && !readOnly) {
                                        onChange(undefined);
                                    }
                                }}
                            />
                            {choice.label}
                        </label>
                    ))}
                </div>
            );

        case 'select_multiple': {
            const selected = Array.isArray(value) ? (value as string[]) : [];

            return (
                <div
                    className="grid gap-1"
                    role="group"
                    aria-label={item.label}
                >
                    {choicesOf(definition, item).map((choice) => (
                        <label
                            key={choice.name}
                            className={`flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm ${selected.includes(choice.name) ? 'border-primary bg-primary/5' : ''}`}
                        >
                            <input
                                type="checkbox"
                                checked={selected.includes(choice.name)}
                                disabled={readOnly}
                                onChange={(event) =>
                                    onChange(
                                        event.target.checked
                                            ? [...selected, choice.name]
                                            : selected.filter(
                                                  (name) =>
                                                      name !== choice.name,
                                              ),
                                    )
                                }
                            />
                            {choice.label}
                        </label>
                    ))}
                </div>
            );
        }

        case 'date':
        case 'time':
        case 'datetime':
            return (
                <input
                    {...common}
                    type={
                        item.type === 'datetime' ? 'datetime-local' : item.type
                    }
                    className={`${inputClass} max-w-60`}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) =>
                        onChange(event.target.value.slice(0, 16))
                    }
                />
            );

        case 'geopoint':
            return (
                <GeoInput
                    value={value as GeoPoint | undefined}
                    onChange={onChange}
                    readOnly={readOnly}
                />
            );

        case 'photo':
            return (
                <PhotoInput
                    value={typeof value === 'string' ? value : undefined}
                    onChange={onChange}
                    readOnly={readOnly}
                    url={photoUrl}
                />
            );
    }

    return null;
}

function GeoInput({
    value,
    onChange,
    readOnly,
}: {
    value: GeoPoint | undefined;
    onChange: (value: unknown) => void;
    readOnly: boolean;
}) {
    const has =
        value !== undefined &&
        typeof value === 'object' &&
        typeof value.lat === 'number';

    return (
        <div className="flex flex-wrap items-center gap-2 text-sm">
            {has ? (
                <span className="tabular-nums">
                    {value.lat.toFixed(5)}, {value.lng.toFixed(5)}
                    {typeof value.accuracy === 'number' &&
                        ` (±${Math.round(value.accuracy)} m)`}
                </span>
            ) : (
                <span className="text-muted-foreground">
                    {readOnly ? 'No location' : 'Not captured yet'}
                </span>
            )}
            {!readOnly && (
                <button
                    type="button"
                    className="h-9 rounded-md border px-3"
                    onClick={() =>
                        navigator.geolocation?.getCurrentPosition(
                            (position) =>
                                onChange({
                                    lat: position.coords.latitude,
                                    lng: position.coords.longitude,
                                    accuracy: position.coords.accuracy,
                                }),
                            () => undefined,
                            { enableHighAccuracy: true, timeout: 15000 },
                        )
                    }
                >
                    {has ? 'Capture again' : 'Capture location'}
                </button>
            )}
            {!readOnly && has && (
                <button
                    type="button"
                    className="h-9 px-2 text-muted-foreground underline"
                    onClick={() => onChange(undefined)}
                >
                    Clear
                </button>
            )}
        </div>
    );
}

function PhotoInput({
    value,
    onChange,
    readOnly,
    url,
}: {
    value: string | undefined;
    onChange: (value: unknown) => void;
    readOnly: boolean;
    url?: string;
}) {
    if (url) {
        return (
            <a href={url} target="_blank" rel="noreferrer" className="w-fit">
                <img
                    src={url}
                    alt=""
                    className="max-h-64 rounded-md border object-contain"
                />
            </a>
        );
    }

    if (readOnly) {
        return (
            <span className="text-sm text-muted-foreground">
                {value ? 'Photo not available' : 'No photo'}
            </span>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 text-sm">
            <input
                type="file"
                accept="image/*"
                capture="environment"
                className="text-sm"
                onChange={async (event) => {
                    const file = event.target.files?.[0];
                    if (!file) {
                        onChange(undefined);
                        return;
                    }
                    const digest = await crypto.subtle.digest(
                        'SHA-256',
                        await file.arrayBuffer(),
                    );
                    onChange(
                        [...new Uint8Array(digest)]
                            .map((byte) => byte.toString(16).padStart(2, '0'))
                            .join(''),
                    );
                }}
            />
            {value && (
                <span className="text-muted-foreground">Photo chosen</span>
            )}
        </div>
    );
}

/** What an error code means for a question, in words. */
export function errorMessage(code: ErrorCode, item: Item): string {
    const c = item.constraint ?? {};

    switch (code) {
        case 'required':
            return 'An answer is required.';
        case 'choice':
            return 'Choose from the list.';
        case 'min':
            return item.type === 'integer' || item.type === 'decimal'
                ? `The lowest allowed is ${c.min}.`
                : `Too early (earliest: ${describeBound(c.min)}).`;
        case 'max':
            return item.type === 'integer' || item.type === 'decimal'
                ? `The highest allowed is ${c.max}.`
                : `Too late (latest: ${describeBound(c.max)}).`;
        case 'min_length':
            return `At least ${c.min_length} characters.`;
        case 'max_length':
            return `At most ${c.max_length ?? (item.type === 'text' ? 1000 : 10000)} characters.`;
        case 'pattern':
            return c.pattern === 'phone'
                ? 'Enter a phone number (digits, spaces, an optional + first).'
                : 'Digits only.';
        case 'min_choices':
            return `Choose at least ${c.min_choices}.`;
        case 'max_choices':
            return `Choose at most ${c.max_choices}.`;
        case 'type':
            return item.type === 'integer'
                ? 'Enter a whole number.'
                : item.type === 'decimal'
                  ? 'Enter a number.'
                  : 'This answer is not valid.';
    }
}

export function describeBound(bound: unknown): string {
    if (bound === 'today') {
        return 'today';
    }
    if (typeof bound === 'string') {
        const relative = /^today([+-])(\d+)d$/.exec(bound);
        if (relative) {
            return `${relative[2]} day${relative[2] === '1' ? '' : 's'} ${relative[1] === '-' ? 'before' : 'after'} today`;
        }
        return bound;
    }

    return String(bound);
}

/** Today in the browser's time zone, as YYYY-MM-DD. */
export function localToday(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
