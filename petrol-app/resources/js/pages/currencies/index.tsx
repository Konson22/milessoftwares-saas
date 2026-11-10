import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';

type Row = {
    id: string;
    code: string;
    name: string;
    symbol: string;
    decimals: number;
    active: boolean;
    createdBy: string | null;
    createdAt: string | null;
};

export default function Currencies({
    catalog,
    accepted,
    books,
    canManage,
    canEnable,
}: {
    catalog: Row[];
    accepted: string[];
    books: string;
    canManage: boolean;
    canEnable: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Currencies" />
            <PageHeader
                title="Currency management"
                description="The company catalog. A station turns currencies on from this list. Cash stays in the currency it was received in."
            />
            <Panel className="mb-4">
                <PanelHeader title="Company catalog" />
                <PanelBody className="grid gap-4">
                    {catalog.map((row) => (
                        <Form
                            key={row.id}
                            action={`/petrol/currencies/${row.id}`}
                            method="put"
                            className="grid gap-3 border-b pb-4 md:grid-cols-6"
                        >
                            <p className="text-sm md:col-span-6">
                                <span className="font-medium">{row.code}</span>
                                {row.active ? '' : ' · inactive'}
                                {row.createdBy
                                    ? ` · added by ${row.createdBy}`
                                    : ''}
                                {row.createdAt ? ` · ${row.createdAt}` : ''}
                            </p>
                            <Field id={`name-${row.code}`} label="Name">
                                <Input
                                    id={`name-${row.code}`}
                                    name="name"
                                    defaultValue={row.name}
                                    required
                                    disabled={!canManage}
                                />
                            </Field>
                            <Field id={`symbol-${row.code}`} label="Symbol">
                                <Input
                                    id={`symbol-${row.code}`}
                                    name="symbol"
                                    defaultValue={row.symbol}
                                    required
                                    disabled={!canManage}
                                />
                            </Field>
                            <Field id={`decimals-${row.code}`} label="Decimals">
                                <Input
                                    id={`decimals-${row.code}`}
                                    name="decimals"
                                    type="number"
                                    min={0}
                                    max={4}
                                    defaultValue={row.decimals}
                                    required
                                    disabled={!canManage}
                                />
                            </Field>
                            <Field id={`active-${row.code}`} label="Active">
                                <input type="hidden" name="active" value="0" />
                                <input
                                    id={`active-${row.code}`}
                                    name="active"
                                    type="checkbox"
                                    value="1"
                                    defaultChecked={row.active}
                                    disabled={!canManage || row.code === books}
                                    className="mt-2 size-4"
                                />
                            </Field>
                            {canManage && (
                                <div className="self-end">
                                    <Button type="submit">Save</Button>
                                </div>
                            )}
                        </Form>
                    ))}
                </PanelBody>
            </Panel>
            {canManage && (
                <Panel className="mb-4">
                    <PanelHeader
                        title="Add a currency"
                        description="Use the ISO code. This does not invent an exchange rate."
                    />
                    <PanelBody>
                        <Form
                            action="/petrol/currencies"
                            method="post"
                            className="grid gap-3 md:grid-cols-4"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field id="code" label="Code" error={errors.code}>
                                <Input
                                    id="code"
                                    name="code"
                                    maxLength={3}
                                    required
                                />
                            </Field>
                            <Field
                                id="symbol"
                                label="Symbol"
                                error={errors.symbol}
                            >
                                <Input id="symbol" name="symbol" required />
                            </Field>
                            <Field
                                id="decimals"
                                label="Decimals"
                                error={errors.decimals}
                            >
                                <Input
                                    id="decimals"
                                    name="decimals"
                                    type="number"
                                    min={0}
                                    max={4}
                                    defaultValue={2}
                                    required
                                />
                            </Field>
                            <Button type="submit">Add currency</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {canEnable && (
                <Panel>
                    <PanelHeader
                        title="Accepted at this station"
                        description={`${books} stays on. A currency that is off is still shown on old transactions.`}
                    />
                    <PanelBody>
                        <Form
                            action="/petrol/currencies-station"
                            method="put"
                            className="grid gap-2"
                        >
                            <input
                                type="hidden"
                                name="enabled[]"
                                value={books}
                            />
                            {catalog
                                .filter((row) => row.active)
                                .map((row) => (
                                    <label
                                        key={row.code}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="enabled[]"
                                            value={row.code}
                                            defaultChecked={accepted.includes(
                                                row.code,
                                            )}
                                            disabled={row.code === books}
                                        />
                                        {row.code} · {row.name}
                                    </label>
                                ))}
                            <Button type="submit" className="mt-2">
                                Save station currencies
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
