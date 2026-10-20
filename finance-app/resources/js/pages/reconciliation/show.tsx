import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CheckCheck, Link2, Unlink, Wand2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';
import { Select } from '@/components/field';
import { useCan } from '@/lib/access';
import { show as showJournal } from '@/routes/journals';
import { auto, destroy, index, reconcile, undo } from '@/routes/reconciliation';
import { enter, match, unmatch } from '@/routes/reconciliation/lines';

type Option = { id: string; label: string };

type Line = {
    id: string;
    date: string;
    description: string;
    reference: string | null;
    amountMinor: number;
    match: {
        journalId: string;
        number: string;
        date: string;
        memo: string;
    } | null;
    candidates: {
        id: string;
        journalId: string;
        number: string;
        date: string;
        memo: string;
    }[];
    rule: { accountId: string; account: string; memo: string | null } | null;
};

type Props = {
    statement: {
        id: string;
        account: string;
        accountId: string;
        reference: string | null;
        startsOn: string;
        endsOn: string;
        openingMinor: number;
        closingMinor: number;
        linesMinor: number;
        reconciledAt: string | null;
        reconciledBy: string | null;
        importedBy: string;
        latest: boolean;
    };
    lines: Line[];
    accounts: Option[];
    report: {
        asOf: string;
        statementMinor: number;
        depositsMinor: number;
        paymentsMinor: number;
        clearedAheadMinor: number;
        expectedMinor: number;
        booksMinor: number;
        differenceMinor: number;
        uncleared: {
            id: string;
            journalId: string;
            number: string;
            date: string;
            memo: string;
            amountMinor: number;
        }[];
    };
};

/**
 * Entering a statement line in the books against another account.
 */
function EnterLine({ line, accounts }: { line: Line; accounts: Option[] }) {
    return (
        <Form
            {...enter.form(line.id)}
            className="flex flex-wrap items-center gap-2"
        >
            {({ processing }) => (
                <>
                    <Select
                        name="account_id"
                        defaultValue={line.rule?.accountId ?? ''}
                        required
                        className="h-8 w-56"
                    >
                        <option value="">
                            {line.amountMinor > 0
                                ? 'Income from…'
                                : 'Spent on…'}
                        </option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.label}
                            </option>
                        ))}
                    </Select>
                    <Input
                        name="memo"
                        placeholder={line.description}
                        defaultValue={line.rule?.memo ?? ''}
                        className="h-8 w-56"
                        maxLength={255}
                    />
                    <Button
                        size="sm"
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {line.amountMinor > 0
                            ? 'Record money in'
                            : 'Record money out'}
                    </Button>
                </>
            )}
        </Form>
    );
}

function Row({
    line,
    open,
    accounts,
}: {
    line: Line;
    open: boolean;
    accounts: Option[];
}) {
    const [entering, setEntering] = useState(false);

    return (
        <TableRow className="align-top">
            <TableCell className="tabular-nums">{line.date}</TableCell>
            <TableCell>
                {line.description}
                {line.reference && (
                    <span className="block text-xs text-muted-foreground">
                        {line.reference}
                    </span>
                )}
            </TableCell>
            <TableCell className="text-right text-emerald-700">
                <Amount minor={Math.max(line.amountMinor, 0)} blankZero />
            </TableCell>
            <TableCell className="text-right">
                <Amount minor={Math.max(-line.amountMinor, 0)} blankZero />
            </TableCell>
            <TableCell className="min-w-80">
                {line.match ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone="success">
                            <Link2 className="size-3" aria-hidden />
                            Matched
                        </StatusBadge>
                        <Link
                            href={showJournal(line.match.journalId)}
                            className="font-mono text-xs text-primary hover:underline"
                        >
                            {line.match.number}
                        </Link>
                        <span className="text-xs text-muted-foreground">
                            {line.match.date} · {line.match.memo}
                        </span>
                        {open && (
                            <Form {...unmatch.form(line.id)}>
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        type="submit"
                                        disabled={processing}
                                    >
                                        <Unlink
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Unmatch
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                ) : open ? (
                    <div className="grid gap-2">
                        {line.candidates.map((candidate) => (
                            <Form
                                key={candidate.id}
                                {...match.form(line.id)}
                                className="flex flex-wrap items-center gap-2"
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="journal_line_id"
                                            value={candidate.id}
                                        />
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Match
                                        </Button>
                                        <span className="font-mono text-xs">
                                            {candidate.number}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {candidate.date} · {candidate.memo}
                                        </span>
                                    </>
                                )}
                            </Form>
                        ))}
                        {line.candidates.length === 0 && (
                            <span className="text-xs text-muted-foreground">
                                Nothing in the books for this amount within 30
                                days.
                                {line.rule && ` Rule: ${line.rule.account}.`}
                            </span>
                        )}
                        {entering || line.candidates.length === 0 ? (
                            <EnterLine line={line} accounts={accounts} />
                        ) : (
                            <Button
                                size="sm"
                                variant="link"
                                className="justify-self-start px-0"
                                onClick={() => setEntering(true)}
                            >
                                None of these: record it
                            </Button>
                        )}
                    </div>
                ) : (
                    <StatusBadge tone="warning">Not matched</StatusBadge>
                )}
            </TableCell>
        </TableRow>
    );
}

/**
 * One statement: its lines matched to the books, reconciliation, and how the
 * bank's balance compares with the books' on its last day.
 */
export default function Statement({
    statement,
    lines,
    accounts,
    report,
}: Props) {
    const can = useCan();
    const { errors } = usePage().props;
    const manage = can('finance.banking.reconcile');
    const open = manage && statement.reconciledAt === null;
    const unmatched = lines.filter((line) => line.match === null).length;
    const adds =
        statement.openingMinor + statement.linesMinor ===
        statement.closingMinor;
    const error =
        errors.statement ??
        errors.line ??
        errors.entry_date ??
        errors.account_id ??
        errors.journal;

    return (
        <>
            <Head title={`Statement ${statement.reference ?? ''}`} />
            <PageHeader
                title={`${statement.account}: ${statement.reference || 'statement'}`}
                description={`${statement.startsOn} to ${statement.endsOn}, imported by ${statement.importedBy}.`}
                badges={
                    statement.reconciledAt ? (
                        <StatusBadge tone="success">
                            Reconciled
                            {statement.reconciledBy &&
                                ` by ${statement.reconciledBy}`}
                        </StatusBadge>
                    ) : unmatched > 0 ? (
                        <StatusBadge tone="warning">
                            {unmatched} to match
                        </StatusBadge>
                    ) : (
                        <StatusBadge tone="info">
                            Ready to reconcile
                        </StatusBadge>
                    )
                }
                actions={
                    manage && (
                        <>
                            {open && unmatched > 0 && (
                                <Form {...auto.form(statement.id)}>
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Wand2
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Match automatically
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {open && (
                                <Form {...reconcile.form(statement.id)}>
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                unmatched > 0 ||
                                                !adds
                                            }
                                        >
                                            <CheckCheck
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Reconcile
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {statement.reconciledAt && (
                                <Form {...undo.form(statement.id)}>
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Undo reconciliation
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {open && statement.latest && (
                                <Form {...destroy.form(statement.id)}>
                                    {({ processing }) => (
                                        <Button
                                            variant="ghost"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    )
                }
            />

            {error && <p className="mb-4 text-sm text-destructive">{error}</p>}

            <div className="mb-4 grid gap-4 sm:grid-cols-4">
                {[
                    { label: 'Opening balance', minor: statement.openingMinor },
                    { label: 'Lines', minor: statement.linesMinor },
                    { label: 'Closing balance', minor: statement.closingMinor },
                ].map((tile) => (
                    <div key={tile.label} className="border bg-card p-3">
                        <p className="text-xs text-muted-foreground">
                            {tile.label}
                        </p>
                        <p className="text-lg font-semibold">
                            <Amount minor={tile.minor} />
                        </p>
                    </div>
                ))}
                <div className="border bg-card p-3">
                    <p className="text-xs text-muted-foreground">
                        Lines add up
                    </p>
                    <p className="text-lg font-semibold">
                        {adds ? (
                            <StatusBadge tone="success">Yes</StatusBadge>
                        ) : (
                            <StatusBadge tone="danger">
                                Off by{' '}
                                <Amount
                                    minor={
                                        statement.closingMinor -
                                        statement.openingMinor -
                                        statement.linesMinor
                                    }
                                />
                            </StatusBadge>
                        )}
                    </p>
                </div>
            </div>

            <Panel className="mb-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-28">Date</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">In</TableHead>
                            <TableHead className="text-right">Out</TableHead>
                            <TableHead>In the books</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.length === 0 && (
                            <TableEmpty colSpan={5}>
                                This statement has no lines.
                            </TableEmpty>
                        )}
                        {lines.map((line) => (
                            <Row
                                key={line.id}
                                line={line}
                                open={open}
                                accounts={accounts}
                            />
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            <Panel className="max-w-3xl">
                <PanelHeader
                    title={`Reconciliation on ${report.asOf}`}
                    description="The bank's balance against the books'."
                />
                <PanelBody className="grid gap-1 text-sm">
                    {[
                        {
                            label: 'Balance per bank statement',
                            minor: report.statementMinor,
                        },
                        {
                            label: 'Add deposits not yet on a statement',
                            minor: report.depositsMinor,
                        },
                        {
                            label: 'Less payments not yet on a statement',
                            minor: -report.paymentsMinor,
                        },
                        ...(report.clearedAheadMinor !== 0
                            ? [
                                  {
                                      label: 'Less statement lines matched to later entries',
                                      minor: -report.clearedAheadMinor,
                                  },
                              ]
                            : []),
                    ].map((row) => (
                        <p
                            key={row.label}
                            className="flex justify-between gap-4"
                        >
                            <span>{row.label}</span>
                            <Amount minor={row.minor} />
                        </p>
                    ))}
                    <p className="flex justify-between gap-4 border-t pt-1 font-semibold">
                        <span>Expected balance in the books</span>
                        <Amount minor={report.expectedMinor} />
                    </p>
                    <p className="flex justify-between gap-4">
                        <span>Balance in the books</span>
                        <Amount minor={report.booksMinor} />
                    </p>
                    <p className="flex justify-between gap-4">
                        <span>Unexplained difference</span>
                        {report.differenceMinor === 0 ? (
                            <StatusBadge tone="success">None</StatusBadge>
                        ) : (
                            <Amount
                                minor={report.differenceMinor}
                                className="font-semibold"
                            />
                        )}
                    </p>
                    {report.differenceMinor !== 0 && (
                        <p className="text-xs text-muted-foreground">
                            Usually lines still to match, or the first
                            statement's opening balance differing from the books
                            on that day.
                        </p>
                    )}
                </PanelBody>
                {report.uncleared.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Not yet on a statement</TableHead>
                                <TableHead>Journal</TableHead>
                                <TableHead>Memo</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.uncleared.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="tabular-nums">
                                        {item.date}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        <Link
                                            href={showJournal(item.journalId)}
                                            className="text-primary hover:underline"
                                        >
                                            {item.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="max-w-80 truncate">
                                        {item.memo}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={item.amountMinor} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <p className="mt-4 text-sm">
                <Link href={index()} className="text-primary hover:underline">
                    All statements
                </Link>
            </p>
        </>
    );
}
