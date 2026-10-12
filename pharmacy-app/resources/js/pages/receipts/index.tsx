import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { create, show } from '@/routes/receipts';

type Receipt = {
    id: string;
    number: string;
    supplier: string;
    order: string | null;
    supplierReference: string | null;
    receivedOn: string;
    totalMinor: number;
};

export default function Receipts({ receipts }: { receipts: Receipt[] }) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();

    return (
        <>
            <Head title="Goods received" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">
                    Goods received, {branch?.name}
                </h1>
                {canHere('pharmacy.purchasing.receive') && (
                    <Button asChild>
                        <Link href={create()}>Receive without an order</Link>
                    </Button>
                )}
            </div>

            <ul className="mt-6 divide-y rounded-md border">
                {receipts.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        Nothing received yet.
                    </li>
                )}
                {receipts.map((receipt) => (
                    <li
                        key={receipt.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <Link
                            href={show(receipt.id)}
                            className="font-medium hover:underline"
                        >
                            {receipt.number}
                        </Link>
                        <span>{receipt.supplier}</span>
                        <span className="text-muted-foreground">
                            {receipt.receivedOn}
                            {receipt.order && ` · ${receipt.order}`}
                            {receipt.supplierReference &&
                                ` · ${receipt.supplierReference}`}
                        </span>
                        <span className="ml-auto tabular-nums">
                            {formatMoney(receipt.totalMinor, currency ?? 'USD')}
                        </span>
                    </li>
                ))}
            </ul>
        </>
    );
}
