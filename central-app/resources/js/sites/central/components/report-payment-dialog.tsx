import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/shared/components/ui/dialog';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { store as storePayment } from '@/routes/organization/invoices/payments';
import { formatMoney, methodLabel } from '@/shared/lib/billing';

type Invoice = {
    id: string;
    number: string;
    currency: string;
    balanceMinor: number;
};

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * The owner tells us about a payment made outside the platform. It shows
 * as awaiting confirmation until staff find it and confirm it.
 */
export function ReportPaymentDialog({
    invoice,
    methods,
    size = 'sm',
}: {
    invoice: Invoice;
    methods: string[];
    size?: 'sm' | 'default';
}) {
    const [open, setOpen] = useState(false);
    const today = new Date().toLocaleDateString('en-CA');
    const id = (field: string) => `${field}-${invoice.id}`;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size={size}>Report a payment</Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...storePayment.form(invoice.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Report a payment for {invoice.number}
                                </DialogTitle>
                                <DialogDescription>
                                    {formatMoney(
                                        invoice.balanceMinor,
                                        invoice.currency,
                                    )}{' '}
                                    is still owed. We confirm payments once they
                                    reach our account.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor={id('method')}>
                                        Paid by
                                    </Label>
                                    <select
                                        id={id('method')}
                                        name="method"
                                        required
                                        defaultValue="bank_transfer"
                                        className={fieldClass}
                                    >
                                        {methods.map((method) => (
                                            <option key={method} value={method}>
                                                {methodLabel[method] ?? method}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.method} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={id('amount')}>
                                        Amount ({invoice.currency})
                                    </Label>
                                    <Input
                                        id={id('amount')}
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                        pattern="\d+(\.\d{1,2})?"
                                        defaultValue={(
                                            invoice.balanceMinor / 100
                                        ).toFixed(2)}
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={id('reference')}>
                                        Transaction reference
                                    </Label>
                                    <Input
                                        id={id('reference')}
                                        name="reference"
                                        required
                                        maxLength={120}
                                        placeholder="e.g. bank or mobile money ID"
                                    />
                                    <InputError message={errors.reference} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={id('paid_on')}>
                                        Date paid
                                    </Label>
                                    <Input
                                        id={id('paid_on')}
                                        name="paid_on"
                                        type="date"
                                        required
                                        max={today}
                                        defaultValue={today}
                                    />
                                    <InputError message={errors.paid_on} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={id('note')}>
                                    Note (optional)
                                </Label>
                                <Input
                                    id={id('note')}
                                    name="note"
                                    maxLength={1000}
                                />
                                <InputError message={errors.note} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Send
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
