import { Boxes } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Pager } from '@/components/report-bits';

export type Movement = {
    id: string;
    at: string;
    branchId: string;
    branch: string | null;
    itemId: string;
    item: string;
    unit: string;
    type: string;
    quantity: number;
    unitCostMinor: number;
    reason: string | null;
    note: string | null;
    billId: string | null;
    deliveryId: string | null;
    by: string;
};

export type MovementPage = {
    in: number;
    out: number;
    outCostMinor: number;
    data: Movement[];
    currentPage: number;
    lastPage: number;
    total: number;
    prev: string | null;
    next: string | null;
};

export const MOVEMENT_TYPES: Record<string, string> = {
    opening: 'Added',
    delivery: 'Delivered',
    sale: 'Billed',
    bill_void: 'Bill voided',
    count: 'Counted',
    write_off: 'Written off',
};

/**
 * Stock movements, newest first: what moved each drink, by how much, why
 * and who. With showBranch, the branch of each (management).
 */
export function MovementTable({
    page,
    showBranch = false,
    itemHref,
}: {
    page: MovementPage;
    showBranch?: boolean;
    itemHref?: (movement: Movement) => string;
}) {
    if (page.data.length === 0) {
        return (
            <EmptyState icon={Boxes} title="No stock moved in this period" />
        );
    }

    return (
        <>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        {showBranch && <TableHead>Branch</TableHead>}
                        <TableHead>Drink</TableHead>
                        <TableHead>What</TableHead>
                        <TableHead className="text-right">Change</TableHead>
                        <TableHead>Why</TableHead>
                        <TableHead>By</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {page.data.map((movement) => (
                        <TableRow key={movement.id}>
                            <TableCell className="whitespace-nowrap text-muted-foreground tabular-nums">
                                {new Date(movement.at).toLocaleString()}
                            </TableCell>
                            {showBranch && (
                                <TableCell>{movement.branch}</TableCell>
                            )}
                            <TableCell className="font-medium">
                                {itemHref ? (
                                    <a
                                        href={itemHref(movement)}
                                        className="hover:underline"
                                    >
                                        {movement.item}
                                    </a>
                                ) : (
                                    movement.item
                                )}
                            </TableCell>
                            <TableCell>
                                {MOVEMENT_TYPES[movement.type] ?? movement.type}
                            </TableCell>
                            <TableCell
                                className={`text-right font-medium tabular-nums ${movement.quantity < 0 ? 'text-red-700' : 'text-green-700'}`}
                            >
                                {movement.quantity > 0 ? '+' : ''}
                                {movement.quantity}
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {[movement.reason, movement.note]
                                    .filter(Boolean)
                                    .join(' · ') || '—'}
                            </TableCell>
                            <TableCell>{movement.by}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <Pager {...page} />
        </>
    );
}
