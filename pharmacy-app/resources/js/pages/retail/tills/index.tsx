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
import { index, show, store } from '@/routes/retail/tills';

export type Till = {
    id: string;
    number: string;
    status: string;
    openedBy: string;
    openedAt: string;
    openingFloatMinor: number;
    closedBy: string | null;
    closedAt: string | null;
    /** Null when the cashier counts blind. */
    expectedCashMinor: number | null;
    countedCashMinor: number | null;
    differenceMinor: number | null;
    closingNote: string | null;
};

/** How the branch runs shifts (its counter settings), for this member. */
export type ShiftRules = {
    /** Money is taken only in an open shift. */
    required: boolean;
    /** The usual starting cash, prefilled. */
    defaultFloat: string;
    /** This member counts without seeing what the drawer should hold. */
    blind: boolean;
    /** Only managers close shifts. */
    managerCloses: boolean;
};

type Props = {
    day: string;
    managing: boolean;
    mine: Till | null;
    tills: Till[];
    shifts: ShiftRules;
};

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    closed: 'Closed',
};

/**
 * Cash shifts of the branch: start yours with the cash in the drawer, and
 * (for managers) every cashier's count against what their drawer should
 * hold.
 */
export default function Shifts({ day, managing, mine, tills, shifts }: Props) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const figure = (minor: number | null) =>
        minor === null ? '—' : money(minor);

    return (
        <>
            <Head title="Shifts" />
            <PageHeader
                title="Shifts"
                description={
                    managing
                        ? `${branch?.name}: every cashier's shift`
                        : branch?.name
                }
            />

            {canHere('retail.sales.create') && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Your shift"
                        description={
                            shifts.required
                                ? 'Start a shift before taking money; count the cash to close it.'
                                : 'Shifts are optional at this branch: start one to count your cash at the end.'
                        }
                    />
                    <PanelBody>
                        {mine ? (
                            <p className="flex flex-wrap items-center gap-3 text-sm">
                                <span>
                                    Shift{' '}
                                    <span className="font-medium">
                                        {mine.number}
                                    </span>{' '}
                                    started{' '}
                                    {new Date(mine.openedAt).toLocaleString()},
                                    with {money(mine.openingFloatMinor)} in the
                                    drawer.
                                </span>
                                <Button asChild size="sm">
                                    <Link href={show(mine.id)}>
                                        {shifts.managerCloses && !managing
                                            ? 'See your shift'
                                            : 'Count and close'}
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
                                                Starting cash in the drawer
                                            </Label>
                                            <Input
                                                id="opening_float"
                                                name="opening_float"
                                                inputMode="decimal"
                                                defaultValue={
                                                    shifts.defaultFloat
                                                }
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
                                            Start shift
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
                            <Label htmlFor="day">Started on</Label>
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
                    <EmptyState icon={Wallet} title="No shifts this day" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Shift</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Status</TableHead>
                                {!shifts.blind && (
                                    <TableHead className="text-right">
                                        Expected
                                    </TableHead>
                                )}
                                <TableHead className="text-right">
                                    Counted
                                </TableHead>
                                {!shifts.blind && (
                                    <TableHead className="text-right">
                                        Over / short
                                    </TableHead>
                                )}
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
                                            {STATUS_LABELS[till.status] ??
                                                till.status}
                                        </StatusBadge>
                                    </TableCell>
                                    {!shifts.blind && (
                                        <TableCell className="text-right tabular-nums">
                                            {figure(till.expectedCashMinor)}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right tabular-nums">
                                        {figure(till.countedCashMinor)}
                                    </TableCell>
                                    {!shifts.blind && (
                                        <TableCell
                                            className={`text-right tabular-nums ${till.differenceMinor ? 'font-medium text-destructive' : ''}`}
                                        >
                                            {figure(till.differenceMinor)}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
