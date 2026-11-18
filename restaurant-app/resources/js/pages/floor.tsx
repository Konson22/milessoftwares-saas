import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    Armchair,
    BellRing,
    Clock,
    Minus,
    Plus,
    ShoppingBag,
    Users,
    UtensilsCrossed,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { show, store } from '@/routes/orders';
import { index as tablesIndex } from '@/routes/tables';

type Card = {
    id: string;
    number: string;
    guests: number | null;
    customerName: string | null;
    openedAt: string;
    openedBy: string;
    totalMinor: number;
    /** The kitchen has a ticket of it ready to take out. */
    hasReady: boolean;
};

type Table = {
    id: string;
    name: string;
    area: string | null;
    seats: number;
    order: Card | null;
};

type Props = {
    tables: Table[];
    takeaways: Card[];
};

type Show = 'all' | 'free' | 'busy' | 'ready';

/** A table seated this long without paying is shown as waiting long. */
const LONG_MINUTES = 90;

function minutesSince(iso: string, now: number): number {
    return Math.max(0, Math.round((now - new Date(iso).getTime()) / 60000));
}

function duration(minutes: number): string {
    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/** The clock, a minute at a time, so "seated 12 min" keeps moving. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

function Summary({
    icon: Icon,
    label,
    value,
    tone = 'default',
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    tone?: 'default' | 'ready';
}) {
    return (
        <div
            className={`flex items-center gap-3 border px-3 py-2.5 ${
                tone === 'ready'
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950'
                    : 'bg-card'
            }`}
        >
            <Icon
                className={`size-4 shrink-0 ${tone === 'ready' ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground'}`}
                aria-hidden
            />
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="font-semibold tabular-nums">{value}</p>
            </div>
        </div>
    );
}

/** Seats around the table: filled for each guest seated. */
function Seats({ seats, guests }: { seats: number; guests: number }) {
    const shown = Math.min(seats, 12);

    return (
        <span
            className="flex flex-wrap gap-1"
            aria-label={`${guests} of ${seats} seats taken`}
        >
            {Array.from({ length: shown }, (_, index) => (
                <span
                    key={index}
                    className={`size-2 ${index < guests ? 'bg-primary' : 'border bg-background'}`}
                />
            ))}
            {seats > shown && (
                <span className="text-[10px] leading-2 text-muted-foreground">
                    +{seats - shown}
                </span>
            )}
        </span>
    );
}

function BusyTable({
    table,
    order,
    money,
    now,
}: {
    table: Table;
    order: Card;
    money: (minor: number) => string;
    now: number;
}) {
    const minutes = minutesSince(order.openedAt, now);
    const long = minutes >= LONG_MINUTES;

    return (
        <Link
            href={show({ order: order.id })}
            className={`group flex flex-col border-2 bg-card transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                order.hasReady ? 'border-emerald-500' : 'border-primary'
            }`}
        >
            <div
                className={`flex items-center justify-between px-3 py-1.5 text-xs font-medium ${
                    order.hasReady
                        ? 'bg-emerald-500 text-white'
                        : 'bg-primary text-primary-foreground'
                }`}
            >
                <span>{order.hasReady ? 'Food ready' : 'Seated'}</span>
                {order.hasReady ? (
                    <BellRing className="size-3.5" aria-hidden />
                ) : (
                    <span className="tabular-nums">{order.number}</span>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="flex items-baseline justify-between gap-2">
                    <span className="text-xl font-semibold tracking-tight">
                        {table.name}
                    </span>
                    <span className="text-sm font-semibold tabular-nums">
                        {money(order.totalMinor)}
                    </span>
                </div>
                <Seats seats={table.seats} guests={order.guests ?? 0} />
                <div className="mt-auto flex items-center justify-between gap-2 text-xs text-muted-foreground">
                    <span className="truncate">{order.openedBy}</span>
                    <span
                        className={`inline-flex shrink-0 items-center gap-1 tabular-nums ${
                            long
                                ? 'font-medium text-amber-700 dark:text-amber-300'
                                : ''
                        }`}
                    >
                        <Clock className="size-3" aria-hidden />
                        {duration(minutes)}
                    </span>
                </div>
            </div>
        </Link>
    );
}

function FreeTable({ table }: { table: Table }) {
    const [guests, setGuests] = useState(Math.min(2, table.seats));
    const set = (value: number) => setGuests(Math.max(1, Math.min(100, value)));

    return (
        <Form
            {...store.form()}
            className="flex flex-col border-2 border-dashed bg-background transition-colors hover:border-emerald-500"
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="service" value="dine_in" />
                    <input
                        type="hidden"
                        name="dining_table_id"
                        value={table.id}
                    />
                    <input type="hidden" name="guests" value={guests} />
                    <div className="flex items-center justify-between px-3 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                        <span>Free</span>
                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                            <Users className="size-3" aria-hidden />
                            {table.seats}
                        </span>
                    </div>
                    <div className="flex flex-1 flex-col gap-2 px-3 pb-3">
                        <span className="text-xl font-semibold tracking-tight">
                            {table.name}
                        </span>
                        <div className="mt-auto flex items-center gap-2">
                            <div
                                className="flex items-center border bg-card"
                                role="group"
                                aria-label={`Guests at ${table.name}`}
                            >
                                <button
                                    type="button"
                                    onClick={() => set(guests - 1)}
                                    disabled={guests <= 1}
                                    className="grid size-8 place-items-center hover:bg-muted disabled:text-muted-foreground"
                                    aria-label="One guest fewer"
                                >
                                    <Minus className="size-3.5" aria-hidden />
                                </button>
                                <span
                                    className="w-7 text-center text-sm font-medium tabular-nums"
                                    aria-live="polite"
                                >
                                    {guests}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => set(guests + 1)}
                                    className="grid size-8 place-items-center hover:bg-muted"
                                    aria-label="One guest more"
                                >
                                    <Plus className="size-3.5" aria-hidden />
                                </button>
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                className="flex-1"
                                disabled={processing}
                            >
                                Seat
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * The branch's tables, free or being served, and the takeaways still
 * open: a free table opens an order, a busy one goes to its order. It
 * refreshes itself, so food made ready in the kitchen shows up here.
 */
export default function Floor({ tables, takeaways }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const canHere = useCanHere();
    const now = useNow();
    usePoll(20_000, { only: ['tables', 'takeaways'] });

    const [showing, setShowing] = useState<Show>('all');
    const [area, setArea] = useState<string | null>(null);
    const areas = [...new Set(tables.map((table) => table.area ?? ''))];

    const busy = tables.filter((table) => table.order !== null);
    const ready =
        busy.filter((table) => table.order?.hasReady).length +
        takeaways.filter((order) => order.hasReady).length;
    const guests = busy.reduce(
        (sum, table) => sum + (table.order?.guests ?? 0),
        0,
    );
    const seats = tables.reduce((sum, table) => sum + table.seats, 0);
    const openMinor =
        busy.reduce((sum, table) => sum + (table.order?.totalMinor ?? 0), 0) +
        takeaways.reduce((sum, order) => sum + order.totalMinor, 0);

    const matches = (table: Table) =>
        (area === null || (table.area ?? '') === area) &&
        (showing === 'all' ||
            (showing === 'free' && table.order === null) ||
            (showing === 'busy' && table.order !== null) ||
            (showing === 'ready' && table.order?.hasReady === true));
    const filters: { value: Show; name: string; count: number }[] = [
        { value: 'all', name: 'All', count: tables.length },
        { value: 'free', name: 'Free', count: tables.length - busy.length },
        { value: 'busy', name: 'Seated', count: busy.length },
        {
            value: 'ready',
            name: 'Food ready',
            count: busy.filter((table) => table.order?.hasReady).length,
        },
    ];
    const chip = (active: boolean) =>
        `inline-flex items-center gap-1.5 border px-3 py-1 text-sm transition-colors ${
            active
                ? 'border-primary bg-primary text-primary-foreground'
                : 'bg-card hover:border-primary hover:bg-muted'
        }`;

    return (
        <>
            <Head title="Floor" />
            <PageHeader title="Floor" />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Summary
                    icon={Armchair}
                    label="Tables seated"
                    value={`${busy.length} of ${tables.length}`}
                />
                <Summary
                    icon={Users}
                    label="Guests"
                    value={`${guests} of ${seats} seats`}
                />
                <Summary
                    icon={BellRing}
                    label="Food ready to serve"
                    value={String(ready)}
                    tone={ready > 0 ? 'ready' : 'default'}
                />
                <Summary
                    icon={UtensilsCrossed}
                    label="Open on the floor"
                    value={money(openMinor)}
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0">
                    {tables.length === 0 ? (
                        <EmptyState
                            icon={Armchair}
                            title="No tables at this branch"
                            action={
                                canHere('restaurant.tables.manage') ? (
                                    <Button asChild>
                                        <Link href={tablesIndex()}>
                                            Set up tables
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <>
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                {filters.map((filter) => (
                                    <button
                                        key={filter.value}
                                        type="button"
                                        onClick={() => setShowing(filter.value)}
                                        aria-pressed={showing === filter.value}
                                        className={chip(
                                            showing === filter.value,
                                        )}
                                    >
                                        {filter.name}
                                        <span className="text-xs tabular-nums">
                                            {filter.count}
                                        </span>
                                    </button>
                                ))}
                                {areas.length > 1 && (
                                    <select
                                        value={area ?? ''}
                                        onChange={(event) =>
                                            setArea(
                                                event.target.value === ''
                                                    ? null
                                                    : event.target.value,
                                            )
                                        }
                                        aria-label="Area"
                                        className="ml-auto h-8 border bg-card px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <option value="">Every area</option>
                                        {areas.map((name) => (
                                            <option key={name} value={name}>
                                                {name || 'No area'}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>

                            {areas
                                .filter(
                                    (name) => area === null || name === area,
                                )
                                .map((name) => {
                                    const here = tables.filter(
                                        (table) =>
                                            (table.area ?? '') === name &&
                                            matches(table),
                                    );
                                    if (here.length === 0) {
                                        return null;
                                    }
                                    const all = tables.filter(
                                        (table) => (table.area ?? '') === name,
                                    );
                                    const seated = all.filter(
                                        (table) => table.order !== null,
                                    ).length;

                                    return (
                                        <section
                                            key={name || 'tables'}
                                            className="mb-5"
                                        >
                                            {areas.length > 1 && (
                                                <h2 className="mb-2 flex items-baseline gap-2 text-sm font-semibold">
                                                    {name || 'No area'}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        {seated} of {all.length}{' '}
                                                        seated
                                                    </span>
                                                </h2>
                                            )}
                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
                                                {here.map((table) =>
                                                    table.order ? (
                                                        <BusyTable
                                                            key={table.id}
                                                            table={table}
                                                            order={table.order}
                                                            money={money}
                                                            now={now}
                                                        />
                                                    ) : (
                                                        <FreeTable
                                                            key={table.id}
                                                            table={table}
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        </section>
                                    );
                                })}

                            {tables.filter(matches).length === 0 && (
                                <p className="border bg-card px-3 py-8 text-center text-sm text-muted-foreground">
                                    No tables to show here.
                                </p>
                            )}
                        </>
                    )}
                </div>

                <aside className="self-start border bg-card xl:sticky xl:top-4">
                    <div className="flex items-center justify-between border-b px-3 py-2">
                        <h2 className="text-sm font-semibold">Takeaways</h2>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {takeaways.length} open
                        </span>
                    </div>
                    <Form {...store.form()} className="border-b p-3">
                        {({ processing }) => (
                            <div className="flex gap-2">
                                <input
                                    type="hidden"
                                    name="service"
                                    value="takeaway"
                                />
                                <Input
                                    name="customer_name"
                                    placeholder="Customer name"
                                    aria-label="Name for the takeaway"
                                    className="h-9 min-w-0 flex-1"
                                />
                                <Button type="submit" disabled={processing}>
                                    <ShoppingBag
                                        className="size-4"
                                        aria-hidden
                                    />
                                    New
                                </Button>
                            </div>
                        )}
                    </Form>
                    {takeaways.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No takeaways waiting.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {takeaways.map((order) => (
                                <li key={order.id}>
                                    <Link
                                        href={show({ order: order.id })}
                                        className={`flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted ${
                                            order.hasReady
                                                ? 'border-l-4 border-emerald-500'
                                                : 'border-l-4 border-transparent'
                                        }`}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {order.customerName ??
                                                    order.number}
                                            </span>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {order.number} ·{' '}
                                                {duration(
                                                    minutesSince(
                                                        order.openedAt,
                                                        now,
                                                    ),
                                                )}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="block text-sm font-semibold tabular-nums">
                                                {money(order.totalMinor)}
                                            </span>
                                            {order.hasReady && (
                                                <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                    <BellRing
                                                        className="size-3"
                                                        aria-hidden
                                                    />
                                                    Ready
                                                </span>
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </aside>
            </div>
        </>
    );
}
