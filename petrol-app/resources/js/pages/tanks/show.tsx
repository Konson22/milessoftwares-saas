import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { measure, opening } from '@/routes/tanks';

type Props = {
    tank: {
        id: string;
        name: string;
        code: string;
        product: string;
        capacity: string;
        quantity: string;
        averageCostMinor: number;
        status: string;
        low: boolean;
    };
    movements: {
        id: string;
        kind: string;
        litres: string;
        at: string;
        notes: string | null;
    }[];
    measurements: {
        id: string;
        measured: string;
        variance: string;
        at: string;
        notes: string | null;
    }[];
    canAdjust: boolean;
};

export default function TankShow({
    tank,
    movements,
    measurements,
    canAdjust,
}: Props) {
    const money = useMoney();
    const { errors } = usePage().props;

    return (
        <>
            <Head title={tank.code} />
            <PageHeader
                title={`${tank.code} ${tank.name}`}
                description={`${tank.product}. ${tank.quantity} L of ${tank.capacity} L. Average cost ${money(tank.averageCostMinor)}.`}
            />
            {canAdjust && (
                <div className="mb-4 grid gap-4 md:grid-cols-2">
                    <Panel>
                        <PanelHeader title="Opening stock" />
                        <PanelBody>
                            <Form
                                {...opening.form(tank.id)}
                                className="grid gap-3"
                            >
                                <Field
                                    id="litres"
                                    label="Litres"
                                    error={errors.litres}
                                >
                                    <Input
                                        id="litres"
                                        name="litres"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="cost"
                                    label="Cost per litre"
                                    error={errors.cost}
                                >
                                    <Input
                                        id="cost"
                                        name="cost"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Button type="submit">Record opening</Button>
                            </Form>
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader title="Measurement" />
                        <PanelBody>
                            <Form
                                {...measure.form(tank.id)}
                                className="grid gap-3"
                            >
                                <Field id="method" label="Method">
                                    <select
                                        id="method"
                                        name="method"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                        defaultValue="volume"
                                    >
                                        <option value="volume">
                                            Authorised volume
                                        </option>
                                        <option value="dipstick">
                                            Dipstick
                                        </option>
                                        <option value="gauge">
                                            Gauge reading
                                        </option>
                                    </select>
                                </Field>
                                <Field
                                    id="measured"
                                    label="Litres, if not a dipstick"
                                    error={errors.litres}
                                >
                                    <Input
                                        id="measured"
                                        name="litres"
                                        inputMode="decimal"
                                    />
                                </Field>
                                <Field
                                    id="depth_mm"
                                    label="Depth (mm)"
                                    error={errors.depth_mm}
                                >
                                    <Input
                                        id="depth_mm"
                                        name="depth_mm"
                                        inputMode="numeric"
                                    />
                                </Field>
                                <Field id="water_mm" label="Water (mm)">
                                    <Input
                                        id="water_mm"
                                        name="water_mm"
                                        inputMode="numeric"
                                    />
                                </Field>
                                <Field id="notes" label="Notes">
                                    <Input id="notes" name="notes" />
                                </Field>
                                <Button type="submit">
                                    Record measurement
                                </Button>
                            </Form>
                            <p className="mt-2 text-xs text-muted-foreground">
                                The reading is kept. Stock changes only when a
                                verified difference is posted.
                            </p>
                        </PanelBody>
                    </Panel>
                </div>
            )}
            <Panel>
                <PanelHeader title="Movements" />
                <PanelBody>
                    <ul className="text-sm">
                        {movements.map((row) => (
                            <li key={row.id}>
                                {row.kind} {row.litres} L
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            <Panel className="mt-4">
                <PanelHeader title="Dips" />
                <PanelBody>
                    <ul className="text-sm">
                        {measurements.map((row) => (
                            <li key={row.id}>
                                {row.measured} L, variance {row.variance} L
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
