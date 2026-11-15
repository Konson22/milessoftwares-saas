import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { TextArea } from '@/components/partner-work';
import { parseMoney, toMajor } from '@/lib/money';
import type { Option } from '@/types';

/** A project request's fields, by type (App\Collaboration\RequestDetails). */

type Change = {
    category: string;
    current_minor: number | null;
    proposed_minor: number | null;
    reason: string;
};

export type RequestDetailsInput = {
    amount_minor?: number | null;
    currency?: string;
    agreement_reference?: string;
    categories?: string[];
    needed_by?: string;
    changes?: Change[];
    activity?: string;
    change?: string;
    current_end?: string;
    proposed_end?: string;
    asked?: string;
};

export type RequestFormData = {
    type: string;
    title: string;
    justification: string;
    reviewer: string;
    details: RequestDetailsInput;
};

/**
 * Stored details (camelCase, App\Collaboration\RequestDetails) back into the
 * form's fields.
 */
export function detailsToInput(
    type: string,
    details: Record<string, unknown>,
    amountMinor: number | null,
    currency: string | null,
): RequestDetailsInput {
    switch (type) {
        case 'funding':
            return {
                amount_minor: amountMinor,
                currency: currency ?? '',
                agreement_reference:
                    (details.agreementReference as string | null) ?? '',
                categories: (details.categories as string[] | undefined) ?? [],
                needed_by: (details.neededBy as string | null) ?? '',
            };
        case 'budget_revision':
            return {
                changes: (
                    (details.changes as
                        | {
                              category: string;
                              currentMinor: number;
                              proposedMinor: number;
                              reason: string | null;
                          }[]
                        | undefined) ?? []
                ).map((change) => ({
                    category: change.category,
                    current_minor: change.currentMinor,
                    proposed_minor: change.proposedMinor,
                    reason: change.reason ?? '',
                })),
            };
        case 'activity_change':
            return {
                activity: (details.activity as string) ?? '',
                change: (details.change as string) ?? '',
            };
        case 'timeline_extension':
            return {
                current_end: (details.currentEnd as string | null) ?? '',
                proposed_end: (details.proposedEnd as string) ?? '',
            };
        default:
            return {
                asked: (details.asked as string) ?? '',
                needed_by: (details.neededBy as string | null) ?? '',
            };
    }
}

export function RequestForm({
    initial,
    url,
    method,
    types,
    categories,
    reviewers,
    currency,
    submitLabel,
    typeFixed = false,
    reviewerFixed = false,
}: {
    initial: RequestFormData;
    url: string;
    method: 'post' | 'put';
    types: Option[];
    categories: Option[];
    reviewers: Option[];
    currency: string;
    submitLabel: string;
    typeFixed?: boolean;
    reviewerFixed?: boolean;
}) {
    const form = useForm<RequestFormData>(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const details = form.data.details;
    const setDetail = <K extends keyof RequestDetailsInput>(
        key: K,
        value: RequestDetailsInput[K],
    ) => form.setData('details', { ...form.data.details, [key]: value });
    const money = details.currency || currency;
    const changes = details.changes ?? [];
    const setChanges = (next: Change[]) => setDetail('changes', next);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                const options = { preserveScroll: true };
                if (method === 'post') {
                    form.post(url, options);
                } else {
                    form.transform(({ type: _type, ...rest }) => rest);
                    form.put(url, options);
                }
            }}
            className="grid gap-3 sm:grid-cols-2"
        >
            {!typeFixed && (
                <Field id="type" label="Kind of request" error={errors.type}>
                    <Select
                        id="type"
                        value={form.data.type}
                        options={types}
                        onChange={(event) =>
                            form.setData({
                                ...form.data,
                                type: event.target.value,
                                details:
                                    event.target.value === 'funding'
                                        ? { currency }
                                        : {},
                            })
                        }
                    />
                </Field>
            )}
            <Field id="title" label="Title" error={errors.title}>
                <Input
                    id="title"
                    value={form.data.title}
                    required
                    maxLength={200}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </Field>
            <Field
                id="justification"
                label="Justification"
                error={errors.justification}
                className="sm:col-span-2"
            >
                <TextArea
                    id="justification"
                    value={form.data.justification}
                    required
                    maxLength={5000}
                    onChange={(event) =>
                        form.setData('justification', event.target.value)
                    }
                />
            </Field>

            {form.data.type === 'funding' && (
                <>
                    <Field
                        id="amount"
                        label="Amount asked"
                        error={
                            errors['details.amount_minor'] ??
                            errors.amount_minor
                        }
                    >
                        <Input
                            id="amount"
                            inputMode="decimal"
                            required
                            defaultValue={
                                details.amount_minor
                                    ? toMajor(details.amount_minor, money)
                                    : ''
                            }
                            onChange={(event) =>
                                setDetail(
                                    'amount_minor',
                                    parseMoney(event.target.value, money),
                                )
                            }
                        />
                    </Field>
                    <Field
                        id="currency"
                        label="Currency"
                        error={errors.currency}
                    >
                        <Input
                            id="currency"
                            value={details.currency ?? currency}
                            maxLength={3}
                            required
                            onChange={(event) =>
                                setDetail(
                                    'currency',
                                    event.target.value.toUpperCase(),
                                )
                            }
                        />
                    </Field>
                    <Field
                        id="agreement"
                        label="Funding agreement reference"
                        error={errors.agreement_reference}
                    >
                        <Input
                            id="agreement"
                            value={details.agreement_reference ?? ''}
                            maxLength={120}
                            onChange={(event) =>
                                setDetail(
                                    'agreement_reference',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field
                        id="needed_by"
                        label="Needed by"
                        error={errors.needed_by}
                    >
                        <Input
                            id="needed_by"
                            type="date"
                            value={details.needed_by ?? ''}
                            onChange={(event) =>
                                setDetail('needed_by', event.target.value)
                            }
                        />
                    </Field>
                    <fieldset className="grid gap-1 sm:col-span-2 sm:grid-cols-3">
                        <legend className="mb-1 text-sm font-medium">
                            Budget categories
                        </legend>
                        {categories.map((category) => (
                            <label
                                key={category.value}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={(
                                        details.categories ?? []
                                    ).includes(category.value)}
                                    onChange={(event) =>
                                        setDetail(
                                            'categories',
                                            event.target.checked
                                                ? [
                                                      ...(details.categories ??
                                                          []),
                                                      category.value,
                                                  ]
                                                : (
                                                      details.categories ?? []
                                                  ).filter(
                                                      (value) =>
                                                          value !==
                                                          category.value,
                                                  ),
                                        )
                                    }
                                />
                                {category.label}
                            </label>
                        ))}
                    </fieldset>
                </>
            )}

            {form.data.type === 'budget_revision' && (
                <div className="grid gap-2 sm:col-span-2">
                    <p className="text-sm text-muted-foreground">
                        Each change: the category, its current amount and what
                        you propose ({currency}). Approval opens a budget
                        revision; the budget changes only once that is approved.
                    </p>
                    {errors.changes && (
                        <p className="text-sm text-destructive">
                            {errors.changes}
                        </p>
                    )}
                    {changes.map((change, index) => (
                        <div
                            key={index}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <Field id={`c-cat-${index}`} label="Category">
                                <Select
                                    id={`c-cat-${index}`}
                                    value={change.category}
                                    options={categories}
                                    onChange={(event) =>
                                        setChanges(
                                            changes.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          category:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Field id={`c-cur-${index}`} label="Current">
                                <Input
                                    id={`c-cur-${index}`}
                                    inputMode="decimal"
                                    defaultValue={
                                        change.current_minor === null
                                            ? ''
                                            : toMajor(
                                                  change.current_minor,
                                                  currency,
                                              )
                                    }
                                    onChange={(event) =>
                                        setChanges(
                                            changes.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          current_minor:
                                                              parseMoney(
                                                                  event.target
                                                                      .value,
                                                                  currency,
                                                              ),
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Field id={`c-new-${index}`} label="Proposed">
                                <Input
                                    id={`c-new-${index}`}
                                    inputMode="decimal"
                                    defaultValue={
                                        change.proposed_minor === null
                                            ? ''
                                            : toMajor(
                                                  change.proposed_minor,
                                                  currency,
                                              )
                                    }
                                    onChange={(event) =>
                                        setChanges(
                                            changes.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          proposed_minor:
                                                              parseMoney(
                                                                  event.target
                                                                      .value,
                                                                  currency,
                                                              ),
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id={`c-why-${index}`}
                                label="Reason"
                                className="flex-1"
                            >
                                <Input
                                    id={`c-why-${index}`}
                                    value={change.reason}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        setChanges(
                                            changes.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          reason: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove change"
                                onClick={() =>
                                    setChanges(
                                        changes.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    ))}
                    <span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setChanges([
                                    ...changes,
                                    {
                                        category: categories[0]?.value ?? '',
                                        current_minor: null,
                                        proposed_minor: null,
                                        reason: '',
                                    },
                                ])
                            }
                        >
                            <Plus className="size-4" /> Add a change
                        </Button>
                    </span>
                </div>
            )}

            {form.data.type === 'activity_change' && (
                <>
                    <Field
                        id="activity"
                        label="Activity"
                        error={errors.activity}
                    >
                        <Input
                            id="activity"
                            value={details.activity ?? ''}
                            required
                            maxLength={300}
                            onChange={(event) =>
                                setDetail('activity', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="change"
                        label="What changes, and why"
                        error={errors.change}
                        className="sm:col-span-2"
                    >
                        <TextArea
                            id="change"
                            value={details.change ?? ''}
                            required
                            maxLength={5000}
                            onChange={(event) =>
                                setDetail('change', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}

            {form.data.type === 'timeline_extension' && (
                <>
                    <Field
                        id="current_end"
                        label="Current end date"
                        error={errors.current_end}
                    >
                        <Input
                            id="current_end"
                            type="date"
                            value={details.current_end ?? ''}
                            onChange={(event) =>
                                setDetail('current_end', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="proposed_end"
                        label="Proposed end date"
                        error={errors.proposed_end}
                    >
                        <Input
                            id="proposed_end"
                            type="date"
                            value={details.proposed_end ?? ''}
                            required
                            onChange={(event) =>
                                setDetail('proposed_end', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}

            {form.data.type === 'information' && (
                <>
                    <Field
                        id="asked"
                        label="What is asked"
                        error={errors.asked}
                        className="sm:col-span-2"
                    >
                        <TextArea
                            id="asked"
                            value={details.asked ?? ''}
                            required
                            maxLength={5000}
                            onChange={(event) =>
                                setDetail('asked', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="needed_by_info"
                        label="Needed by"
                        error={errors.needed_by}
                    >
                        <Input
                            id="needed_by_info"
                            type="date"
                            value={details.needed_by ?? ''}
                            onChange={(event) =>
                                setDetail('needed_by', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}

            {!reviewerFixed && (
                <Field
                    id="reviewer"
                    label="Send to"
                    error={errors.reviewer}
                    hint="The party above you that reviews requests, unless you choose another."
                >
                    <Select
                        id="reviewer"
                        value={form.data.reviewer}
                        options={reviewers}
                        onChange={(event) =>
                            form.setData('reviewer', event.target.value)
                        }
                    />
                </Field>
            )}

            {Object.entries(errors)
                .filter(([key]) => key === 'request' || key === 'project')
                .map(([key, message]) => (
                    <p
                        key={key}
                        className="text-sm text-destructive sm:col-span-2"
                    >
                        {message}
                    </p>
                ))}

            <span className="sm:col-span-2">
                <Button type="submit" disabled={form.processing}>
                    {submitLabel}
                </Button>
            </span>
        </form>
    );
}
