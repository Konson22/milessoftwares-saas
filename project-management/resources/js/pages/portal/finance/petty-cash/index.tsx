import { Form, Head, Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { Field, invalid, Select } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { todayIso } from '@/lib/status';
import { show, store } from '@/routes/petty-cash';
import type { Option } from '@/types';

type Props = {
    funds: {
        id: string;
        name: string;
        custodian: string;
        floatMinor: number;
        balanceMinor: number;
        currency: string;
        active: boolean;
        moved: boolean;
    }[];
    custodians: Option[];
    currency: string;
    limitMinor: number | null;
    canManage: boolean;
    /** Set when Finance keeps the organization's petty cash. */
    financeUrl: string | null;
};

/**
 * The organization's petty cash boxes, each with a set amount and a custodian.
 */
export default function PettyCash({
    funds,
    custodians,
    currency,
    limitMinor,
    canManage,
    financeUrl,
}: Props) {
    const waiting = funds.filter((fund) => fund.active).length;
    return (
        <>
            <Head title="Petty cash" />
            <PageHeader
                title="Petty cash"
                description="Boxes of cash with a set amount and a custodian, topped up against receipts."
            />

            <div className="grid gap-4">
                {financeUrl !== null && (
                    <p
                        role="status"
                        className="border border-input bg-card p-3 text-sm"
                    >
                        Petty cash is kept in Finance.{' '}
                        <a href={financeUrl} className="underline">
                            Open petty cash in Finance
                        </a>
                        . Payments charged to a project there are not shown in
                        its budget here.
                        {waiting > 0 &&
                            ` ${waiting === 1 ? 'One box here is' : `${waiting} boxes here are`} still to be moved: open a box and move it.`}
                    </p>
                )}
                {financeUrl === null && limitMinor === null && (
                    <p
                        role="status"
                        className="border border-input bg-card p-3 text-sm"
                    >
                        No payment limit is set, so nothing can be paid yet.
                        Someone who runs the organization sets it in
                        Administration, Settings.
                    </p>
                )}
                <Panel>
                    {funds.length === 0 ? (
                        <EmptyState icon={Wallet} title="No boxes yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Box</TableHead>
                                    <TableHead>Custodian</TableHead>
                                    <TableHead className="text-right">
                                        Holds
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Set amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {funds.map((fund) => (
                                    <TableRow key={fund.id}>
                                        <TableCell>
                                            <Link
                                                href={show(fund.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {fund.name}
                                            </Link>
                                            {!fund.active && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {fund.moved
                                                        ? 'In Finance'
                                                        : 'Closed'}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {fund.custodian}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                fund.balanceMinor,
                                                fund.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                fund.floatMinor,
                                                fund.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {canManage && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Set up a box" />
                        <PanelBody>
                            {custodians.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nobody may hold a box yet: give someone the
                                    Petty Cash Custodian role first.
                                </p>
                            ) : (
                                <Form
                                    {...store.form()}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="name"
                                                label="Name"
                                                error={errors.name}
                                            >
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    required
                                                    maxLength={100}
                                                    placeholder="Juba office"
                                                    {...invalid(
                                                        'name',
                                                        errors.name,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="custodian_id"
                                                label="Custodian"
                                                error={errors.custodian_id}
                                            >
                                                <Select
                                                    id="custodian_id"
                                                    name="custodian_id"
                                                    required
                                                    options={custodians}
                                                />
                                            </Field>
                                            <Field
                                                id="float"
                                                label={`Set amount (${currency})`}
                                                error={errors.float}
                                            >
                                                <Input
                                                    id="float"
                                                    name="float"
                                                    required
                                                    inputMode="decimal"
                                                    {...invalid(
                                                        'float',
                                                        errors.float,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="on"
                                                label="Date"
                                                error={errors.on}
                                            >
                                                <Input
                                                    id="on"
                                                    name="on"
                                                    type="date"
                                                    required
                                                    defaultValue={todayIso()}
                                                    {...invalid(
                                                        'on',
                                                        errors.on,
                                                    )}
                                                />
                                            </Field>
                                            <div className="sm:col-span-2">
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Set up box
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
