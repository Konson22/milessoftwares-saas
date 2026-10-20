import { Form, Head } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';
import { useState } from 'react';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { store, update } from '@/routes/asset-categories';

type Option = { id: string; label: string };

type Category = {
    id: string;
    name: string;
    assetAccountId: string;
    accumulatedAccountId: string;
    expenseAccountId: string;
    assetAccount: string;
    accumulatedAccount: string;
    expenseAccount: string;
    method: string;
    methodLabel: string;
    lifeMonths: number;
    rate: string | null;
    active: boolean;
};

type Props = {
    categories: Category[];
    assetAccounts: Option[];
    expenseAccounts: Option[];
    methods: { value: string; label: string }[];
};

function AccountSelect({
    id,
    name,
    label,
    options,
    value,
    error,
}: {
    id: string;
    name: string;
    label: string;
    options: Option[];
    value?: string;
    error?: string;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <Select id={id} name={name} defaultValue={value ?? ''} required>
                <option value="">Choose an account…</option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.label}
                    </option>
                ))}
            </Select>
        </Field>
    );
}

function CategoryFields({
    category,
    assetAccounts,
    expenseAccounts,
    methods,
    errors,
}: {
    category?: Category;
    assetAccounts: Option[];
    expenseAccounts: Option[];
    methods: Props['methods'];
    errors: Record<string, string>;
}) {
    const id = category?.id ?? 'new';
    const [method, setMethod] = useState(category?.method ?? 'straight_line');

    return (
        <>
            <Field id={`${id}-name`} label="Name" error={errors.name}>
                <Input
                    id={`${id}-name`}
                    name="name"
                    defaultValue={category?.name}
                    required
                    maxLength={100}
                    placeholder="Vehicles"
                />
            </Field>
            <AccountSelect
                id={`${id}-asset`}
                name="asset_account_id"
                label="Asset account"
                options={assetAccounts}
                value={category?.assetAccountId}
                error={errors.asset_account_id}
            />
            <AccountSelect
                id={`${id}-accumulated`}
                name="accumulated_account_id"
                label="Accumulated depreciation"
                options={assetAccounts}
                value={category?.accumulatedAccountId}
                error={errors.accumulated_account_id}
            />
            <AccountSelect
                id={`${id}-expense`}
                name="expense_account_id"
                label="Depreciation expense"
                options={expenseAccounts}
                value={category?.expenseAccountId}
                error={errors.expense_account_id}
            />
            <Field id={`${id}-method`} label="Method" error={errors.method}>
                <Select
                    id={`${id}-method`}
                    name="method"
                    value={method}
                    onChange={(event) => setMethod(event.target.value)}
                >
                    {methods.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
            </Field>
            <Field
                id={`${id}-life`}
                label="Useful life (months)"
                error={errors.life_months}
            >
                <Input
                    id={`${id}-life`}
                    name="life_months"
                    type="number"
                    min={1}
                    max={1200}
                    defaultValue={category?.lifeMonths ?? 36}
                    required
                />
            </Field>
            {method === 'declining_balance' && (
                <Field
                    id={`${id}-rate`}
                    label="Rate a year (%)"
                    error={errors.rate}
                >
                    <Input
                        id={`${id}-rate`}
                        name="rate"
                        inputMode="decimal"
                        defaultValue={category?.rate ?? ''}
                        required
                    />
                </Field>
            )}
            {category && (
                <label className="flex items-center gap-2 self-end text-sm">
                    <input type="hidden" name="active" value="0" />
                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        defaultChecked={category.active}
                    />
                    In use
                </label>
            )}
        </>
    );
}

/**
 * Asset categories: which accounts a kind of asset uses and how it is
 * depreciated. Changes apply to assets registered afterwards.
 */
export default function AssetCategories({
    categories,
    assetAccounts,
    expenseAccounts,
    methods,
}: Props) {
    const can = useCan();
    const manage = can('finance.assets.manage');
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const fieldProps = { assetAccounts, expenseAccounts, methods };

    return (
        <>
            <Head title="Asset categories" />
            <PageHeader
                title="Asset categories"
                description="Accounts and depreciation for each kind of asset. A change applies to assets registered afterwards."
                actions={
                    manage && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New category
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader title="New category" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            onSuccess={() => setAdding(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <CategoryFields
                                        errors={errors}
                                        {...fieldProps}
                                    />
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add category
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {categories.length === 0 ? (
                    <EmptyState icon={Boxes} title="No categories yet">
                        Add one for each kind of asset, such as vehicles,
                        furniture or computers.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Category</TableHead>
                                <TableHead>Accounts</TableHead>
                                <TableHead>Depreciation</TableHead>
                                <TableHead>Status</TableHead>
                                {manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.map((category) =>
                                editing === category.id ? (
                                    <TableRow key={category.id}>
                                        <TableCell colSpan={manage ? 5 : 4}>
                                            <Form
                                                {...update.form(category.id)}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="grid gap-3 py-2 sm:grid-cols-4"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <CategoryFields
                                                            category={category}
                                                            errors={errors}
                                                            {...fieldProps}
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
                                    <TableRow key={category.id}>
                                        <TableCell className="font-medium">
                                            {category.name}
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">
                                            {category.assetAccount}
                                            <br />
                                            {category.accumulatedAccount}
                                            <br />
                                            {category.expenseAccount}
                                        </TableCell>
                                        <TableCell>
                                            {category.methodLabel}
                                            {category.rate &&
                                                `, ${category.rate}% a year`}
                                            <span className="block text-xs text-muted-foreground">
                                                {category.lifeMonths} months
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={
                                                    category.active
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {category.active
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
                                                        setEditing(category.id)
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
