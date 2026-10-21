import { Form, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { update } from '@/routes/counter-settings';

type Settings = {
    workflow: string;
    directPayment: 'paid' | 'unpaid' | 'choose';
    shiftsRequired: boolean;
    defaultFloat: string;
    blindClose: boolean;
    managerClosesShifts: boolean;
    requireCustomerAlways: boolean;
    requireCustomerForRestricted: boolean;
    requireCustomerAbove: string;
    printReceiptAfterPayment: boolean;
    printOrderSlip: boolean;
    printCollectionSlip: boolean;
    printReceiptAfterDispensing: boolean;
    printerName: string;
    paperSize: string;
    receiptHeader: string;
    receiptFooter: string;
    businessName: string;
    address: string;
    contact: string;
    logo: string | null;
    showLogo: boolean;
    showAddress: boolean;
    showContact: boolean;
    showCustomer: boolean;
    showStaff: boolean;
    showBarcode: boolean;
    slipPrices: boolean;
};

type Props = {
    settings: Settings;
    workflows: { value: string; label: string; description: string }[];
    paperSizes: string[];
};

const DIRECT_PAYMENTS = [
    {
        value: 'paid',
        label: 'Paid at the counter',
        description: 'The seller takes the money and completes the sale.',
    },
    {
        value: 'unpaid',
        label: 'Unpaid, paid at the cashier',
        description:
            'The seller hands the goods over; the cashier takes payment from the order queue.',
    },
    {
        value: 'choose',
        label: 'The seller chooses each time',
        description: 'Paid or unpaid, sale by sale.',
    },
] as const;

/** A yes/no setting, sent as 0 or 1. */
function Toggle({
    name,
    checked,
    children,
}: {
    name: string;
    checked: boolean;
    children: ReactNode;
}) {
    return (
        <label className="flex items-start gap-2 text-sm">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={checked}
                className="mt-0.5"
            />
            <span>{children}</span>
        </label>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

/**
 * How this branch's counter works: its workflow, cash shifts, when the
 * customer must be named, and what prints, on which paper, with what on it. Nothing prints
 * and nobody has to be named until the branch says so.
 */
export default function CounterSettings({
    settings,
    workflows,
    paperSizes,
}: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Counter settings" />
            <PageHeader title="Counter settings" description={branch?.name} />

            <Form
                {...update.form()}
                options={{ preserveScroll: true }}
                className="grid max-w-3xl gap-3"
            >
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader
                                title="Workflow"
                                description="How a basket becomes a sale here"
                            />
                            <PanelBody className="grid gap-2">
                                {workflows.map((workflow) => (
                                    <label
                                        key={workflow.value}
                                        className="flex items-start gap-2 rounded-md border p-3 text-sm has-checked:border-primary"
                                    >
                                        <input
                                            type="radio"
                                            name="workflow"
                                            value={workflow.value}
                                            defaultChecked={
                                                settings.workflow ===
                                                workflow.value
                                            }
                                            className="mt-0.5"
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {workflow.label}
                                            </span>
                                            <span className="block text-muted-foreground">
                                                {workflow.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                {errors.workflow && (
                                    <p className="text-sm text-destructive">
                                        {errors.workflow}
                                    </p>
                                )}

                                <fieldset className="mt-2 grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Payment on a direct sale
                                    </legend>
                                    {DIRECT_PAYMENTS.map((option) => (
                                        <label
                                            key={option.value}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <input
                                                type="radio"
                                                name="direct_payment"
                                                value={option.value}
                                                defaultChecked={
                                                    settings.directPayment ===
                                                    option.value
                                                }
                                                className="mt-0.5"
                                            />
                                            <span>
                                                <span className="font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="block text-muted-foreground">
                                                    {option.description}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </fieldset>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Shifts"
                                description="Each cashier starts a shift with the cash in the drawer and counts it at the end"
                            />
                            <PanelBody className="grid gap-3">
                                <Toggle
                                    name="shifts_required"
                                    checked={settings.shiftsRequired}
                                >
                                    Take money only in an open shift
                                </Toggle>
                                <Field
                                    id="default_float"
                                    label="Usual starting cash (prefilled when a shift starts)"
                                    error={errors.default_float}
                                >
                                    <Input
                                        id="default_float"
                                        name="default_float"
                                        inputMode="decimal"
                                        defaultValue={settings.defaultFloat}
                                        className="w-44"
                                    />
                                </Field>
                                <Toggle
                                    name="blind_close"
                                    checked={settings.blindClose}
                                >
                                    Blind count: cashiers count the cash without
                                    seeing what the drawer should hold (managers
                                    see the difference)
                                </Toggle>
                                <Toggle
                                    name="manager_closes_shifts"
                                    checked={settings.managerClosesShifts}
                                >
                                    Only managers count and close shifts
                                </Toggle>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Customer details"
                                description="Optional unless you ask for them; a walk-in sale is never held up otherwise"
                            />
                            <PanelBody className="grid gap-3">
                                <Toggle
                                    name="require_customer_always"
                                    checked={settings.requireCustomerAlways}
                                >
                                    Name the customer on every sale
                                </Toggle>
                                <Toggle
                                    name="require_customer_for_restricted"
                                    checked={
                                        settings.requireCustomerForRestricted
                                    }
                                >
                                    Name the customer when prescription-only
                                    medicines are sold
                                </Toggle>
                                <Field
                                    id="require_customer_above"
                                    label="Name the customer on sales over (leave empty for no limit)"
                                    error={errors.require_customer_above}
                                >
                                    <Input
                                        id="require_customer_above"
                                        name="require_customer_above"
                                        inputMode="decimal"
                                        defaultValue={
                                            settings.requireCustomerAbove
                                        }
                                        className="w-44"
                                    />
                                </Field>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Printing"
                                description="Prints by itself once; anything later is a marked reprint"
                            />
                            <PanelBody className="grid gap-3">
                                <Toggle
                                    name="print_receipt_after_payment"
                                    checked={settings.printReceiptAfterPayment}
                                >
                                    Print the receipt after payment
                                </Toggle>
                                <Toggle
                                    name="print_order_slip"
                                    checked={settings.printOrderSlip}
                                >
                                    Print an order slip when an order is taken
                                </Toggle>
                                <Toggle
                                    name="print_collection_slip"
                                    checked={settings.printCollectionSlip}
                                >
                                    Print a collection slip after payment (order
                                    → payment → collection)
                                </Toggle>
                                <Toggle
                                    name="print_receipt_after_dispensing"
                                    checked={
                                        settings.printReceiptAfterDispensing
                                    }
                                >
                                    Print the receipt after dispensing
                                </Toggle>
                                <Toggle
                                    name="slip_prices"
                                    checked={settings.slipPrices}
                                >
                                    Show prices on order and collection slips
                                </Toggle>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        id="paper_size"
                                        label="Paper"
                                        error={errors.paper_size}
                                    >
                                        <select
                                            id="paper_size"
                                            name="paper_size"
                                            defaultValue={settings.paperSize}
                                            className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                        >
                                            {paperSizes.map((size) => (
                                                <option key={size} value={size}>
                                                    {size === 'a4'
                                                        ? 'A4'
                                                        : `${size} roll`}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="printer_name"
                                        label="Printer (shown when printing)"
                                        error={errors.printer_name}
                                    >
                                        <Input
                                            id="printer_name"
                                            name="printer_name"
                                            defaultValue={settings.printerName}
                                            placeholder="e.g. Counter 1 thermal"
                                        />
                                    </Field>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Documents print through the browser: set
                                    this printer as the default on the counter
                                    computer, so the print dialog picks it.
                                </p>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="On the paper"
                                description="Leave a field empty to leave it off"
                            />
                            <PanelBody className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    id="business_name"
                                    label="Business name (the workspace's when empty)"
                                    error={errors.business_name}
                                >
                                    <Input
                                        id="business_name"
                                        name="business_name"
                                        defaultValue={settings.businessName}
                                    />
                                </Field>
                                <Field
                                    id="contact"
                                    label="Contact"
                                    error={errors.contact}
                                >
                                    <Input
                                        id="contact"
                                        name="contact"
                                        defaultValue={settings.contact}
                                        placeholder="Phone, email"
                                    />
                                </Field>
                                <Field
                                    id="address"
                                    label="Address"
                                    error={errors.address}
                                >
                                    <textarea
                                        id="address"
                                        name="address"
                                        rows={2}
                                        defaultValue={settings.address}
                                        className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    />
                                </Field>
                                <Field
                                    id="receipt_header"
                                    label="Header"
                                    error={errors.receipt_header}
                                >
                                    <textarea
                                        id="receipt_header"
                                        name="receipt_header"
                                        rows={2}
                                        defaultValue={settings.receiptHeader}
                                        className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    />
                                </Field>
                                <Field
                                    id="receipt_footer"
                                    label="Footer"
                                    error={errors.receipt_footer}
                                >
                                    <textarea
                                        id="receipt_footer"
                                        name="receipt_footer"
                                        rows={2}
                                        defaultValue={settings.receiptFooter}
                                        placeholder="Thank you. Get well soon."
                                        className="rounded-md border bg-transparent px-3 py-2 text-sm"
                                    />
                                </Field>
                                <Field
                                    id="logo"
                                    label="Logo (PNG or JPG, up to 256 KB)"
                                    error={errors.logo}
                                >
                                    {settings.logo && (
                                        <img
                                            src={settings.logo}
                                            alt="Current logo"
                                            className="max-h-14 object-contain"
                                        />
                                    )}
                                    <input
                                        id="logo"
                                        name="logo"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="text-sm"
                                    />
                                    {settings.logo && (
                                        <Toggle
                                            name="remove_logo"
                                            checked={false}
                                        >
                                            Remove the logo
                                        </Toggle>
                                    )}
                                </Field>

                                <div className="grid gap-2 sm:col-span-2 sm:grid-cols-2">
                                    <Toggle
                                        name="show_logo"
                                        checked={settings.showLogo}
                                    >
                                        Logo
                                    </Toggle>
                                    <Toggle
                                        name="show_address"
                                        checked={settings.showAddress}
                                    >
                                        Address
                                    </Toggle>
                                    <Toggle
                                        name="show_contact"
                                        checked={settings.showContact}
                                    >
                                        Contact
                                    </Toggle>
                                    <Toggle
                                        name="show_customer"
                                        checked={settings.showCustomer}
                                    >
                                        Customer name and phone
                                    </Toggle>
                                    <Toggle
                                        name="show_staff"
                                        checked={settings.showStaff}
                                    >
                                        Cashier and pharmacist
                                    </Toggle>
                                    <Toggle
                                        name="show_barcode"
                                        checked={settings.showBarcode}
                                    >
                                        Barcode of the order number
                                    </Toggle>
                                </div>
                            </PanelBody>
                        </Panel>

                        <div>
                            <Button type="submit" disabled={processing}>
                                Save counter settings
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
