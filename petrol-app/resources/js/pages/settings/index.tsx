import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { index, station } from '@/routes/settings';
import { update as updatePayment } from '@/routes/settings/payments';
import { store as storePrice } from '@/routes/settings/prices';
import { store as storeSchedule } from '@/routes/settings/schedules';

type Props = {
    section: string;
    station: {
        code: string;
        collectionMode: string;
        lowStockPercent: number;
        hours: string | null;
        notes: string | null;
        active: boolean;
        main: boolean;
        sharedPumps?: boolean;
        dispenseDuringReceiving?: boolean;
        waterAlertMm?: number | null;
        calibrationIntervalDays?: number | null;
        operations?: { fuel: boolean; service: boolean; store: boolean };
    } | null;
    schedules: {
        id: string;
        name: string;
        startsAt: string;
        endsAt: string;
        crossesMidnight: boolean;
        active: boolean;
    }[];
    products: { id: string; name: string; code: string }[];
    prices: { product: string; priceMinor: number; currency?: string | null }[];
    methods: { id: string; name: string; kind: string; active: boolean }[];
    accessUrl: string;
    currencies?: { code: string; name: string }[];
};

const sections = ['station', 'schedules', 'prices', 'payments', 'access'];

export default function Settings({
    section,
    station: current,
    schedules,
    products,
    prices,
    methods,
    accessUrl,
    currencies = [],
}: Props) {
    const money = useMoney();
    const { errors, currency } = usePage().props;

    return (
        <>
            <Head title="Settings" />
            <PageHeader
                title="Station settings"
                description="The branch, its hours, prices and how cash is collected. People and roles stay in the workspace."
            />
            <nav className="mb-4 flex flex-wrap gap-2">
                {sections.map((name) => (
                    <Button
                        key={name}
                        asChild
                        variant={section === name ? 'default' : 'outline'}
                        size="sm"
                    >
                        <a href={index.url({ query: { section: name } })}>
                            {name}
                        </a>
                    </Button>
                ))}
            </nav>

            {section === 'schedules' && (
                <Panel>
                    <PanelHeader title="Shift schedules" />
                    <PanelBody className="grid gap-4">
                        <ul className="text-sm">
                            {schedules.map((row) => (
                                <li key={row.id}>
                                    {row.name}: {row.startsAt}–{row.endsAt}
                                    {row.crossesMidnight ? ' (overnight)' : ''}
                                </li>
                            ))}
                        </ul>
                        <Form
                            {...storeSchedule.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field
                                id="starts_at"
                                label="Starts"
                                error={errors.starts_at}
                            >
                                <Input
                                    id="starts_at"
                                    name="starts_at"
                                    placeholder="06:00"
                                    required
                                />
                            </Field>
                            <Field
                                id="ends_at"
                                label="Ends"
                                error={errors.ends_at}
                            >
                                <Input
                                    id="ends_at"
                                    name="ends_at"
                                    placeholder="14:00"
                                    required
                                />
                            </Field>
                            <Button type="submit">Add schedule</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {section === 'prices' && (
                <Panel>
                    <PanelHeader title="Selling prices" />
                    <PanelBody className="grid gap-4">
                        <ul className="text-sm">
                            {prices.map((price) => (
                                <li key={price.product}>
                                    {price.product}: {money(price.priceMinor)}
                                    {price.currency ? ` ${price.currency}` : ''}
                                </li>
                            ))}
                        </ul>
                        <Form
                            {...storePrice.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="fuel_product_id" label="Grade">
                                <Select
                                    id="fuel_product_id"
                                    name="fuel_product_id"
                                    options={products.map((product) => ({
                                        value: product.id,
                                        label: product.name,
                                    }))}
                                    required
                                />
                            </Field>
                            <Field
                                id="selling_price"
                                label="Price per litre"
                                error={errors.selling_price}
                            >
                                <Input
                                    id="selling_price"
                                    name="selling_price"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="currency"
                                label="Currency"
                                error={errors.currency}
                                hint="This station only. Other stations keep their own prices."
                            >
                                <CurrencySelect
                                    id="currency"
                                    currencies={currencies}
                                    defaultValue={currency}
                                />
                            </Field>
                            <Button type="submit">Save price</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {section === 'payments' && (
                <Panel>
                    <PanelHeader title="Payment methods" />
                    <PanelBody className="grid gap-3">
                        {methods.map((method) => (
                            <Form
                                key={method.id}
                                {...updatePayment.form(method.id)}
                                className="flex items-center gap-3"
                            >
                                <input
                                    type="hidden"
                                    name="active"
                                    value={method.active ? '0' : '1'}
                                />
                                <span className="flex-1 text-sm">
                                    {method.name} ({method.kind}) —{' '}
                                    {method.active ? 'on' : 'off'}
                                </span>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    {method.active ? 'Disable' : 'Enable'}
                                </Button>
                            </Form>
                        ))}
                    </PanelBody>
                </Panel>
            )}

            {section === 'access' && (
                <Panel>
                    <PanelHeader title="People and roles" />
                    <PanelBody>
                        <p className="text-sm">
                            Users, roles and who may work at this station are
                            managed in the workspace.
                        </p>
                        <Button asChild className="mt-3">
                            <a href={accessUrl}>Open members</a>
                        </Button>
                    </PanelBody>
                </Panel>
            )}

            {(section === 'station' || !sections.includes(section)) && (
                <Panel>
                    <PanelHeader title="Station" />
                    <PanelBody>
                        {current?.main && (
                            <p className="mb-3 text-sm text-muted-foreground">
                                This is the company’s main branch. It still runs
                                its own tanks, shifts and sales. Company
                                administrators change the designation from
                                Company overview.
                            </p>
                        )}
                        <Form
                            {...station.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="code" label="Code" error={errors.code}>
                                <Input
                                    id="code"
                                    name="code"
                                    defaultValue={current?.code ?? ''}
                                    required
                                />
                            </Field>
                            <Field
                                id="collection_mode"
                                label="Who collects cash"
                            >
                                <Select
                                    id="collection_mode"
                                    name="collection_mode"
                                    defaultValue={
                                        current?.collectionMode ?? 'both'
                                    }
                                    options={[
                                        {
                                            value: 'attendant',
                                            label: 'Attendant',
                                        },
                                        { value: 'cashier', label: 'Cashier' },
                                        { value: 'both', label: 'Either' },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="dispense_during_receiving"
                                label="Dispense during unloading"
                                hint="Leave this on only where the station procedure allows sales while a tanker is unloading. Sales during that interval are counted in the delivery check."
                            >
                                <input
                                    type="hidden"
                                    name="dispense_during_receiving"
                                    value="0"
                                />
                                <input
                                    id="dispense_during_receiving"
                                    name="dispense_during_receiving"
                                    type="checkbox"
                                    value="1"
                                    defaultChecked={
                                        current?.dispenseDuringReceiving ?? true
                                    }
                                    className="mt-2 size-4"
                                />
                            </Field>
                            <Field
                                id="water_alert_mm"
                                label="Water alert (mm)"
                                hint="A dip at or above this water depth holds dispensing until a supervisor clears the tank. Leave empty for no alert."
                                error={errors.water_alert_mm}
                            >
                                <Input
                                    id="water_alert_mm"
                                    name="water_alert_mm"
                                    type="number"
                                    min={1}
                                    max={5000}
                                    step={1}
                                    defaultValue={current?.waterAlertMm ?? ''}
                                />
                            </Field>
                            <Field
                                id="calibration_interval_days"
                                label="Calibration interval (days)"
                                hint="When a calibration has no next date, the next one is due this many days later. Leave empty to set it each time."
                                error={errors.calibration_interval_days}
                            >
                                <Input
                                    id="calibration_interval_days"
                                    name="calibration_interval_days"
                                    type="number"
                                    min={1}
                                    max={3650}
                                    step={1}
                                    defaultValue={
                                        current?.calibrationIntervalDays ?? ''
                                    }
                                />
                            </Field>
                            <Field
                                id="shared_pumps"
                                label="Shared pumps"
                                hint="More than one attendant can use a nozzle. Each sale names who dispensed it."
                            >
                                <input
                                    type="hidden"
                                    name="shared_pumps"
                                    value="0"
                                />
                                <input
                                    id="shared_pumps"
                                    name="shared_pumps"
                                    type="checkbox"
                                    value="1"
                                    defaultChecked={
                                        current?.sharedPumps ?? false
                                    }
                                    className="mt-2 size-4"
                                />
                            </Field>
                            <Field
                                id="low_stock_percent"
                                label="Low stock percent"
                            >
                                <Input
                                    id="low_stock_percent"
                                    name="low_stock_percent"
                                    type="number"
                                    defaultValue={
                                        current?.lowStockPercent ?? 15
                                    }
                                    required
                                />
                            </Field>
                            <Field id="hours" label="Hours">
                                <Input
                                    id="hours"
                                    name="hours"
                                    defaultValue={current?.hours ?? ''}
                                />
                            </Field>
                            <Field
                                id="notes"
                                label="Notes"
                                className="md:col-span-2"
                            >
                                <Input
                                    id="notes"
                                    name="notes"
                                    defaultValue={current?.notes ?? ''}
                                />
                            </Field>
                            <input
                                type="hidden"
                                name="operations_form"
                                value="1"
                            />
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="op_fuel"
                                    value="1"
                                    defaultChecked={
                                        current?.operations?.fuel ?? true
                                    }
                                />
                                Fuel
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="op_service"
                                    value="1"
                                    defaultChecked={
                                        current?.operations?.service ?? true
                                    }
                                />
                                Service bay
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="op_store"
                                    value="1"
                                    defaultChecked={
                                        current?.operations?.store ?? true
                                    }
                                />
                                Store
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="hidden" name="active" value="0" />
                                <input
                                    type="checkbox"
                                    name="active"
                                    value="1"
                                    defaultChecked={current?.active ?? true}
                                />
                                Active
                            </label>
                            <div className="md:col-span-2">
                                <Button type="submit">Save station</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
