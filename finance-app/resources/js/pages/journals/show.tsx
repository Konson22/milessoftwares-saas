import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { sourceLabel, sourceTypeLabel } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { reverse, show } from '@/routes/journals';

type Props = {
    journal: {
        id: string;
        number: string;
        date: string;
        memo: string;
        source: string;
        sourceType: string;
        sourceReference: string | null;
        createdBy: string | null;
        createdAt: string;
        reversalOf: { id: string; number: string } | null;
        reversedBy: { id: string; number: string } | null;
        reversible: boolean;
        lines: {
            id: string;
            accountId: string;
            account: string;
            description: string | null;
            debitMinor: number;
            creditMinor: number;
        }[];
        totalMinor: number;
    };
};

/**
 * One journal: its lines, where it came from, and its reversal.
 */
export default function ShowJournal({ journal }: Props) {
    const can = useCan();
    const { errors } = usePage().props;
    const [reversing, setReversing] = useState(false);
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={journal.number} />
            <PageHeader
                title={`Journal ${journal.number}`}
                badges={
                    <>
                        <StatusBadge
                            tone={
                                journal.source === 'finance'
                                    ? 'neutral'
                                    : 'info'
                            }
                        >
                            {sourceLabel(journal.source)}
                        </StatusBadge>
                        {journal.reversedBy && (
                            <StatusBadge tone="warning">Reversed</StatusBadge>
                        )}
                        {journal.reversalOf && (
                            <StatusBadge tone="warning">Reversal</StatusBadge>
                        )}
                    </>
                }
                description={journal.memo}
                actions={
                    journal.reversible &&
                    can('finance.journals.post') && (
                        <Button
                            variant="outline"
                            onClick={() => setReversing((value) => !value)}
                        >
                            <Undo2 className="size-4" aria-hidden />
                            Reverse
                        </Button>
                    )
                }
            />

            {errors.journal && (
                <p className="mb-4 text-sm text-destructive">
                    {errors.journal}
                </p>
            )}

            {reversing && (
                <Panel className="mb-5">
                    <PanelHeader
                        title="Reverse this journal"
                        description="Posts the mirror image of every line. The original stays in the books."
                    />
                    <PanelBody>
                        <Form
                            {...reverse.form(journal.id)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors: formErrors, processing }) => (
                                <>
                                    <Field
                                        id="entry_date"
                                        label="Date"
                                        error={formErrors.entry_date}
                                    >
                                        <Input
                                            id="entry_date"
                                            name="entry_date"
                                            type="date"
                                            defaultValue={today}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="reason"
                                        label="Reason"
                                        error={formErrors.reason}
                                        className="sm:col-span-3"
                                    >
                                        <Input
                                            id="reason"
                                            name="reason"
                                            required
                                            maxLength={200}
                                        />
                                    </Field>
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Post reversal
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <div className="grid gap-5 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Account</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead className="text-right">
                                    Debit
                                </TableHead>
                                <TableHead className="text-right">
                                    Credit
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {journal.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>
                                        <Link
                                            href={showAccount(line.accountId)}
                                            className="hover:underline"
                                        >
                                            {line.account}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {line.description}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={line.debitMinor}
                                            blankZero
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={line.creditMinor}
                                            blankZero
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={2}>Total</TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={journal.totalMinor} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={journal.totalMinor} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </Panel>

                <Panel>
                    <PanelHeader title="Details" />
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 p-4 text-sm">
                        <dt className="text-muted-foreground">Date</dt>
                        <dd className="tabular-nums">{journal.date}</dd>
                        <dt className="text-muted-foreground">Kind</dt>
                        <dd>{sourceTypeLabel(journal.sourceType)}</dd>
                        {journal.sourceReference && (
                            <>
                                <dt className="text-muted-foreground">
                                    Reference
                                </dt>
                                <dd className="font-mono text-xs">
                                    {journal.sourceReference}
                                </dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">Posted</dt>
                        <dd>
                            {new Date(journal.createdAt).toLocaleString()}
                            {journal.createdBy
                                ? ` by ${journal.createdBy}`
                                : ' automatically'}
                        </dd>
                        {journal.reversalOf && (
                            <>
                                <dt className="text-muted-foreground">
                                    Reverses
                                </dt>
                                <dd>
                                    <Link
                                        href={show(journal.reversalOf.id)}
                                        className="text-primary hover:underline"
                                    >
                                        {journal.reversalOf.number}
                                    </Link>
                                </dd>
                            </>
                        )}
                        {journal.reversedBy && (
                            <>
                                <dt className="text-muted-foreground">
                                    Reversed by
                                </dt>
                                <dd>
                                    <Link
                                        href={show(journal.reversedBy.id)}
                                        className="text-primary hover:underline"
                                    >
                                        {journal.reversedBy.number}
                                    </Link>
                                </dd>
                            </>
                        )}
                    </dl>
                </Panel>
            </div>
        </>
    );
}
