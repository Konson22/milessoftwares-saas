import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { amount } from '@/lib/procurement';

export type EditableLine = {
    id?: string;
    kind: string;
    description: string;
    specification?: string;
    quantity: string;
    unit?: string;
    unit_price: string;
};

export const blankLine = (): EditableLine => ({
    kind: 'goods',
    description: '',
    specification: '',
    quantity: '1',
    unit: '',
    unit_price: '',
});

/** quantity × price in the typed decimals, for display only (the server computes the real amounts). */
function estimate(line: EditableLine): number {
    const q = Number(line.quantity);
    const p = Number(line.unit_price);

    return Number.isFinite(q) && Number.isFinite(p) ? q * p : 0;
}

/**
 * Goods and service lines with a running estimate. Used by requisitions
 * and purchase orders.
 */
export function LinesEditor({
    lines,
    onChange,
    errors,
    currency,
    specification = true,
}: {
    lines: EditableLine[];
    onChange: (lines: EditableLine[]) => void;
    errors: Record<string, string>;
    currency: string;
    specification?: boolean;
}) {
    const set = (i: number, patch: Partial<EditableLine>) =>
        onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const total = lines.reduce((sum, line) => sum + estimate(line), 0);
    const cell =
        'h-9 w-full rounded-md border border-input bg-background px-2 text-sm';

    return (
        <div className="grid gap-3">
            {lines.map((line, i) => (
                <div key={i} className="grid gap-2 rounded-md border p-3">
                    <div className="grid gap-2 sm:grid-cols-12">
                        <select
                            aria-label="Kind"
                            value={line.kind}
                            onChange={(e) => set(i, { kind: e.target.value })}
                            className={`${cell} sm:col-span-2`}
                        >
                            <option value="goods">Goods</option>
                            <option value="service">Service</option>
                        </select>
                        <Input
                            aria-label="Description"
                            placeholder="Description"
                            value={line.description}
                            onChange={(e) =>
                                set(i, { description: e.target.value })
                            }
                            className="sm:col-span-4"
                        />
                        <Input
                            aria-label="Quantity"
                            inputMode="decimal"
                            placeholder="Qty"
                            value={line.quantity}
                            onChange={(e) =>
                                set(i, { quantity: e.target.value })
                            }
                            className="sm:col-span-2"
                        />
                        <Input
                            aria-label="Unit"
                            placeholder="Unit"
                            value={line.unit ?? ''}
                            onChange={(e) => set(i, { unit: e.target.value })}
                            className="sm:col-span-1"
                        />
                        <Input
                            aria-label={`Unit price (${currency})`}
                            inputMode="decimal"
                            placeholder={`Unit price ${currency}`}
                            value={line.unit_price}
                            onChange={(e) =>
                                set(i, { unit_price: e.target.value })
                            }
                            className="sm:col-span-2"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Remove line"
                            disabled={lines.length === 1}
                            onClick={() =>
                                onChange(lines.filter((_, j) => j !== i))
                            }
                            className="sm:col-span-1"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    {specification && (
                        <Input
                            aria-label="Specification"
                            placeholder="Specification (optional)"
                            value={line.specification ?? ''}
                            onChange={(e) =>
                                set(i, { specification: e.target.value })
                            }
                        />
                    )}
                    {Object.entries(errors)
                        .filter(([key]) => key.startsWith(`lines.${i}.`))
                        .map(([key, message]) => (
                            <p key={key} className="text-xs text-destructive">
                                {message}
                            </p>
                        ))}
                </div>
            ))}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...lines, blankLine()])}
                >
                    <Plus /> Add line
                </Button>
                <p className="text-sm">
                    Estimate:{' '}
                    <span className="font-semibold tabular-nums">
                        {currency} {amount(total.toFixed(2))}
                    </span>
                </p>
            </div>
            {errors.lines && (
                <p className="text-xs text-destructive">{errors.lines}</p>
            )}
        </div>
    );
}
