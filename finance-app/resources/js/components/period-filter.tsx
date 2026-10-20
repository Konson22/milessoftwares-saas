import { Form } from '@inertiajs/react';
import { Select } from '@/components/field';
import type { RouteFormDefinition } from '@/wayfinder';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';

/**
 * From/to dates (or a single "as of" date) for lists and statements.
 */
export function PeriodFilter({
    form,
    from,
    to,
    asOf,
    compare,
    children,
}: {
    form: RouteFormDefinition<'get'>;
    from?: string;
    to?: string;
    asOf?: string;
    /** A "compare with" choice for statements. */
    compare?: {
        value: string | null;
        options: { value: string; label: string }[];
    };
    children?: React.ReactNode;
}) {
    return (
        <Form
            {...form}
            options={{ preserveState: true }}
            className="flex flex-wrap items-end gap-2 print:hidden"
        >
            {({ errors }) => (
                <>
                    {asOf !== undefined ? (
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            As of
                            <Input
                                name="as_of"
                                type="date"
                                defaultValue={asOf}
                                className="w-40"
                            />
                        </label>
                    ) : (
                        <>
                            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                From
                                <Input
                                    name="from"
                                    type="date"
                                    defaultValue={from}
                                    className="w-40"
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                To
                                <Input
                                    name="to"
                                    type="date"
                                    defaultValue={to}
                                    className="w-40"
                                />
                            </label>
                        </>
                    )}
                    {compare && (
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            Compare with
                            <Select
                                name="compare"
                                defaultValue={compare.value ?? ''}
                                className="w-44"
                            >
                                <option value="">Nothing</option>
                                {compare.options.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    )}
                    {children}
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                    {(errors.from || errors.to || errors.as_of) && (
                        <p className="w-full text-sm text-destructive">
                            {errors.from ?? errors.to ?? errors.as_of}
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}
