import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { Barcode } from '@/components/barcode';
import { formatMoney } from '@/lib/money';
import { show as showOrder } from '@/routes/orders';
import { store as logPrint } from '@/routes/printed-documents';
import { show as showSale } from '@/routes/sales';

type Props = {
    document: {
        type: 'receipt' | 'order_slip' | 'collection_slip';
        referenceId: string;
        title: string;
        number: string;
        orderNumber: string | null;
        receiptNumber?: string | null;
        barcode: string;
        at: string;
        staff: { role: string; name: string }[];
        customerName: string | null;
        customerPhone: string | null;
        /** Whether prices appear (slips follow the branch's setting). */
        prices: boolean;
        lines: {
            name: string;
            /** Sold by the pack: "box of 30"; quantity is then packs. */
            pack: string | null;
            quantity: number;
            unitPriceMinor: number | null;
            discountMinor: number;
            lineTotalMinor: number;
        }[];
        totalMinor: number;
        discountMinor: number;
        payment: {
            method: string;
            reference: string | null;
            tenderedMinor: number | null;
            changeMinor: number | null;
        } | null;
        /** What the customer does next with a slip. */
        instruction: string | null;
        voided: boolean;
    };
    /** How many times it was printed; past 1, the paper says it is a copy. */
    copies: number;
    /** This page claimed the original: it prints by itself. */
    autoPrint: boolean;
    layout: {
        paperSize: '58mm' | '80mm' | 'a4';
        printerName: string | null;
        header: string | null;
        footer: string | null;
        businessName: string | null;
        address: string | null;
        contact: string | null;
        logo: string | null;
        showCustomer: boolean;
        showStaff: boolean;
        showBarcode: boolean;
    };
};

const PAPER = {
    '58mm': {
        page: '58mm auto',
        margin: '2mm',
        width: '54mm',
        text: 'text-[10px]',
    },
    '80mm': {
        page: '80mm auto',
        margin: '3mm',
        width: '74mm',
        text: 'text-xs',
    },
    a4: { page: 'A4', margin: '15mm', width: '180mm', text: 'text-sm' },
} as const;

/**
 * A counter document (receipt, order slip, collection slip) laid out for
 * the branch's paper. Printing shows only the document. It prints by itself
 * only when it is the original being printed after a step; otherwise each
 * print is logged first (a numbered copy), then printed. Printing never
 * changes the sale or the order.
 */
export default function PrintDocument({
    document: doc,
    copies,
    autoPrint,
    layout,
}: Props) {
    const { workspace, branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const paper = PAPER[layout.paperSize];
    const [printing, setPrinting] = useState(false);
    const printed = useRef(false);

    useEffect(() => {
        if (autoPrint && !printed.current) {
            printed.current = true;
            window.print();
        }
    }, [autoPrint]);

    const print = () =>
        router.post(
            logPrint(),
            { document: doc.type, reference_id: doc.referenceId },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setPrinting(true),
                onFinish: () => setPrinting(false),
                // The copy number is on the page before the paper comes out.
                onSuccess: () => window.setTimeout(() => window.print(), 50),
            },
        );

    const back =
        doc.type === 'receipt'
            ? showSale(doc.referenceId)
            : showOrder(doc.referenceId);

    return (
        <>
            <Head title={`${doc.title} ${doc.number}`} />
            <style>{`
                @page { size: ${paper.page}; margin: ${paper.margin}; }
                @media print { html, body { background: #fff; } }
            `}</style>

            <div className="mx-auto flex max-w-xl flex-wrap items-center justify-center gap-2 p-4 print:hidden">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={back}>
                        <ArrowLeft className="size-4" aria-hidden />
                        Back
                    </Link>
                </Button>
                <Button size="sm" onClick={print} disabled={printing}>
                    <Printer className="size-4" aria-hidden />
                    {copies === 0 ? 'Print' : 'Reprint'}
                </Button>
                <p className="w-full text-center text-sm text-muted-foreground">
                    {copies === 0
                        ? 'Not printed yet.'
                        : `Printed ${copies} time${copies === 1 ? '' : 's'}; a reprint is marked as a copy.`}
                    {layout.printerName && ` Print to ${layout.printerName}.`}
                </p>
            </div>

            <article
                id="document"
                style={{ width: paper.width }}
                className={`mx-auto bg-white p-2 font-mono text-black print:p-0 ${paper.text}`}
            >
                <header className="text-center">
                    {layout.logo && (
                        <img
                            src={layout.logo}
                            alt=""
                            className="mx-auto mb-1 max-h-14 object-contain"
                        />
                    )}
                    <p className="font-bold">
                        {layout.businessName ?? workspace?.name}
                    </p>
                    <p>{branch?.name}</p>
                    {layout.address && (
                        <p className="whitespace-pre-line">{layout.address}</p>
                    )}
                    {layout.contact && <p>{layout.contact}</p>}
                    {layout.header && (
                        <p className="mt-1 whitespace-pre-line">
                            {layout.header}
                        </p>
                    )}

                    <p className="mt-2 font-bold uppercase">
                        {doc.title} {doc.number}
                    </p>
                    {copies > 1 && (
                        <p className="font-bold">REPRINT · COPY {copies}</p>
                    )}
                    {doc.voided && <p className="font-bold">CANCELLED</p>}
                    <p>{new Date(doc.at).toLocaleString()}</p>
                    {doc.orderNumber && doc.orderNumber !== doc.number && (
                        <p>Order {doc.orderNumber}</p>
                    )}
                    {doc.receiptNumber && <p>Receipt {doc.receiptNumber}</p>}
                    {layout.showStaff &&
                        doc.staff.map((person) => (
                            <p key={person.role}>
                                {person.role}: {person.name}
                            </p>
                        ))}
                    {layout.showCustomer && (
                        <p>
                            Customer: {doc.customerName ?? 'Walk-in Customer'}
                            {doc.customerPhone && ` · ${doc.customerPhone}`}
                        </p>
                    )}
                </header>

                <table className="mt-2 w-full border-y border-dashed border-black">
                    <tbody>
                        {doc.lines.map((line, i) => (
                            <tr key={i} className="align-top">
                                <td className="py-0.5">
                                    {line.name}
                                    <span className="block">
                                        {line.quantity}
                                        {line.pack && ` ${line.pack}`}
                                        {doc.prices &&
                                            line.unitPriceMinor !== null &&
                                            ` × ${money(line.unitPriceMinor)}`}
                                        {doc.prices &&
                                            line.discountMinor > 0 &&
                                            ` less ${money(line.discountMinor)}`}
                                    </span>
                                </td>
                                {doc.prices && (
                                    <td className="py-0.5 text-right">
                                        {money(line.lineTotalMinor)}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>

                {doc.prices && (
                    <dl className="mt-1 grid grid-cols-[1fr_auto] gap-x-2">
                        {doc.discountMinor > 0 && (
                            <>
                                <dt>Discount</dt>
                                <dd className="text-right">
                                    −{money(doc.discountMinor)}
                                </dd>
                            </>
                        )}
                        <dt className="font-bold">Total</dt>
                        <dd className="text-right font-bold">
                            {money(doc.totalMinor)}
                        </dd>
                        {doc.payment && (
                            <>
                                <dt>
                                    {doc.payment.method}
                                    {doc.payment.reference &&
                                        ` (${doc.payment.reference})`}
                                </dt>
                                <dd className="text-right">
                                    {money(
                                        doc.payment.tenderedMinor ??
                                            doc.totalMinor,
                                    )}
                                </dd>
                                {doc.payment.changeMinor !== null && (
                                    <>
                                        <dt>Change</dt>
                                        <dd className="text-right">
                                            {money(doc.payment.changeMinor)}
                                        </dd>
                                    </>
                                )}
                            </>
                        )}
                    </dl>
                )}

                {doc.instruction && (
                    <p className="mt-2 text-center font-bold">
                        {doc.instruction}
                    </p>
                )}

                {layout.showBarcode && (
                    <Barcode value={doc.barcode} className="mt-2" />
                )}

                <p className="mt-2 text-center whitespace-pre-line">
                    {layout.footer ?? 'Thank you. Get well soon.'}
                </p>
            </article>
        </>
    );
}
