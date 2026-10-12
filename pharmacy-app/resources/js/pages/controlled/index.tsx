import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/controlled';

type Entry = {
    id: string;
    at: string;
    type: string;
    quantity: number;
    balance: number;
    batchNumber: string;
    by: string;
    document: string | null;
    supplier: string | null;
    prescription: string | null;
    patient: string | null;
    prescriber: string | null;
    reason: string | null;
};

type Props = {
    medicines: { id: string; label: string }[];
    selected: string | null;
    entries: Entry[];
};

const TYPES: Record<string, string> = {
    opening: 'Opening stock',
    receipt: 'Received',
    sale: 'Dispensed',
    sale_void: 'Returned (sale voided)',
    adjustment: 'Adjusted',
};

/**
 * The controlled drugs register: every movement of one controlled medicine
 * in this branch, newest first, with the balance after it.
 */
export default function ControlledRegister({
    medicines,
    selected,
    entries,
}: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Controlled drugs register" />
            <h1 className="text-2xl font-semibold">
                Controlled drugs register, {branch?.name}
            </h1>

            {medicines.length === 0 ? (
                <p className="mt-6 text-muted-foreground">
                    No medicine is marked as controlled.
                </p>
            ) : (
                <>
                    <nav
                        aria-label="Controlled medicines"
                        className="mt-6 flex flex-wrap gap-2"
                    >
                        {medicines.map((medicine) => (
                            <Button
                                key={medicine.id}
                                size="sm"
                                variant={
                                    medicine.id === selected
                                        ? 'default'
                                        : 'outline'
                                }
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: { medicine: medicine.id },
                                    })}
                                >
                                    {medicine.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>

                    <div className="mt-4 overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        When
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        What
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        In / out
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Balance
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Batch
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Document
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        For / from
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        By
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {entries.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            No movements in this branch.
                                        </td>
                                    </tr>
                                )}
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            {new Date(
                                                entry.at,
                                            ).toLocaleString()}
                                        </td>
                                        <td className="px-3 py-2">
                                            {TYPES[entry.type] ?? entry.type}
                                            {entry.reason && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.reason}
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={`px-3 py-2 text-right tabular-nums ${entry.quantity < 0 ? 'text-destructive' : ''}`}
                                        >
                                            {entry.quantity > 0 ? '+' : ''}
                                            {entry.quantity}
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium tabular-nums">
                                            {entry.balance}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.batchNumber}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.document}
                                            {entry.prescription && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.prescription}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.patient ?? entry.supplier}
                                            {entry.prescriber && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.prescriber}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.by}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </>
    );
}
