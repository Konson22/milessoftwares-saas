import { Form, Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, selectClass } from '@/components/fields';
import { formatDateTime } from '@/lib/mel';
import type { Option } from '@/lib/mel';
import { index as grid } from '@/routes/data';
import { apply, store, template } from '@/routes/import';

type Props = {
    programme: { id: string; code: string; name: string };
    periods: Option[];
    selectedPeriod: string | null;
    batches: {
        id: string;
        fileName: string;
        period: string;
        status: 'validated' | 'applied' | 'failed';
        rowsTotal: number;
        rowsReady: number;
        rowsSkipped: number;
        errors: { row: number; message: string }[];
        by: string;
        createdAt: string;
        mine: boolean;
    }[];
};

export default function Import({
    programme,
    periods,
    selectedPeriod,
    batches,
}: Props) {
    const [period, setPeriod] = useState(selectedPeriod ?? '');

    return (
        <>
            <Head title={`Import · ${programme.code}`} />
            <PageHeader
                title={`Import results · ${programme.code}`}
                actions={
                    <Button variant="outline" asChild>
                        <Link
                            href={grid({
                                query: { programme: programme.id },
                            })}
                        >
                            Back to data entry
                        </Link>
                    </Button>
                }
            />

            <div className="grid max-w-4xl gap-3">
                {periods.length === 0 ? (
                    <Panel>
                        <PanelBody className="text-sm text-muted-foreground">
                            No open periods to import into.
                        </PanelBody>
                    </Panel>
                ) : (
                    <Panel>
                        <PanelHeader
                            title="1. Fill in the template"
                            description="One row per indicator and unit for the period. Rows already imported, or left empty, are skipped."
                        />
                        <PanelBody className="flex flex-wrap items-end gap-3">
                            <Field id="period" label="Period">
                                <select
                                    id="period"
                                    value={period}
                                    onChange={(event) =>
                                        setPeriod(event.target.value)
                                    }
                                    className={`${selectClass} w-auto`}
                                >
                                    {periods.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {period && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={template.url({
                                            programme: programme.id,
                                            period,
                                        })}
                                    >
                                        <Download aria-hidden />
                                        Download template
                                    </a>
                                </Button>
                            )}
                        </PanelBody>
                        <Form
                            {...store.form(programme.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="border-t"
                        >
                            {({ errors, processing }) => (
                                <PanelBody className="grid gap-3">
                                    <div className="text-sm font-semibold">
                                        2. Upload it to check
                                    </div>
                                    <input
                                        type="hidden"
                                        name="period_id"
                                        value={period}
                                    />
                                    <Field
                                        id="file"
                                        label="Filled-in sheet (.xlsx or .csv)"
                                        error={errors.file ?? errors.period_id}
                                    >
                                        <Input
                                            id="file"
                                            type="file"
                                            name="file"
                                            accept=".xlsx,.csv"
                                        />
                                    </Field>
                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Check the sheet
                                        </Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Nothing is saved until you apply a
                                        checked sheet below.
                                    </p>
                                </PanelBody>
                            )}
                        </Form>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader
                        title="3. Your recent imports"
                        description="A sheet with any error is not imported at all: fix the rows and upload it again."
                    />
                    {batches.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            None yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {batches.map((batch) => (
                                <li key={batch.id} className="grid gap-2 p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span>
                                            <span className="font-medium">
                                                {batch.fileName}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                · {batch.period} ·{' '}
                                                {formatDateTime(
                                                    batch.createdAt,
                                                )}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            tone={
                                                batch.status === 'failed'
                                                    ? 'danger'
                                                    : batch.status === 'applied'
                                                      ? 'success'
                                                      : 'info'
                                            }
                                        >
                                            {batch.status === 'failed'
                                                ? `${batch.errors.length} rows to fix`
                                                : batch.status === 'applied'
                                                  ? 'Imported'
                                                  : `${batch.rowsReady} rows ready`}
                                        </StatusBadge>
                                    </div>
                                    {batch.rowsSkipped > 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            {batch.rowsSkipped} of{' '}
                                            {batch.rowsTotal} rows skipped:
                                            empty or already imported.
                                        </p>
                                    )}
                                    {batch.errors.length > 0 && (
                                        <ul className="grid gap-0.5 text-sm text-destructive">
                                            {batch.errors
                                                .slice(0, 50)
                                                .map((error, index) => (
                                                    <li key={index}>
                                                        Row {error.row}:{' '}
                                                        {error.message}
                                                    </li>
                                                ))}
                                        </ul>
                                    )}
                                    {batch.status === 'validated' &&
                                        batch.mine &&
                                        batch.rowsReady > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                <Form
                                                    {...apply.form({
                                                        programme: programme.id,
                                                        batch: batch.id,
                                                    })}
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="submit"
                                                                value="1"
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Import and
                                                                submit for
                                                                review
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                                <Form
                                                    {...apply.form({
                                                        programme: programme.id,
                                                        batch: batch.id,
                                                    })}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Import as drafts
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
