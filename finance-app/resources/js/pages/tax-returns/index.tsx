import { Form, Head, Link } from '@inertiajs/react';
import { FileCheck2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
import { TaxReport } from '@/components/tax-report';
import type { TaxReportData } from '@/components/tax-report';
import { useCan } from '@/lib/access';
import { index, show, store } from '@/routes/tax-returns';

type Return = {
    id: string;
    title: string;
    reference: string | null;
    outputMinor: number;
    inputMinor: number;
    netMinor: number;
    settled: boolean;
};

type Props = {
    returns: Return[];
    firstReturn: boolean;
    preview: TaxReportData | null;
    startsOn: string | null;
    endsOn: string;
};

/**
 * Tax returns: the next period as it stands, ready to file, and the
 * returns filed so far. Filing locks the period for anything that touches
 * the tax account.
 */
export default function TaxReturns({
    returns,
    firstReturn,
    preview,
    startsOn,
    endsOn,
}: Props) {
    const can = useCan();
    const manage = can('finance.tax.manage');

    return (
        <>
            <Head title="Tax returns" />
            <PageHeader
                title="Tax returns"
                description="Output tax on sales less input tax on purchases, period by period. A filed period is locked for tax."
            />

            <Panel className="mb-3">
                <PanelHeader
                    title={
                        firstReturn
                            ? 'First return'
                            : `Next return from ${startsOn}`
                    }
                />
                <PanelBody>
                    <Form
                        {...index.form()}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-3 print:hidden"
                    >
                        {firstReturn && (
                            <Field id="starts_on" label="From">
                                <Input
                                    id="starts_on"
                                    name="starts_on"
                                    type="date"
                                    defaultValue={startsOn ?? ''}
                                />
                            </Field>
                        )}
                        <Field id="ends_on" label="To">
                            <Input
                                id="ends_on"
                                name="ends_on"
                                type="date"
                                defaultValue={endsOn}
                            />
                        </Field>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelBody>
                {preview === null ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        {startsOn === null
                            ? 'Choose when the first return starts.'
                            : 'Choose an end date on or after the start.'}
                    </PanelBody>
                ) : (
                    <>
                        <TaxReport report={preview} />
                        {manage && (
                            <PanelBody>
                                <Form
                                    {...store.form()}
                                    className="flex flex-wrap items-end gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="starts_on"
                                                value={preview.from}
                                            />
                                            <input
                                                type="hidden"
                                                name="ends_on"
                                                value={preview.to}
                                            />
                                            <Field
                                                id="reference"
                                                label="Reference"
                                                error={
                                                    errors.reference ??
                                                    errors.ends_on ??
                                                    errors.starts_on
                                                }
                                                hint="The tax authority's acknowledgement, if any"
                                            >
                                                <Input
                                                    id="reference"
                                                    name="reference"
                                                    maxLength={64}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                File {preview.from} to{' '}
                                                {preview.to}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        )}
                    </>
                )}
            </Panel>

            <Panel>
                <PanelHeader title="Filed" />
                {returns.length === 0 ? (
                    <EmptyState
                        icon={FileCheck2}
                        title="No returns filed yet"
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Period</TableHead>
                                <TableHead>Reference</TableHead>
                                <TableHead className="text-right">
                                    Output
                                </TableHead>
                                <TableHead className="text-right">
                                    Input
                                </TableHead>
                                <TableHead className="text-right">
                                    Net
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {returns.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={show.url(item.id)}
                                            className="hover:underline"
                                        >
                                            {item.title}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {item.reference}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={item.outputMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={item.inputMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={item.netMinor} />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                item.settled ||
                                                item.netMinor === 0
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {item.settled || item.netMinor === 0
                                                ? 'Settled'
                                                : 'To settle'}
                                        </StatusBadge>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
