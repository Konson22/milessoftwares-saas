import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { index, show, store } from '@/routes/tills';

export type Till = {
    id: string;
    number: string;
    status: string;
    openedBy: string;
    openedAt: string;
    openingFloatMinor: number;
    closedBy: string | null;
    closedAt: string | null;
    expectedCashMinor: number | null;
    countedCashMinor: number | null;
    differenceMinor: number | null;
    closingNote: string | null;
};

type Props = {
    day: string;
    managing: boolean;
    mine: Till | null;
    tills: Till[];
};

/**
 * Tills of the branch: open yours with a float, and (for managers) every
 * cashier's count against what their till should hold.
 */
export default function Tills({ day, managing, mine, tills }: Props) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Tills" />
            <PageHeader
                title="Tills"
                description={
                    managing
                        ? `${branch?.name}: every cashier's till`
                        : branch?.name
                }
            />

            {canHere('pharmacy.sales.create') && (
                <Panel className="mb-5">
                    <PanelHeader title="Your till" />
                    <PanelBody>
                        {mine ? (
                            <p className="flex flex-wrap items-center gap-3 text-sm">
                                <span>
                                    Till{' '}
                                    <span className="font-medium">
                                        {mine.number}
                                    </span>{' '}
                                    is open since{' '}
                                    {new Date(mine.openedAt).toLocaleString()},
                                    with a float of{' '}
                                    {money(mine.openingFloatMinor)}.
                                </span>
                                <Button asChild size="sm">
                                    <Link href={show(mine.id)}>
                                        Count and close
                                    </Link>
                                </Button>
                            </p>
                        ) : (
                            <Form
                                {...store.form()}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="opening_float">
                                                Cash in the drawer to start
                                            </Label>
                                            <Input
                                                id="opening_float"
                                                name="opening_float"
                                                inputMode="decimal"
                                                defaultValue="0"
                                                className="w-44"
                                            />
                                            {errors.opening_float && (
                                                <p className="text-sm text-destructive">
                                                    {errors.opening_float}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Open till
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="day">Opened on</Label>
                            <Input
                                id="day"
                                name="day"
                                type="date"
                                defaultValue={day}
                                className="w-44"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {tills.length === 0 ? (
                    <EmptyState icon={Wallet} title="No tills this day" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Till</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Opened</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Expected
                                </TableHead>
                                <TableHead className="text-right">
                                    Counted
                                </TableHead>
                                <TableHead className="text-right">
                                    Over / short
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tills.map((till) => (
                                <TableRow key={till.id}>
                                    <TableCell>
                                        <Link
                                            href={show(till.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {till.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{till.openedBy}</TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {new Date(
                                            till.openedAt,
                                        ).toLocaleString()}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(till.status)}
                                        >
                                            {till.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {till.expectedCashMinor === null
                                            ? '—'
                                            : money(till.expectedCashMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {till.countedCashMinor === null
                                            ? '—'
                                            : money(till.countedCashMinor)}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${till.differenceMinor ? 'font-medium text-destructive' : ''}`}
                                    >
                                        {till.differenceMinor === null
                                            ? '—'
                                            : money(till.differenceMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
