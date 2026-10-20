import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Select } from '@/components/field';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useAmountStep, useMoney } from '@/lib/format';

export type AccountOption = {
    id: string;
    label: string;
    type: string;
    /** Dimensions lines on the account must name: cost_centre, project. */
    requires?: string[];
};

export type TaxCodeOption = { id: string; label: string; rate_bp: number };

type Option = { id: string; label: string };

export type DimensionOptions = { costCentres: Option[]; projects: Option[] };

type Row = {
    key: number;
    accountId: string;
    description: string;
    amount: string;
    taxCodeId: string;
    costCentreId: string;
    projectId: string;
    debit: string;
    credit: string;
};

const DIMENSIONS = [
    {
        kind: 'cost_centre',
        label: 'Cost centre',
        field: 'cost_centre_id',
        row: 'costCentreId',
        options: 'costCentres',
    },
    {
        kind: 'project',
        label: 'Project',
        field: 'project_id',
        row: 'projectId',
        options: 'projects',
    },
] as const;

let nextKey = 1;

function emptyRow(accountId = ''): Row {
    return {
        key: nextKey++,
        accountId,
        description: '',
        amount: '',
        taxCodeId: '',
        costCentreId: '',
        projectId: '',
        debit: '',
        credit: '',
    };
}

/** Typed major units → minor units (0 when blank or not a number). */
export function toMinor(value: string, step: string): number {
    const number = Number(value);

    return Number.isFinite(number)
        ? Math.round(number * (step === '1' ? 1 : 100))
        : 0;
}

/** Tax on a net amount, rounded half up like the server (TaxCode::taxOn). */
export function taxOn(netMinor: number, rateBp: number): number {
    const tax = Math.floor((Math.abs(netMinor) * rateBp + 5000) / 10000);

    return netMinor < 0 ? -tax : tax;
}

/**
 * Editable lines posted as lines[i][account_id|description|amount] (bills,
 * invoices, with lines[i][tax_code_id] when tax codes are given) or
 * lines[i][account_id|description|debit|credit] (journals), with running
 * totals. With cost centres or projects, each line also posts
 * lines[i][cost_centre_id] and lines[i][project_id], required where the
 * account says so. Amounts are in the books' currency unless another is
 * given.
 */
export function LinesEditor({
    accounts,
    mode,
    errors,
    minRows = 1,
    taxCodes = [],
    dimensions = { costCentres: [], projects: [] },
    currency = null,
}: {
    accounts: AccountOption[];
    mode: 'amount' | 'journal';
    errors: Record<string, string>;
    minRows?: number;
    taxCodes?: TaxCodeOption[];
    dimensions?: DimensionOptions;
    currency?: string | null;
}) {
    const taxed = mode === 'amount' && taxCodes.length > 0;
    const dimensioned =
        dimensions.costCentres.length > 0 || dimensions.projects.length > 0;
    const money = useMoney(currency);
    const step = useAmountStep(currency);
    const [rows, setRows] = useState<Row[]>(() =>
        Array.from(
            { length: Math.max(minRows, mode === 'journal' ? 2 : 1) },
            () => emptyRow(),
        ),
    );

    const change = (key: number, field: keyof Row, value: string) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key
                    ? {
                          ...row,
                          [field]: value,
                          // A journal line is a debit or a credit, not both.
                          ...(field === 'debit' && value !== ''
                              ? { credit: '' }
                              : {}),
                          ...(field === 'credit' && value !== ''
                              ? { debit: '' }
                              : {}),
                      }
                    : row,
            ),
        );

    const total = rows.reduce((sum, row) => sum + toMinor(row.amount, step), 0);
    const tax = rows.reduce((sum, row) => {
        const code = taxCodes.find((option) => option.id === row.taxCodeId);

        return code
            ? sum + taxOn(toMinor(row.amount, step), code.rate_bp)
            : sum;
    }, 0);
    const debits = rows.reduce((sum, row) => sum + toMinor(row.debit, step), 0);
    const credits = rows.reduce(
        (sum, row) => sum + toMinor(row.credit, step),
        0,
    );
    const lineError = (index: number) =>
        Object.entries(errors).find(
            ([key]) =>
                key === `lines.${index}` || key.startsWith(`lines.${index}.`),
        )?.[1];

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="w-[34%] py-2 pr-2">Account</th>
                        <th className="py-2 pr-2">Description</th>
                        {mode === 'amount' ? (
                            <>
                                <th className="w-36 py-2 pr-2 text-right">
                                    {taxed ? 'Net amount' : 'Amount'}
                                </th>
                                {taxed && (
                                    <th className="w-40 py-2 pr-2">Tax</th>
                                )}
                            </>
                        ) : (
                            <>
                                <th className="w-32 py-2 pr-2 text-right">
                                    Debit
                                </th>
                                <th className="w-32 py-2 pr-2 text-right">
                                    Credit
                                </th>
                            </>
                        )}
                        <th className="w-10" />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={row.key} className="align-top">
                            <td className="py-1.5 pr-2">
                                <Select
                                    name={`lines[${index}][account_id]`}
                                    value={row.accountId}
                                    onChange={(event) =>
                                        change(
                                            row.key,
                                            'accountId',
                                            event.target.value,
                                        )
                                    }
                                    aria-label={`Line ${index + 1} account`}
                                    required
                                >
                                    <option value="">Choose an account…</option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.label}
                                        </option>
                                    ))}
                                </Select>
                                {dimensioned && (
                                    <div className="mt-1.5 grid grid-cols-2 gap-1.5">
                                        {DIMENSIONS.map((dimension) => {
                                            const options =
                                                dimensions[dimension.options];
                                            const required = accounts
                                                .find(
                                                    (account) =>
                                                        account.id ===
                                                        row.accountId,
                                                )
                                                ?.requires?.includes(
                                                    dimension.kind,
                                                );

                                            return (
                                                options.length > 0 && (
                                                    <Select
                                                        key={dimension.kind}
                                                        name={`lines[${index}][${dimension.field}]`}
                                                        value={
                                                            row[dimension.row]
                                                        }
                                                        onChange={(event) =>
                                                            change(
                                                                row.key,
                                                                dimension.row,
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        aria-label={`Line ${index + 1} ${dimension.label.toLowerCase()}`}
                                                        required={required}
                                                        className="h-8 text-xs"
                                                    >
                                                        <option value="">
                                                            {required
                                                                ? `${dimension.label} (required)…`
                                                                : `No ${dimension.label.toLowerCase()}`}
                                                        </option>
                                                        {options.map(
                                                            (option) => (
                                                                <option
                                                                    key={
                                                                        option.id
                                                                    }
                                                                    value={
                                                                        option.id
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </Select>
                                                )
                                            );
                                        })}
                                    </div>
                                )}
                                {lineError(index) && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {lineError(index)}
                                    </p>
                                )}
                            </td>
                            <td className="py-1.5 pr-2">
                                <Input
                                    name={`lines[${index}][description]`}
                                    value={row.description}
                                    onChange={(event) =>
                                        change(
                                            row.key,
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    aria-label={`Line ${index + 1} description`}
                                    required={mode === 'amount'}
                                />
                            </td>
                            {mode === 'amount' ? (
                                <>
                                    <td className="py-1.5 pr-2">
                                        <Input
                                            name={`lines[${index}][amount]`}
                                            value={row.amount}
                                            onChange={(event) =>
                                                change(
                                                    row.key,
                                                    'amount',
                                                    event.target.value,
                                                )
                                            }
                                            inputMode="decimal"
                                            className="text-right tabular-nums"
                                            aria-label={`Line ${index + 1} amount`}
                                            required
                                        />
                                    </td>
                                    {taxed && (
                                        <td className="py-1.5 pr-2">
                                            <Select
                                                name={`lines[${index}][tax_code_id]`}
                                                value={row.taxCodeId}
                                                onChange={(event) =>
                                                    change(
                                                        row.key,
                                                        'taxCodeId',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-label={`Line ${index + 1} tax code`}
                                            >
                                                <option value="">No tax</option>
                                                {taxCodes.map((code) => (
                                                    <option
                                                        key={code.id}
                                                        value={code.id}
                                                    >
                                                        {code.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </td>
                                    )}
                                </>
                            ) : (
                                <>
                                    <td className="py-1.5 pr-2">
                                        <Input
                                            name={`lines[${index}][debit]`}
                                            value={row.debit}
                                            onChange={(event) =>
                                                change(
                                                    row.key,
                                                    'debit',
                                                    event.target.value,
                                                )
                                            }
                                            inputMode="decimal"
                                            className="text-right tabular-nums"
                                            aria-label={`Line ${index + 1} debit`}
                                        />
                                    </td>
                                    <td className="py-1.5 pr-2">
                                        <Input
                                            name={`lines[${index}][credit]`}
                                            value={row.credit}
                                            onChange={(event) =>
                                                change(
                                                    row.key,
                                                    'credit',
                                                    event.target.value,
                                                )
                                            }
                                            inputMode="decimal"
                                            className="text-right tabular-nums"
                                            aria-label={`Line ${index + 1} credit`}
                                        />
                                    </td>
                                </>
                            )}
                            <td className="py-1.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={rows.length <= minRows}
                                    onClick={() =>
                                        setRows((current) =>
                                            current.filter(
                                                (other) =>
                                                    other.key !== row.key,
                                            ),
                                        )
                                    }
                                    aria-label={`Remove line ${index + 1}`}
                                >
                                    <Trash2 className="size-4" aria-hidden />
                                </Button>
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t">
                        <td className="pt-2" colSpan={2}>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRows((current) => [
                                        ...current,
                                        emptyRow(),
                                    ])
                                }
                            >
                                <Plus className="size-4" aria-hidden />
                                Add line
                            </Button>
                        </td>
                        {mode === 'amount' ? (
                            <>
                                <td className="pt-2 pr-2 text-right font-semibold tabular-nums">
                                    {money(total)}
                                </td>
                                {taxed && (
                                    <td className="pt-2 pr-2 text-sm tabular-nums">
                                        + {money(tax)} tax ={' '}
                                        <span className="font-semibold">
                                            {money(total + tax)}
                                        </span>
                                    </td>
                                )}
                            </>
                        ) : (
                            <>
                                <td className="pt-2 pr-2 text-right font-semibold tabular-nums">
                                    {money(debits)}
                                </td>
                                <td className="pt-2 pr-2 text-right font-semibold tabular-nums">
                                    {money(credits)}
                                </td>
                            </>
                        )}
                        <td />
                    </tr>
                    {mode === 'journal' && (
                        <tr>
                            <td
                                colSpan={5}
                                className={`pt-1 text-right text-xs ${debits === credits && debits > 0 ? 'text-emerald-700' : 'text-amber-700'}`}
                            >
                                {debits === credits && debits > 0
                                    ? 'Balanced'
                                    : `Out of balance by ${money(Math.abs(debits - credits))}`}
                            </td>
                        </tr>
                    )}
                </tfoot>
            </table>
            {errors.lines && (
                <p className="mt-2 text-sm text-destructive">{errors.lines}</p>
            )}
        </div>
    );
}
