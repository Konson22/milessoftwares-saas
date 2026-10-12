import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/stock';

type Row = {
    id: string;
    label: string;
    unit: string;
    sellable: number;
    expired: number;
    nextExpiry: string | null;
    low: boolean;
    expiringSoon: boolean;
    reorderLevel: number;
};

type Props = {
    rows: Row[];
    filters: { search: string; filter: string };
    expiringDays: number;
};

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'low', label: 'Low stock' },
    { value: 'expiring', label: 'Expiring soon' },
    { value: 'expired', label: 'Expired' },
];

export default function StockIndex({ rows, filters, expiringDays }: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Stock" />
            <h1 className="text-2xl font-semibold">Stock in {branch?.name}</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Sellable stock excludes expired batches. Expiring soon: within{' '}
                {expiringDays} days.
            </p>

            <nav
                aria-label="Stock filters"
                className="mt-6 flex flex-wrap gap-2"
            >
                {FILTERS.map((option) => (
                    <Button
                        key={option.value}
                        size="sm"
                        variant={
                            filters.filter === option.value
                                ? 'default'
                                : 'outline'
                        }
                        asChild
                    >
                        <Link
                            href={index({
                                query: {
                                    filter: option.value,
                                    search: filters.search || undefined,
                                },
                            })}
                            preserveState
                        >
                            {option.label}
                        </Link>
                    </Button>
                ))}
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="ml-auto flex gap-2"
                >
                    <input type="hidden" name="filter" value={filters.filter} />
                    <Input
                        name="search"
                        defaultValue={filters.search}
                        placeholder="Search medicines"
                        aria-label="Search medicines"
                        className="h-8 w-56"
                    />
                </Form>
            </nav>

            <div className="mt-4 overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Medicine</th>
                            <th className="px-4 py-2 text-right font-medium">
                                Sellable
                            </th>
                            <th className="px-4 py-2 font-medium">
                                Next expiry
                            </th>
                            <th className="px-4 py-2 font-medium">
                                <span className="sr-only">Status</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-4 py-6 text-center text-muted-foreground"
                                >
                                    Nothing to show.
                                </td>
                            </tr>
                        )}
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2">
                                    <Link
                                        href={show(row.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {row.label}
                                    </Link>
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {row.sellable} {row.unit}
                                </td>
                                <td className="px-4 py-2 tabular-nums">
                                    {row.nextExpiry ?? '—'}
                                </td>
                                <td className="space-x-1 px-4 py-2">
                                    {row.low && (
                                        <Badge variant="destructive">
                                            Low (reorder at {row.reorderLevel})
                                        </Badge>
                                    )}
                                    {row.expiringSoon && (
                                        <Badge variant="secondary">
                                            Expiring soon
                                        </Badge>
                                    )}
                                    {row.expired > 0 && (
                                        <Badge variant="outline">
                                            {row.expired} expired
                                        </Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
