import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Pill } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { create, edit, index } from '@/routes/medicines';

type Medicine = {
    id: string;
    name: string;
    genericName: string;
    form: string;
    strength: string | null;
    unit: string;
    category: string | null;
    storage: string;
    storageLabel: string;
    prescriptionOnly: boolean;
    controlled: boolean;
    sellPriceMinor: number;
    active: boolean;
};

type Props = {
    medicines: Medicine[];
    filters: { search: string; category: string; inactive: boolean };
    categories: string[];
    canManage: boolean;
};

export default function Medicines({
    medicines,
    filters,
    categories,
    canManage,
}: Props) {
    const { currency } = usePage().props;

    return (
        <>
            <Head title="Medicines" />
            <PageHeader
                title="Medicines"
                actions={
                    canManage ? (
                        <Button asChild>
                            <Link href={create()}>Add medicine</Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, generic name or barcode"
                            aria-label="Search medicines"
                            className="max-w-sm"
                        />
                        {categories.length > 0 && (
                            <select
                                name="category"
                                defaultValue={filters.category}
                                aria-label="Category"
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            >
                                <option value="">All categories</option>
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category}
                                    </option>
                                ))}
                            </select>
                        )}
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
                </PanelToolbar>

                {medicines.length === 0 ? (
                    <EmptyState
                        icon={Pill}
                        title="No medicines yet"
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={create()}>Add medicine</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Medicine</TableHead>
                                <TableHead>Generic</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">
                                    Price
                                </TableHead>
                                <TableHead>Flags</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {medicines.map((medicine) => (
                                <TableRow key={medicine.id}>
                                    <TableCell>
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
                                        <span className="text-muted-foreground">
                                            {medicine.strength} {medicine.form}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {medicine.genericName}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {medicine.category ?? '–'}
                                    </TableCell>
                                    <TableCell>{medicine.unit}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            medicine.sellPriceMinor,
                                            currency ?? 'USD',
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {medicine.controlled && (
                                                <StatusBadge tone="danger">
                                                    Controlled
                                                </StatusBadge>
                                            )}
                                            {medicine.prescriptionOnly &&
                                                !medicine.controlled && (
                                                    <StatusBadge tone="info">
                                                        Rx
                                                    </StatusBadge>
                                                )}
                                            {medicine.storage !== 'room' && (
                                                <StatusBadge tone="warning">
                                                    {medicine.storageLabel}
                                                </StatusBadge>
                                            )}
                                            {!medicine.active && (
                                                <StatusBadge tone="neutral">
                                                    Inactive
                                                </StatusBadge>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
