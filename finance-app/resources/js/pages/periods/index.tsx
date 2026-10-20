import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarPlus,
    CalendarRange,
    Lock,
    LockOpen,
    Scale,
} from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/field';
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
import { edit as openingBalances } from '@/routes/opening-balances';
import { show as showJournal } from '@/routes/journals';
import { lock, unlock } from '@/routes/periods';
import { close, reopen, store as storeYear } from '@/routes/periods/years';

type Period = {
    id: string;
    label: string;
    startsOn: string;
    endsOn: string;
    lockedAt: string | null;
    lockedBy: string | null;
};

type Year = {
    id: string;
    name: string;
    startsOn: string;
    endsOn: string;
    closedAt: string | null;
    closedBy: string | null;
    closingJournal: { id: string; number: string } | null;
    periods: Period[];
};

type Props = {
    lockedUntil: string | null;
    booksStart: string | null;
    suggestedStart: string;
    years: Year[];
};

function day(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * Financial years and their months: lock in order, close a year into
 * retained earnings, reopen it.
 */
export default function Periods({
    lockedUntil,
    booksStart,
    suggestedStart,
    years,
}: Props) {
    const can = useCan();
    const manage = can('finance.periods.manage');
    const { errors } = usePage().props;
    const [adding, setAdding] = useState(false);
    const [confirming, setConfirming] = useState<string | null>(null);

    // Months are locked from the earliest and unlocked from the latest.
    const months = [...years]
        .reverse()
        .flatMap((year) => year.periods.map((period) => ({ period, year })));
    const nextToLock = months.find(({ period }) => !period.lockedAt)?.period.id;
    const nextToUnlock = [...months]
        .reverse()
        .find(({ period, year }) => period.lockedAt && !year.closedAt)
        ?.period.id;
    // Years are closed from the earliest and reopened from the latest.
    const nextToClose = [...years].reverse().find((year) => !year.closedAt)?.id;
    const nextToReopen = years.find((year) => year.closedAt)?.id;
    const error = errors.period ?? errors.year;

    return (
        <>
            <Head title="Periods & close" />
            <PageHeader
                title="Periods & close"
                description={
                    booksStart === null
                        ? 'Set up your first financial year to lock months and close years.'
                        : lockedUntil
                          ? `Books locked up to ${day(lockedUntil)}. Sales and bills from other products dated earlier are posted on the next open day.`
                          : `Books start on ${day(booksStart)}. No month is locked.`
                }
                actions={
                    manage && (
                        <>
                            {booksStart !== null && (
                                <Button variant="outline" asChild>
                                    <Link href={openingBalances()}>
                                        <Scale className="size-4" aria-hidden />
                                        Opening balances
                                    </Link>
                                </Button>
                            )}
                            <Button
                                onClick={() => setAdding((value) => !value)}
                            >
                                <CalendarPlus className="size-4" aria-hidden />
                                {years.length === 0
                                    ? 'Set up first year'
                                    : 'Add next year'}
                            </Button>
                        </>
                    )
                }
            />

            {error && <p className="mb-4 text-sm text-destructive">{error}</p>}

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader
                        title={
                            years.length === 0
                                ? 'First financial year'
                                : 'Next financial year'
                        }
                        description={
                            years.length === 0
                                ? 'The day your books in Miles start. Opening balances are dated this day.'
                                : `Starts on ${day(nextDay(years[0].endsOn))}. Change the length only when your year end changes.`
                        }
                    />
                    <PanelBody>
                        <Form
                            {...storeYear.form()}
                            onSuccess={() => setAdding(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors: formErrors, processing }) => (
                                <>
                                    {years.length === 0 && (
                                        <Field
                                            id="starts_on"
                                            label="Starts on"
                                            error={formErrors.starts_on}
                                            hint="The first day of a month"
                                        >
                                            <Input
                                                id="starts_on"
                                                name="starts_on"
                                                type="date"
                                                defaultValue={suggestedStart}
                                                required
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        id="months"
                                        label="Months"
                                        error={formErrors.months}
                                    >
                                        <Input
                                            id="months"
                                            name="months"
                                            type="number"
                                            min={1}
                                            max={24}
                                            defaultValue={12}
                                            required
                                        />
                                    </Field>
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add year
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {years.length === 0 && (
                <Panel>
                    <EmptyState
                        icon={CalendarRange}
                        title="No financial years yet"
                    >
                        Until a year is set up nothing is locked and entries can
                        be dated any day.
                    </EmptyState>
                </Panel>
            )}

            <div className="grid gap-4">
                {years.map((year) => (
                    <Panel key={year.id}>
                        <PanelHeader
                            title={
                                <span className="flex items-center gap-2">
                                    {year.name}
                                    {year.closedAt ? (
                                        <StatusBadge tone="neutral">
                                            Closed
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge tone="success">
                                            Open
                                        </StatusBadge>
                                    )}
                                </span>
                            }
                            description={
                                <>
                                    {day(year.startsOn)} to {day(year.endsOn)}
                                    {year.closedAt && (
                                        <>
                                            {' · closed'}
                                            {year.closedBy &&
                                                ` by ${year.closedBy}`}
                                            {year.closingJournal && (
                                                <>
                                                    {' in '}
                                                    <Link
                                                        href={showJournal(
                                                            year.closingJournal
                                                                .id,
                                                        )}
                                                        className="font-mono text-primary hover:underline"
                                                    >
                                                        {
                                                            year.closingJournal
                                                                .number
                                                        }
                                                    </Link>
                                                </>
                                            )}
                                        </>
                                    )}
                                </>
                            }
                            actions={
                                manage &&
                                (year.id === nextToClose ? (
                                    confirming === year.id ? (
                                        <div className="flex gap-2">
                                            <Form
                                                {...close.form(year.id)}
                                                onFinish={() =>
                                                    setConfirming(null)
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Lock every month and
                                                        close
                                                    </Button>
                                                )}
                                            </Form>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setConfirming(null)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setConfirming(year.id)
                                            }
                                        >
                                            Close year
                                        </Button>
                                    )
                                ) : year.id === nextToReopen ? (
                                    <Form {...reopen.form(year.id)}>
                                        {({ processing }) => (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Reopen
                                            </Button>
                                        )}
                                    </Form>
                                ) : null)
                            }
                        />
                        {confirming === year.id && (
                            <PanelBody className="border-b text-sm text-muted-foreground">
                                Every month of {year.name} is locked, and one
                                journal dated {day(year.endsOn)} moves the
                                year's revenue and expenses into retained
                                earnings. Reopening reverses it.
                            </PanelBody>
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Month</TableHead>
                                    <TableHead>Dates</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Locked by</TableHead>
                                    {manage && (
                                        <TableHead className="text-right" />
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {year.periods.map((period) => (
                                    <TableRow key={period.id}>
                                        <TableCell className="font-medium">
                                            {period.label}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground tabular-nums">
                                            {period.startsOn} – {period.endsOn}
                                        </TableCell>
                                        <TableCell>
                                            {period.lockedAt ? (
                                                <StatusBadge tone="warning">
                                                    <Lock
                                                        className="size-3"
                                                        aria-hidden
                                                    />
                                                    Locked
                                                </StatusBadge>
                                            ) : (
                                                <StatusBadge tone="success">
                                                    Open
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {period.lockedBy}
                                        </TableCell>
                                        {manage && (
                                            <TableCell className="text-right">
                                                {period.id === nextToLock && (
                                                    <Form
                                                        {...lock.form(
                                                            period.id,
                                                        )}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                type="submit"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <Lock
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                                Lock
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                                {period.id === nextToUnlock && (
                                                    <Form
                                                        {...unlock.form(
                                                            period.id,
                                                        )}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                type="submit"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <LockOpen
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                                Unlock
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                ))}
            </div>
        </>
    );
}

function nextDay(date: string): string {
    const next = new Date(`${date}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + 1);

    return next.toISOString().slice(0, 10);
}
