import { Form, Head } from '@inertiajs/react';
import { Percent, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field } from '@/components/field';
import { useCan } from '@/lib/access';
import { store, update } from '@/routes/tax-codes';

type TaxCode = {
    id: string;
    code: string;
    name: string;
    rate: string;
    label: string;
    sales: boolean;
    purchases: boolean;
    recoverable: boolean;
    active: boolean;
};

type Props = { codes: TaxCode[] };

/** A yes/no field that always posts a value. */
function Flag({
    name,
    label,
    checked,
}: {
    name: string;
    label: string;
    checked: boolean;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={checked}
            />
            {label}
        </label>
    );
}

function CodeFields({
    code,
    errors,
}: {
    code?: TaxCode;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Field id="code" label="Code" error={errors.code} hint="VAT18">
                <Input
                    id="code"
                    name="code"
                    required
                    maxLength={16}
                    defaultValue={code?.code}
                />
            </Field>
            <Field
                id="name"
                label="Name"
                error={errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id="name"
                    name="name"
                    required
                    maxLength={255}
                    defaultValue={code?.name}
                />
            </Field>
            <Field
                id="rate"
                label="Rate (%)"
                error={errors.rate}
                hint={code ? 'A new rate is a new code' : undefined}
            >
                <Input
                    id="rate"
                    name="rate"
                    inputMode="decimal"
                    required={!code}
                    disabled={!!code}
                    defaultValue={code?.rate}
                />
            </Field>
            <div className="flex flex-wrap gap-4 sm:col-span-4">
                <Flag
                    name="sales"
                    label="On sales"
                    checked={code?.sales ?? true}
                />
                <Flag
                    name="purchases"
                    label="On purchases"
                    checked={code?.purchases ?? true}
                />
                <Flag
                    name="recoverable"
                    label="Purchase tax claimed back"
                    checked={code?.recoverable ?? true}
                />
                {code && (
                    <Flag name="active" label="In use" checked={code.active} />
                )}
            </div>
        </>
    );
}

/**
 * Tax codes: the rates charged on invoices and paid on bills. Purchase
 * tax that is not claimed back is part of the cost.
 */
export default function TaxCodes({ codes }: Props) {
    const can = useCan();
    const manage = can('finance.tax.manage');
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Tax codes" />
            <PageHeader
                title="Tax codes"
                description="Rates chosen on invoice and bill lines. Their tax is reported on the tax return."
                actions={
                    manage && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New tax code
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader title="New tax code" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            onSuccess={() => setAdding(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <CodeFields errors={errors} />
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add tax code
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {codes.length === 0 ? (
                    <EmptyState icon={Percent} title="No tax codes yet">
                        Add the rates you charge, for example VAT at 18% and a
                        zero rate for exempt sales.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead>Used on</TableHead>
                                <TableHead>Status</TableHead>
                                {manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {codes.map((code) =>
                                editing === code.id ? (
                                    <TableRow key={code.id}>
                                        <TableCell colSpan={manage ? 6 : 5}>
                                            <Form
                                                {...update.form(code.id)}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="grid gap-3 py-2 sm:grid-cols-4"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <CodeFields
                                                            code={code}
                                                            errors={errors}
                                                        />
                                                        <div className="flex gap-2 sm:col-span-4">
                                                            <Button
                                                                type="submit"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </>
                                                )}
                                            </Form>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={code.id}>
                                        <TableCell className="font-medium">
                                            {code.code}
                                        </TableCell>
                                        <TableCell>{code.name}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {code.rate}%
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {[
                                                code.sales && 'Sales',
                                                code.purchases &&
                                                    (code.recoverable
                                                        ? 'Purchases'
                                                        : 'Purchases (cost)'),
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={
                                                    code.active
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {code.active
                                                    ? 'In use'
                                                    : 'Not in use'}
                                            </StatusBadge>
                                        </TableCell>
                                        {manage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing(code.id)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
