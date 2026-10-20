import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileUp, ListChecks, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { useCan } from '@/lib/access';
import { DATE_FORMATS, parseAmount, parseCsv, parseDate } from '@/lib/csv';
import { useAmountStep } from '@/lib/format';
import { index as bankRules } from '@/routes/bank-rules';
import { show, store } from '@/routes/reconciliation';

type Mapping = {
    header: string;
    date: string;
    description: string;
    reference: string;
    mode: string;
    amount: string;
    moneyIn: string;
    moneyOut: string;
    dateFormat: string;
    decimalMark: string;
};

type Bank = {
    id: string;
    label: string;
    lastEndsOn: string | null;
    lastClosingMinor: number | null;
    import: Partial<Mapping> | null;
};

type Props = {
    banks: Bank[];
    statements: {
        id: string;
        account: string;
        reference: string | null;
        startsOn: string;
        endsOn: string;
        openingMinor: number;
        closingMinor: number;
        lines: number;
        unmatched: number;
        reconciled: boolean;
    }[];
};

const DEFAULT_MAPPING: Mapping = {
    header: '1',
    date: '0',
    description: '1',
    reference: '',
    mode: 'single',
    amount: '2',
    moneyIn: '',
    moneyOut: '',
    dateFormat: 'd/m/Y',
    decimalMark: '.',
};

type Row = {
    date: string;
    description: string;
    reference: string;
    amount: string;
};

/**
 * The bank's export, read in the browser with the chosen columns.
 */
function readRows(
    cells: string[][],
    mapping: Mapping,
    decimals: number,
): { rows: Row[]; problems: string[] } {
    const rows: Row[] = [];
    const problems: string[] = [];
    const body = mapping.header === '1' ? cells.slice(1) : cells;
    const at = (row: string[], column: string) =>
        column === '' ? '' : (row[Number(column)] ?? '');

    body.forEach((row, index) => {
        const line = index + (mapping.header === '1' ? 2 : 1);
        const date = parseDate(at(row, mapping.date), mapping.dateFormat);
        const mark = mapping.decimalMark === ',' ? ',' : '.';
        let amount: string | null;

        if (mapping.mode === 'single') {
            amount = parseAmount(at(row, mapping.amount), mark, decimals);
        } else {
            const moneyIn = parseAmount(
                at(row, mapping.moneyIn),
                mark,
                decimals,
            );
            const moneyOut = parseAmount(
                at(row, mapping.moneyOut),
                mark,
                decimals,
            );
            amount =
                moneyIn === null || moneyOut === null
                    ? null
                    : moneyIn !== '' && moneyIn !== '0'
                      ? moneyIn.replace(/^-/, '')
                      : moneyOut !== '' && moneyOut !== '0'
                        ? `-${moneyOut.replace(/^-/, '')}`
                        : '0';
        }

        if (date === null) {
            problems.push(
                `Line ${line}: “${at(row, mapping.date)}” is not a ${mapping.dateFormat} date.`,
            );
        } else if (amount === null || amount === '') {
            problems.push(`Line ${line}: no amount.`);
        } else if (amount !== '0') {
            rows.push({
                date,
                description: at(row, mapping.description) || '(no description)',
                reference: at(row, mapping.reference),
                amount,
            });
        }
    });

    return { rows, problems };
}

function ImportStatement({
    banks,
    onDone,
}: {
    banks: Bank[];
    onDone: () => void;
}) {
    const { errors } = usePage().props;
    const step = useAmountStep();
    const decimals = step === '1' ? 0 : 2;
    const [bankId, setBankId] = useState(banks[0]?.id ?? '');
    const bank = banks.find((candidate) => candidate.id === bankId);
    const [mapping, setMapping] = useState<Mapping>({
        ...DEFAULT_MAPPING,
        ...bank?.import,
    });
    const [cells, setCells] = useState<string[][]>([]);
    const [fileName, setFileName] = useState('');
    const [endsOn, setEndsOn] = useState('');
    const [reference, setReference] = useState('');
    const [opening, setOpening] = useState('');
    const [closing, setClosing] = useState('');
    const [processing, setProcessing] = useState(false);

    const { rows, problems } = useMemo(
        () => readRows(cells, mapping, decimals),
        [cells, mapping, decimals],
    );
    const lastDate = rows.reduce(
        (last, row) => (row.date > last ? row.date : last),
        '',
    );
    const columns = (cells[0] ?? []).map((value, index) => ({
        value: String(index),
        label:
            mapping.header === '1'
                ? `${index + 1}: ${value}`
                : `Column ${index + 1} (${value})`,
    }));
    const set = (key: keyof Mapping, value: string) =>
        setMapping((current) => ({ ...current, [key]: value }));

    const choose = (id: string) => {
        setBankId(id);
        setMapping({
            ...DEFAULT_MAPPING,
            ...banks.find((candidate) => candidate.id === id)?.import,
        });
    };

    const read = async (file: File | undefined) => {
        if (!file) {
            return;
        }
        setFileName(file.name);
        setCells(parseCsv(await file.text()));
    };

    const submit = () => {
        router.post(
            store.url(),
            {
                account_id: bankId,
                ends_on: endsOn || lastDate,
                reference: reference || fileName,
                opening_balance: bank?.lastEndsOn ? '' : opening,
                closing_balance: closing,
                rows,
                mapping,
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );
    };

    const ColumnSelect = ({
        name,
        optional = false,
    }: {
        name: keyof Mapping;
        optional?: boolean;
    }) => (
        <Select
            value={mapping[name]}
            onChange={(event) => set(name, event.target.value)}
        >
            {optional && <option value="">None</option>}
            {columns.map((column) => (
                <option key={column.value} value={column.value}>
                    {column.label}
                </option>
            ))}
        </Select>
    );

    return (
        <Panel className="mb-4">
            <PanelHeader
                title="Import a bank statement"
                description="Download the statement from your bank as CSV. The file is read here in your browser; only the lines are sent."
            />
            <PanelBody className="grid gap-4">
                <div className="grid gap-3 sm:grid-cols-4">
                    <Field
                        id="account_id"
                        label="Bank account"
                        error={errors.account_id}
                    >
                        <Select
                            id="account_id"
                            value={bankId}
                            onChange={(event) => choose(event.target.value)}
                        >
                            {banks.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field id="file" label="CSV file" className="sm:col-span-3">
                        <Input
                            id="file"
                            type="file"
                            accept=".csv,text/csv,text/plain"
                            onChange={(event) => read(event.target.files?.[0])}
                        />
                    </Field>
                </div>

                {cells.length > 0 && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-4">
                            <Field id="header" label="First row">
                                <Select
                                    id="header"
                                    value={mapping.header}
                                    onChange={(event) =>
                                        set('header', event.target.value)
                                    }
                                >
                                    <option value="1">Column names</option>
                                    <option value="0">A transaction</option>
                                </Select>
                            </Field>
                            <Field id="date" label="Date column">
                                <ColumnSelect name="date" />
                            </Field>
                            <Field id="dateFormat" label="Dates look like">
                                <Select
                                    id="dateFormat"
                                    value={mapping.dateFormat}
                                    onChange={(event) =>
                                        set('dateFormat', event.target.value)
                                    }
                                >
                                    {DATE_FORMATS.map((format) => (
                                        <option
                                            key={format.value}
                                            value={format.value}
                                        >
                                            {format.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field id="decimalMark" label="Decimal mark">
                                <Select
                                    id="decimalMark"
                                    value={mapping.decimalMark}
                                    onChange={(event) =>
                                        set('decimalMark', event.target.value)
                                    }
                                >
                                    <option value=".">1,234.50</option>
                                    <option value=",">1.234,50</option>
                                </Select>
                            </Field>
                            <Field id="description" label="Description column">
                                <ColumnSelect name="description" />
                            </Field>
                            <Field id="reference" label="Reference column">
                                <ColumnSelect name="reference" optional />
                            </Field>
                            <Field id="mode" label="Amounts">
                                <Select
                                    id="mode"
                                    value={mapping.mode}
                                    onChange={(event) =>
                                        set('mode', event.target.value)
                                    }
                                >
                                    <option value="single">
                                        One column, money out negative
                                    </option>
                                    <option value="split">
                                        Money in and money out columns
                                    </option>
                                </Select>
                            </Field>
                            {mapping.mode === 'single' ? (
                                <Field id="amount" label="Amount column">
                                    <ColumnSelect name="amount" />
                                </Field>
                            ) : (
                                <div className="grid grid-cols-2 gap-2">
                                    <Field id="moneyIn" label="Money in">
                                        <ColumnSelect name="moneyIn" />
                                    </Field>
                                    <Field id="moneyOut" label="Money out">
                                        <ColumnSelect name="moneyOut" />
                                    </Field>
                                </div>
                            )}
                        </div>

                        {problems.length > 0 && (
                            <div className="text-sm text-destructive">
                                <p>
                                    {problems.length}{' '}
                                    {problems.length === 1
                                        ? 'line cannot'
                                        : 'lines cannot'}{' '}
                                    be read; check the columns and formats.
                                </p>
                                <ul className="list-disc pl-5">
                                    {problems.slice(0, 5).map((problem) => (
                                        <li key={problem}>{problem}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="overflow-x-auto border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.slice(0, 5).map((row, index) => (
                                        <TableRow key={index}>
                                            <TableCell className="tabular-nums">
                                                {row.date}
                                            </TableCell>
                                            <TableCell>
                                                {row.description}
                                            </TableCell>
                                            <TableCell>
                                                {row.reference}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.amount}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                                {rows.length}{' '}
                                {rows.length === 1 ? 'line' : 'lines'} read
                                {rows.length > 5 && ', the first five shown'}.
                                {bank?.lastEndsOn &&
                                    ` Lines on or before ${bank.lastEndsOn}, the end of the last statement, are skipped.`}
                            </p>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-4">
                            <Field
                                id="ends_on"
                                label="Statement date"
                                error={errors.ends_on}
                                hint="The last day it covers"
                            >
                                <Input
                                    id="ends_on"
                                    type="date"
                                    value={endsOn || lastDate}
                                    onChange={(event) =>
                                        setEndsOn(event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="statement-reference"
                                label="Statement number"
                                error={errors.reference}
                            >
                                <Input
                                    id="statement-reference"
                                    value={reference}
                                    placeholder={fileName}
                                    maxLength={64}
                                    onChange={(event) =>
                                        setReference(event.target.value)
                                    }
                                />
                            </Field>
                            {bank?.lastEndsOn ? (
                                <Field
                                    id="opening_balance"
                                    label="Opening balance"
                                    hint="The last statement's closing balance"
                                >
                                    <Input
                                        id="opening_balance"
                                        disabled
                                        className="text-right"
                                        value={
                                            (bank.lastClosingMinor ?? 0) /
                                            10 ** decimals
                                        }
                                    />
                                </Field>
                            ) : (
                                <Field
                                    id="opening_balance"
                                    label="Opening balance"
                                    error={errors.opening_balance}
                                    hint="As printed on the statement"
                                >
                                    <Input
                                        id="opening_balance"
                                        inputMode="decimal"
                                        className="text-right tabular-nums"
                                        value={opening}
                                        onChange={(event) =>
                                            setOpening(event.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            <Field
                                id="closing_balance"
                                label="Closing balance"
                                error={errors.closing_balance}
                                hint="As printed on the statement"
                            >
                                <Input
                                    id="closing_balance"
                                    inputMode="decimal"
                                    className="text-right tabular-nums"
                                    value={closing}
                                    onChange={(event) =>
                                        setClosing(event.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        {(errors.rows || errors.statement) && (
                            <p className="text-sm text-destructive">
                                {errors.rows ?? errors.statement}
                            </p>
                        )}
                        <div>
                            <Button
                                onClick={submit}
                                disabled={
                                    processing ||
                                    problems.length > 0 ||
                                    closing === ''
                                }
                            >
                                <Upload className="size-4" aria-hidden />
                                Import {rows.length}{' '}
                                {rows.length === 1 ? 'line' : 'lines'}
                            </Button>
                        </div>
                    </>
                )}
            </PanelBody>
        </Panel>
    );
}

/**
 * Bank statements: import, then match and reconcile each one.
 */
export default function Reconciliation({ banks, statements }: Props) {
    const can = useCan();
    const [importing, setImporting] = useState(false);

    return (
        <>
            <Head title="Reconciliation" />
            <PageHeader
                title="Reconciliation"
                description="Match each bank statement line to the books, then reconcile the statement."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={bankRules()}>
                                <ListChecks className="size-4" aria-hidden />
                                Bank rules
                            </Link>
                        </Button>
                        {can('finance.banking.reconcile') &&
                            banks.length > 0 && (
                                <Button
                                    onClick={() =>
                                        setImporting((value) => !value)
                                    }
                                >
                                    <FileUp className="size-4" aria-hidden />
                                    Import statement
                                </Button>
                            )}
                    </>
                }
            />

            {importing && (
                <ImportStatement
                    banks={banks}
                    onDone={() => setImporting(false)}
                />
            )}

            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Account</TableHead>
                            <TableHead>Statement</TableHead>
                            <TableHead>Period</TableHead>
                            <TableHead className="text-right">
                                Opening
                            </TableHead>
                            <TableHead className="text-right">
                                Closing
                            </TableHead>
                            <TableHead className="text-right">Lines</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {statements.length === 0 && (
                            <TableEmpty colSpan={7}>
                                <EmptyState
                                    icon={FileUp}
                                    title="No statements yet"
                                >
                                    Import your bank's CSV export to start
                                    reconciling.
                                </EmptyState>
                            </TableEmpty>
                        )}
                        {statements.map((statement) => (
                            <TableRow key={statement.id}>
                                <TableCell>{statement.account}</TableCell>
                                <TableCell>
                                    <Link
                                        href={show(statement.id)}
                                        className="text-primary hover:underline"
                                    >
                                        {statement.reference || 'Statement'}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {statement.startsOn} – {statement.endsOn}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={statement.openingMinor} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={statement.closingMinor} />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {statement.lines}
                                </TableCell>
                                <TableCell>
                                    {statement.reconciled ? (
                                        <StatusBadge tone="success">
                                            Reconciled
                                        </StatusBadge>
                                    ) : statement.unmatched > 0 ? (
                                        <StatusBadge tone="warning">
                                            {statement.unmatched} to match
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge tone="info">
                                            Ready to reconcile
                                        </StatusBadge>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
