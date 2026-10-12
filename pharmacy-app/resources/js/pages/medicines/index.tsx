import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import { create, edit, index } from '@/routes/medicines';

type Medicine = {
    id: string;
    name: string;
    genericName: string;
    form: string;
    strength: string | null;
    unit: string;
    prescriptionOnly: boolean;
    controlled: boolean;
    sellPriceMinor: number;
    active: boolean;
};

type Props = {
    medicines: Medicine[];
    filters: { search: string; inactive: boolean };
    canManage: boolean;
};

export default function Medicines({ medicines, filters, canManage }: Props) {
    const { currency } = usePage().props;

    return (
        <>
            <Head title="Medicines" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">Medicines</h1>
                {canManage && (
                    <Button asChild>
                        <Link href={create()}>Add medicine</Link>
                    </Button>
                )}
            </div>

            <Form
                {...index.form()}
                options={{ preserveState: true }}
                className="mt-6 flex flex-wrap items-center gap-3"
            >
                <Input
                    name="search"
                    defaultValue={filters.search}
                    placeholder="Name, generic name or barcode"
                    aria-label="Search medicines"
                    className="max-w-xs"
                />
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="inactive"
                        value="1"
                        defaultChecked={filters.inactive}
                    />
                    Include inactive
                </label>
                <Button type="submit" variant="outline">
                    Search
                </Button>
            </Form>

            <div className="mt-6 overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Medicine</th>
                            <th className="px-4 py-2 font-medium">Generic</th>
                            <th className="px-4 py-2 font-medium">Unit</th>
                            <th className="px-4 py-2 text-right font-medium">
                                Price
                            </th>
                            <th className="px-4 py-2 font-medium">
                                <span className="sr-only">Flags</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {medicines.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No medicines yet.
                                </td>
                            </tr>
                        )}
                        {medicines.map((medicine) => (
                            <tr key={medicine.id}>
                                <td className="px-4 py-2">
                                    {canManage ? (
                                        <Link
                                            href={edit(medicine.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {medicine.name}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">
                                            {medicine.name}
                                        </span>
                                    )}{' '}
                                    {medicine.strength} {medicine.form}
                                </td>
                                <td className="px-4 py-2 text-muted-foreground">
                                    {medicine.genericName}
                                </td>
                                <td className="px-4 py-2">{medicine.unit}</td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {formatMoney(
                                        medicine.sellPriceMinor,
                                        currency ?? 'USD',
                                    )}
                                </td>
                                <td className="space-x-1 px-4 py-2">
                                    {medicine.controlled && (
                                        <Badge variant="destructive">
                                            Controlled
                                        </Badge>
                                    )}
                                    {medicine.prescriptionOnly &&
                                        !medicine.controlled && (
                                            <Badge variant="secondary">
                                                Rx
                                            </Badge>
                                        )}
                                    {!medicine.active && (
                                        <Badge variant="outline">
                                            Inactive
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
