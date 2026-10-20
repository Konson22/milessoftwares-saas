import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { index as categoriesIndex } from '@/routes/asset-categories';
import { show, store } from '@/routes/fixed-assets';

type Option = { id: string; label: string };

type Props = {
    assets: {
        id: string;
        number: string;
        name: string;
        category: string;
        acquiredOn: string;
        costMinor: number;
        accumulatedMinor: number;
        valueMinor: number;
        disposedOn: string | null;
    }[];
    reconciliation: {
        account: string;
        kind: 'asset' | 'accumulated';
        ledgerMinor: number;
        registerMinor: number;
    }[];
    categories: (Option & {
        method: string;
        lifeMonths: number;
        rate: string | null;
    })[];
    sources: { bills: Option[]; journals: Option[] };
    today: string;
};

/**
 * The fixed asset register, checked against the ledger.
 */
export default function FixedAssets({
    assets,
    reconciliation,
    categories,
    sources,
    today,
}: Props) {
    const can = useCan();
    const manage = can('finance.assets.manage');
    const [adding, setAdding] = useState(false);
    const [source, setSource] = useState('bill');
    const [categoryId, setCategoryId] = useState(categories[0]?.id ?? '');
    const category = categories.find((option) => option.id === categoryId);
    const held = assets.filter((asset) => asset.disposedOn === null);
    const sum = (key: 'costMinor' | 'accumulatedMinor' | 'valueMinor') =>
        held.reduce((total, asset) => total + asset[key], 0);

    return (
        <>
            <Head title="Fixed assets" />
            <PageHeader
                title="Fixed assets"
                description="Assets bought by bill or journal, depreciated monthly. Registering an asset posts nothing: its cost is already in the asset account."
                actions={
                    manage &&
                    categories.length > 0 && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            Register an asset
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader title="Register an asset" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="name"
                                        label="Asset"
                                        error={errors.name}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={255}
                                            placeholder="Toyota Hilux KAA 123"
                                        />
                                    </Field>
                                    <Field
                                        id="asset_category_id"
                                        label="Category"
                                        error={errors.asset_category_id}
                                    >
                                        <Select
                                            id="asset_category_id"
                                            name="asset_category_id"
                                            value={categoryId}
                                            onChange={(event) =>
                                                setCategoryId(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {categories.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field
                                        id="acquired_on"
                                        label="Acquired"
                                        error={errors.acquired_on}
                                    >
                                        <Input
                                            id="acquired_on"
                                            name="acquired_on"
                                            type="date"
                                            defaultValue={today}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="source"
                                        label="Bought by"
                                        error={errors.source}
                                    >
                                        <Select
                                            id="source"
                                            name="source"
                                            value={source}
                                            onChange={(event) =>
                                                setSource(event.target.value)
                                            }
                                        >
                                            <option value="bill">A bill</option>
                                            <option value="journal">
                                                A journal
                                            </option>
                                            <option value="existing">
                                                Already in the books
                                            </option>
                                        </Select>
                                    </Field>
                                    {source === 'bill' && (
                                        <Field
                                            id="bill_id"
                                            label="Bill"
                                            error={errors.bill_id}
                                            className="sm:col-span-3"
                                            hint="Bills with a line on an asset account."
                                        >
                                            <Select
                                                id="bill_id"
                                                name="bill_id"
                                                required
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    Choose a bill…
                                                </option>
                                                {sources.bills.map((bill) => (
                                                    <option
                                                        key={bill.id}
                                                        value={bill.id}
                                                    >
                                                        {bill.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                    )}
                                    {source === 'journal' && (
                                        <Field
                                            id="journal_id"
                                            label="Journal"
                                            error={errors.journal_id}
                                            className="sm:col-span-3"
                                            hint="Journals debiting an asset account."
                                        >
                                            <Select
                                                id="journal_id"
                                                name="journal_id"
                                                required
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    Choose a journal…
                                                </option>
                                                {sources.journals.map(
                                                    (journal) => (
                                                        <option
                                                            key={journal.id}
                                                            value={journal.id}
                                                        >
                                                            {journal.label}
                                                        </option>
                                                    ),
                                                )}
                                            </Select>
                                        </Field>
                                    )}
                                    {source === 'existing' && (
                                        <Field
                                            id="opening_depreciation"
                                            label="Depreciated so far"
                                            error={errors.opening_depreciation}
                                            className="sm:col-span-3"
                                            hint="Already in accumulated depreciation, for example from opening balances. The life below is what is left from the date acquired."
                                        >
                                            <Input
                                                id="opening_depreciation"
                                                name="opening_depreciation"
                                                inputMode="decimal"
                                                className="max-w-48 text-right tabular-nums"
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        id="cost"
                                        label="Cost"
                                        error={errors.cost}
                                    >
                                        <Input
                                            id="cost"
                                            name="cost"
                                            inputMode="decimal"
                                            className="text-right tabular-nums"
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="residual"
                                        label="Residual value"
                                        error={errors.residual}
                                        hint="What it will be worth at the end."
                                    >
                                        <Input
                                            id="residual"
                                            name="residual"
                                            inputMode="decimal"
                                            className="text-right tabular-nums"
                                        />
                                    </Field>
                                    <Field
                                        id="life_months"
                                        label="Life (months)"
                                        error={errors.life_months}
                                    >
                                        <Input
                                            id="life_months"
                                            name="life_months"
                                            type="number"
                                            min={1}
                                            max={1200}
                                            key={`life-${categoryId}`}
                                            defaultValue={category?.lifeMonths}
                                        />
                                    </Field>
                                    {category?.method ===
                                        'declining_balance' && (
                                        <Field
                                            id="rate"
                                            label="Rate a year (%)"
                                            error={errors.rate}
                                        >
                                            <Input
                                                id="rate"
                                                name="rate"
                                                inputMode="decimal"
                                                key={`rate-${categoryId}`}
                                                defaultValue={
                                                    category.rate ?? ''
                                                }
                                            />
                                        </Field>
                                    )}
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Register
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {reconciliation.length > 0 && (
                <div className="mb-3 grid gap-3 sm:grid-cols-2">
                    {reconciliation.map((row) => {
                        const matches = row.ledgerMinor === row.registerMinor;

                        return (
                            <Panel key={`${row.kind}-${row.account}`}>
                                <div className="flex items-start justify-between gap-2 p-4">
                                    <div>
                                        <p className="text-sm font-medium">
                                            {row.account}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Ledger{' '}
                                            <Amount minor={row.ledgerMinor} /> ·
                                            register{' '}
                                            <Amount minor={row.registerMinor} />
                                        </p>
                                    </div>
                                    <StatusBadge
                                        tone={matches ? 'success' : 'warning'}
                                    >
                                        {matches ? 'Matches' : 'Differs'}
                                    </StatusBadge>
                                </div>
                            </Panel>
                        );
                    })}
                </div>
            )}

            <Panel>
                {assets.length === 0 ? (
                    <EmptyState icon={Building2} title="No assets yet">
                        {categories.length === 0 ? (
                            <>
                                Add{' '}
                                <Link
                                    href={categoriesIndex()}
                                    className="text-primary hover:underline"
                                >
                                    asset categories
                                </Link>{' '}
                                first.
                            </>
                        ) : (
                            'Register vehicles, equipment and furniture bought by bill or journal.'
                        )}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Asset</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Acquired</TableHead>
                                <TableHead className="text-right">
                                    Cost
                                </TableHead>
                                <TableHead className="text-right">
                                    Depreciation
                                </TableHead>
                                <TableHead className="text-right">
                                    Value
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {assets.map((asset) => (
                                <TableRow
                                    key={asset.id}
                                    className={
                                        asset.disposedOn
                                            ? 'text-muted-foreground'
                                            : undefined
                                    }
                                >
                                    <TableCell className="font-mono text-xs">
                                        <Link
                                            href={show(asset.id)}
                                            className="text-primary hover:underline"
                                        >
                                            {asset.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {asset.name}
                                        {asset.disposedOn && (
                                            <StatusBadge className="ml-2">
                                                Disposed {asset.disposedOn}
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>{asset.category}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {asset.acquiredOn}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={asset.costMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={asset.accumulatedMinor}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {asset.disposedOn ? (
                                            '—'
                                        ) : (
                                            <Amount minor={asset.valueMinor} />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={4}>
                                    Held ({held.length})
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={sum('costMinor')} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={sum('accumulatedMinor')} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={sum('valueMinor')} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                )}
            </Panel>
        </>
    );
}
