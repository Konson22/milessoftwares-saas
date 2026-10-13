import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Select } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAmountStep, useMoney } from '@/lib/format';

export type AccountOption = { id: string; label: string; type: string };

type Row = {
    key: number;
    accountId: string;
    description: string;
    amount: string;
    debit: string;
    credit: string;
};

let nextKey = 1;

function emptyRow(accountId = ''): Row {
    return {
        key: nextKey++,
        accountId,
        description: '',
        amount: '',
        debit: '',
        credit: '',
    };
}

/** Typed major units → minor units (0 when blank or not a number). */
function toMinor(value: string, step: string): number {
    const number = Number(value);

    return Number.isFinite(number)
        ? Math.round(number * (step === '1' ? 1 : 100))
        : 0;
}

/**
 * Editable lines posted as lines[i][account_id|description|amount] (bills,
 * invoices) or lines[i][account_id|description|debit|credit] (journals),
 * with running totals.
 */
export function LinesEditor({
    accounts,
    mode,
    errors,
    minRows = 1,
}: {
    accounts: AccountOption[];
    mode: 'amount' | 'journal';
    errors: Record<string, string>;
    minRows?: number;
}) {
    const money = useMoney();
    const step = useAmountStep();
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
    const debits = rows.reduce((sum, row) => sum + toMinor(row.debit, step), 0);
    const credits = rows.reduce(
        (sum, row) => sum + toMinor(row.credit, step),
        0,
    );
    const lineError = (index: number) =>
        Object.entries(errors).find(([key]) =>
            key.startsWith(`lines.${index}.`),
        )?.[1];

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="w-[34%] py-2 pr-2">Account</th>
                        <th className="py-2 pr-2">Description</th>
                        {mode === 'amount' ? (
                            <th className="w-36 py-2 pr-2 text-right">
                                Amount
                            </th>
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
                            <td className="pt-2 pr-2 text-right font-semibold tabular-nums">
                                {money(total)}
                            </td>
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
