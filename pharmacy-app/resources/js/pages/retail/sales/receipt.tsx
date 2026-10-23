import { Head, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { formatMoney } from '@/lib/money';

type Props = {
    sale: {
        id: string;
        number: string;
        status: string;
        soldAt: string;
        soldBy: string;
        customerName: string | null;
        totalMinor: number;
        discountMinor: number;
        paymentMethod: string;
        paymentReference: string | null;
        tenderedMinor: number | null;
        changeMinor: number | null;
        lines: {
            item: string;
            /** Sold by the pack: "box of 30"; quantity is then packs. */
            pack: string | null;
            quantity: number;
            unitPriceMinor: number;
            discountMinor: number;
            lineTotalMinor: number;
        }[];
    };
};

/**
 * The customer's receipt, sized for an 80 mm receipt printer. Printing
 * shows only the receipt, not the shop around it; it opens the print
 * dialog by itself.
 */
export default function Receipt({ sale }: Props) {
    const { workspace, branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    useEffect(() => {
        window.print();
    }, []);

    return (
        <>
            <Head title={`Receipt ${sale.number}`} />
            <style>{`
                @page { size: 80mm auto; margin: 4mm; }
                @media print {
                    body * { visibility: hidden; }
                    #receipt, #receipt * { visibility: visible; }
                    #receipt { position: absolute; inset: 0 auto auto 0; width: 72mm; }
                }
            `}</style>

            <div className="mb-4 flex justify-center print:hidden">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => window.print()}
                >
                    <Printer className="size-4" aria-hidden />
                    Print again
                </Button>
            </div>

            <article
                id="receipt"
                className="mx-auto w-[72mm] bg-white p-3 font-mono text-xs text-black"
            >
                <header className="text-center">
                    <p className="text-sm font-bold">{workspace?.name}</p>
                    <p>{branch?.name}</p>
                    <p className="mt-2 font-bold">Receipt {sale.number}</p>
                    <p>{new Date(sale.soldAt).toLocaleString()}</p>
                    <p>Served by {sale.soldBy}</p>
                    {sale.customerName && <p>Customer: {sale.customerName}</p>}
                    {sale.status === 'voided' && (
                        <p className="mt-1 font-bold">VOIDED</p>
                    )}
                </header>

                <table className="mt-3 w-full border-y border-dashed border-black">
                    <tbody>
                        {sale.lines.map((line) => (
                            <tr key={line.item} className="align-top">
                                <td className="py-1">
                                    {line.item}
                                    <span className="block">
                                        {line.quantity}
                                        {line.pack && ` ${line.pack}`} ×{' '}
                                        {money(line.unitPriceMinor)}
                                        {line.discountMinor > 0 &&
                                            ` less ${money(line.discountMinor)}`}
                                    </span>
                                </td>
                                <td className="py-1 text-right">
                                    {money(line.lineTotalMinor)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-2">
                    {sale.discountMinor > 0 && (
                        <>
                            <dt>Discount</dt>
                            <dd className="text-right">
                                −{money(sale.discountMinor)}
                            </dd>
                        </>
                    )}
                    <dt className="font-bold">Total</dt>
                    <dd className="text-right font-bold">
                        {money(sale.totalMinor)}
                    </dd>
                    <dt>
                        {sale.paymentMethod}
                        {sale.paymentReference && ` (${sale.paymentReference})`}
                    </dt>
                    <dd className="text-right">
                        {money(sale.tenderedMinor ?? sale.totalMinor)}
                    </dd>
                    {sale.changeMinor !== null && (
                        <>
                            <dt>Change</dt>
                            <dd className="text-right">
                                {money(sale.changeMinor)}
                            </dd>
                        </>
                    )}
                </dl>

                <p className="mt-3 text-center">Thank you. Get well soon.</p>
            </article>
        </>
    );
}
