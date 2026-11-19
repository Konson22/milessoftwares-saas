import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { ActionButton } from '@/components/actions';
import type { Values } from '@/components/entry-fields';
import { EntryFields } from '@/components/entry-fields';
import { Errors, Field, Status } from '@/components/fields';
import type { FieldDef, FileRef, Option } from '@/lib/logbook';
import { destroy, show, store, update } from '@/routes/entries';
import { show as sheetShow } from '@/routes/sheets';

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
    members: Option[];
    files: Record<string, FileRef[]>;
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
    members,
    files,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<Values>(initial);
    const [occurredAt, setOccurredAt] = useState(initialOccurredAt);
    const [busy, setBusy] = useState(false);

    const send = (submit: boolean) => {
        if (
            submit &&
            !window.confirm(
                sheet.reviewRequired
                    ? 'Submit this entry for review? You cannot change it unless it is returned.'
                    : 'Record this entry? It cannot be changed afterwards.',
            )
        ) {
            return;
        }
        setBusy(true);
        const data = {
            occurred_at: occurredAt,
            values,
            submit,
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
