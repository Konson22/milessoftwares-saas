import { Form, Head, Link, usePage } from '@inertiajs/react';
import { UtensilsCrossed } from 'lucide-react';
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
import { create, edit, index } from '@/routes/menu';

type MenuItem = {
    id: string;
    kind: 'dish' | 'drink';
    name: string;
    section: string | null;
    unit: string;
    barcode: string | null;
    priceMinor: number;
    reorderLevel: number;
    active: boolean;
};

type Props = {
    items: MenuItem[];
    filters: { search: string; section: string; inactive: boolean };
    sections: string[];
    canManage: boolean;
};

/**
 * The restaurant's menu, shared by every branch.
 */
export default function Menu({ items, filters, sections, canManage }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>Add to the menu</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Menu" />
            <PageHeader
                title="Menu"
                description="Dishes are made to order; drinks are kept in stock."
                actions={add}
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
                            placeholder="Name or barcode"
                            aria-label="Search the menu"
                            className="max-w-sm"
                        />
                        {sections.length > 0 && (
                            <select
                                name="section"
                                defaultValue={filters.section}
                                aria-label="Section"
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            >
                                <option value="">All sections</option>
                                {sections.map((section) => (
                                    <option key={section} value={section}>
                                        {section}
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
                            Include items off the menu
                        </label>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {items.length === 0 ? (
                    <EmptyState
                        icon={UtensilsCrossed}
                        title="Nothing on the menu yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead>Section</TableHead>
                                <TableHead>Kind</TableHead>
                                <TableHead className="text-right">
                                    Price
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell>
                                        {canManage ? (
                                            <Link
                                                href={edit(item.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {item.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {item.name}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {item.section ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                item.kind === 'drink'
                                                    ? 'info'
                                                    : 'neutral'
                                            }
                                        >
                                            {item.kind === 'drink'
                                                ? 'Drink'
                                                : 'Dish'}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(item.priceMinor)} / {item.unit}
                                    </TableCell>
                                    <TableCell>
                                        {!item.active && (
                                            <StatusBadge>
                                                Off the menu
                                            </StatusBadge>
                                        )}
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
