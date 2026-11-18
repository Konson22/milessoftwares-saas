import { Form, router } from '@inertiajs/react';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Label } from './ui/label';
import { useMoney } from './money-input';

/**
 * The currencies people may type amounts in, and their rates against the
 * workspace currency. Posts to `{action}` and deletes `{action}/{code}`.
 */
export function CurrencyRates({
    action,
    canManage = true,
}: {
    action: string;
    canManage?: boolean;
}) {
    const money = useMoney();
    if (!money) {
        return null;
    }

    const others = money.currencies.slice(1);

    return (
        <div className="grid gap-4">
            <p className="text-sm text-muted-foreground">
                Amounts are kept in {money.base}. When someone types an amount
                in another currency it is converted at the rate below, and the
                record keeps what was typed and the rate it used. Changing a
                rate does not change amounts already saved.
            </p>
            <div className="divide-y border">
                <div className="flex items-center justify-between px-3 py-2 text-sm">
                    <span className="font-medium">{money.base}</span>
                    <span className="text-muted-foreground">
                        Workspace currency
                    </span>
                </div>
                {others.map((currency) => (
                    <div
                        key={currency.code}
                        className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                    >
                        <span className="font-medium">{currency.code}</span>
                        <span className="tabular-nums">
                            1 {currency.code} = {money.base} {currency.rate}
                        </span>
                        {canManage && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.delete(
                                        `${action}/${currency.code}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                ))}
                {others.length === 0 && (
                    <p className="px-3 py-2 text-sm text-muted-foreground">
                        No other currencies yet. Amounts can only be typed in{' '}
                        {money.base}.
                    </p>
                )}
            </div>
            {canManage && (
                <Form
                    action={action}
                    method="post"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="entry-currency-code">
                                    Currency
                                </Label>
                                <Input
                                    id="entry-currency-code"
                                    name="code"
                                    placeholder="USD"
                                    maxLength={3}
                                    className="w-24 uppercase"
                                    required
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="entry-currency-rate">
                                    {money.base} for one unit
                                </Label>
                                <Input
                                    id="entry-currency-rate"
                                    name="rate"
                                    inputMode="decimal"
                                    className="w-40"
                                    required
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Save rate
                            </Button>
                            {(errors.code || errors.rate) && (
                                <p className="basis-full text-sm text-destructive">
                                    {errors.code ?? errors.rate}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
