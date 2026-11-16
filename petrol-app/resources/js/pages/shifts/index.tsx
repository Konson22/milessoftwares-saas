import { Form, Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowRight,
    CircleCheck,
    Clock,
    Droplets,
    Fuel,
    Hourglass,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
    XCircle,
} from "lucide-react";
import { useState, type ReactNode } from "react";
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from "recharts";
import { PageHeader } from "@miles/ui/components/page-header";
import { Panel, PanelBody, PanelHeader } from "@miles/ui/components/panel";
import { StatusBadge } from "@miles/ui/components/status-badge";
import { Button } from "@miles/ui/components/ui/button";
import { Input } from "@miles/ui/components/ui/input";
import { cn } from "@miles/ui/lib/utils";
import { Field, Select } from "@/components/field";
import { useMoney } from "@/lib/use-money";
import { index, show, store } from "@/routes/shifts";
import { Empty, shortDate, ViewToggle } from "../overview/charts";

type Status =
    "open" | "closing_submitted" | "under_review" | "approved" | "rejected";

const STATUS: Record<
    Status,
    { label: string; tone: "info" | "warning" | "success" | "danger" }
> = {
    open: { label: "Open", tone: "info" },
    closing_submitted: { label: "Submitted", tone: "warning" },
    under_review: { label: "Under review", tone: "warning" },
    approved: { label: "Approved", tone: "success" },
    rejected: { label: "Rejected", tone: "danger" },
};

type Row = {
    id: string;
    number: string;
    status: Status;
    businessDate: string;
    schedule: string | null;
    openedAt: string;
    closedAt: string | null;
    openedBy: string | null;
    submittedBy: string | null;
    approvedBy: string | null;
    salesMinor: number;
    litres: string;
    salesCount: number;
    expectedMinor: number | null;
    countedMinor: number | null;
    differenceMinor: number | null;
};

type Current = {
    id: string;
    number: string;
    schedule: string | null;
    openedAt: string;
    openedBy: string | null;
    floatMinor: number;
    drawerMinor: number;
    salesMinor: number;
    litres: string;
    salesCount: number;
    crew: { name: string; nozzles: string[] }[];
    pumpsStaffed: number;
    nozzles: number;
};

type Closing = {
    id: string;
    number: string;
    businessDate: string;
    schedule: string | null;
    differenceMinor: number;
    expectedMinor: number;
};

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const FILTERS = [
    { value: "", label: "All" },
    { value: "open", label: "Open" },
    { value: "waiting", label: "To review" },
    { value: "approved", label: "Approved" },
    { value: "rejected", label: "Rejected" },
];

export default function Shifts({
    shifts,
    status,
    current,
    counts,
    closings,
    schedules,
    canOpen,
    currencies = [],
    books,
}: {
    shifts: Page<Row>;
    status: string;
    current: Current | null;
    counts: { waiting: number; rejected: number };
    closings: Closing[];
    schedules: {
        id: string;
        name: string;
        starts_at: string;
        ends_at: string;
    }[];
    canOpen: boolean;
    currencies?: { code: string; name: string }[];
    books?: string;
}) {
    const money = useMoney();
    const short = closings.filter((row) => row.differenceMinor < 0);
    const net = closings.reduce((sum, row) => sum + row.differenceMinor, 0);
    const balanced = closings.filter((row) => row.differenceMinor === 0).length;

    return (
        <>
            <Head title="Shifts" />
            <PageHeader
                title="Shifts"
                description="One shift is open at a time. Whoever submits a closing cannot approve it."
            />

            {current ? (
                <CurrentShift shift={current} money={money} />
            ) : canOpen ? (
                <OpenShift
                    schedules={schedules}
                    currencies={currencies}
                    books={books}
                />
            ) : (
                <Panel className="mb-4">
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            No shift is open at this station.
                        </p>
                    </PanelBody>
                </Panel>
            )}

            {/* How closings have counted lately */}
            <div className="mt-4 grid grid-cols-2 border bg-card xl:grid-cols-4 [&>*]:border-r [&>*]:border-b">
                <Pulse
                    icon={Hourglass}
                    label="Waiting for review"
                    value={String(counts.waiting)}
                    note={
                        counts.waiting > 0
                            ? "Closings to approve"
                            : "Nothing waiting"
                    }
                    warn={counts.waiting > 0}
                    href={
                        counts.waiting > 0
                            ? index({ query: { status: "waiting" } }).url
                            : undefined
                    }
                />
                <Pulse
                    icon={CircleCheck}
                    label="Counted exactly"
                    value={`${balanced} of ${closings.length}`}
                    note="Last approved closings"
                />
                <Pulse
                    icon={TrendingDown}
                    label="Short closings"
                    value={String(short.length)}
                    note={
                        short.length > 0
                            ? `${money(-short.reduce((sum, row) => sum + row.differenceMinor, 0))} short in all`
                            : "None lately"
                    }
                    bad={short.length > 0}
                />
                <Pulse
                    icon={net < 0 ? TrendingDown : TrendingUp}
                    label="Net difference"
                    value={`${net > 0 ? "+" : ""}${money(net)}`}
                    note={
                        counts.rejected > 0
                            ? `${counts.rejected} closing${counts.rejected === 1 ? "" : "s"} rejected`
                            : "Counted minus expected"
                    }
                    bad={net < 0}
                />
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Cash counted against expected"
                    description={`Last ${closings.length} approved closings. Below the line is short, above is over.`}
                />
                <PanelBody>
                    <Differences closings={closings} money={money} />
                </PanelBody>
            </Panel>

            {/* Every shift */}
            <Panel className="mt-4">
                <div
                    className="flex flex-wrap items-center gap-1 border-b p-2"
                    role="group"
                    aria-label="Show shifts"
                >
                    {FILTERS.map((filter) => (
                        <Button
                            key={filter.value}
                            size="sm"
                            variant={
                                status === filter.value ? "default" : "ghost"
                            }
                            aria-pressed={status === filter.value}
                            onClick={() =>
                                router.get(
                                    index.url(),
                                    filter.value
                                        ? { status: filter.value }
                                        : {},
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        >
                            {filter.label}
                            {filter.value === "waiting" &&
                                counts.waiting > 0 && (
                                    <span className="ml-1 rounded-full bg-amber-500 px-1.5 text-[10px] text-white">
                                        {counts.waiting}
                                    </span>
                                )}
                        </Button>
                    ))}
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        {shifts.total.toLocaleString()} shift
                        {shifts.total === 1 ? "" : "s"}
                    </span>
                </div>

                {shifts.data.length === 0 ? (
                    <div className="p-6">
                        <Empty>No shifts here.</Empty>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40 text-xs text-muted-foreground">
                                <tr>
                                    <Th>Shift</Th>
                                    <Th>Hours</Th>
                                    <Th>People</Th>
                                    <Th right>Sales</Th>
                                    <Th right>Litres</Th>
                                    <Th right>Expected cash</Th>
                                    <Th right>Counted</Th>
                                    <Th right>Difference</Th>
                                    <Th>Status</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {shifts.data.map((shift) => (
                                    <tr
                                        key={shift.id}
                                        className="cursor-pointer border-b last:border-0 hover:bg-muted/40"
                                        onClick={() =>
                                            router.visit(show(shift.id))
                                        }
                                    >
                                        <Td>
                                            <Link
                                                href={show(shift.id)}
                                                className="font-medium hover:underline"
                                                onClick={(event) =>
                                                    event.stopPropagation()
                                                }
                                            >
                                                {shift.number}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {longDate(shift.businessDate)}
                                                {shift.schedule
                                                    ? ` · ${shift.schedule}`
                                                    : ""}
                                            </span>
                                        </Td>
                                        <Td>
                                            <span className="tabular-nums">
                                                {time(shift.openedAt)} –{" "}
                                                {shift.closedAt
                                                    ? time(shift.closedAt)
                                                    : "now"}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {duration(
                                                    shift.openedAt,
                                                    shift.closedAt,
                                                )}
                                            </span>
                                        </Td>
                                        <Td>
                                            <span className="block text-xs">
                                                <span className="text-muted-foreground">
                                                    Opened{" "}
                                                </span>
                                                {shift.openedBy ?? "—"}
                                            </span>
                                            {shift.approvedBy && (
                                                <span className="block text-xs">
                                                    <span className="text-muted-foreground">
                                                        Approved{" "}
                                                    </span>
                                                    {shift.approvedBy}
                                                </span>
                                            )}
                                        </Td>
                                        <Td right>
                                            <span className="font-medium">
                                                {money(shift.salesMinor)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {shift.salesCount} sales
                                            </span>
                                        </Td>
                                        <Td right>
                                            {Number(
                                                shift.litres,
                                            ).toLocaleString(undefined, {
                                                maximumFractionDigits: 1,
                                            })}
                                        </Td>
                                        <Td right>
                                            {shift.expectedMinor === null
                                                ? "—"
                                                : money(shift.expectedMinor)}
                                        </Td>
                                        <Td right>
                                            {shift.countedMinor === null
                                                ? "—"
                                                : money(shift.countedMinor)}
                                        </Td>
                                        <Td right>
                                            <Difference
                                                minor={shift.differenceMinor}
                                                money={money}
                                            />
                                        </Td>
                                        <Td>
                                            <StatusBadge
                                                tone={STATUS[shift.status].tone}
                                            >
                                                {STATUS[shift.status].label}
                                            </StatusBadge>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {shifts.last_page > 1 && (
                    <div className="flex items-center justify-between gap-2 border-t px-3 py-2 text-xs text-muted-foreground">
                        <span className="tabular-nums">
                            {shifts.from}–{shifts.to} of{" "}
                            {shifts.total.toLocaleString()}
                        </span>
                        <div className="flex items-center gap-1">
                            <PageLink href={shifts.prev_page_url}>
                                Previous
                            </PageLink>
                            <span className="px-2 tabular-nums">
                                Page {shifts.current_page} of {shifts.last_page}
                            </span>
                            <PageLink href={shifts.next_page_url}>
                                Next
                            </PageLink>
                        </div>
                    </div>
                )}
            </Panel>
        </>
    );
}

/** The open shift, live: what it has sold, what the drawer should hold, who is on the pumps. */
function CurrentShift({
    shift,
    money,
}: {
    shift: Current;
    money: (minor: number) => string;
}) {
    return (
        <section className="border border-primary/30 bg-card">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-primary/5 px-4 py-3">
                <div>
                    <p className="flex items-center gap-2 text-base font-semibold">
                        <span className="relative flex size-2.5">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60" />
                            <span className="relative inline-flex size-2.5 rounded-full bg-emerald-500" />
                        </span>
                        Shift {shift.number} is open
                        {shift.schedule && (
                            <span className="font-normal text-muted-foreground">
                                · {shift.schedule}
                            </span>
                        )}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Opened {time(shift.openedAt)}
                        {shift.openedBy ? ` by ${shift.openedBy}` : ""} ·
                        running {duration(shift.openedAt, null)}
                    </p>
                </div>
                <Button asChild>
                    <Link href={show(shift.id)}>
                        Readings and closing
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                </Button>
            </header>
            <div className="grid sm:grid-cols-2 xl:grid-cols-4 [&>*]:border-b sm:[&>*]:border-r">
                <Big
                    icon={Fuel}
                    label="Sold so far"
                    value={money(shift.salesMinor)}
                    note={`${shift.salesCount} sales`}
                />
                <Big
                    icon={Droplets}
                    label="Litres"
                    value={`${shift.litres} L`}
                />
                <Big
                    icon={Wallet}
                    label="Cash expected in the drawer"
                    value={money(shift.drawerMinor)}
                    note={`Float ${money(shift.floatMinor)}`}
                />
                <Big
                    icon={Users}
                    label="Pumps staffed"
                    value={`${shift.pumpsStaffed} of ${shift.nozzles}`}
                    note={`${shift.crew.length} attendant${shift.crew.length === 1 ? "" : "s"}`}
                    warn={shift.pumpsStaffed < shift.nozzles}
                />
            </div>
            {shift.crew.length > 0 && (
                <ul className="flex flex-wrap gap-2 px-4 py-3">
                    {shift.crew.map((person) => (
                        <li
                            key={person.name}
                            className="flex items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm"
                        >
                            <span className="grid size-6 place-items-center rounded-full bg-muted text-[11px] font-semibold">
                                {initials(person.name)}
                            </span>
                            {person.name}
                            <span className="font-mono text-xs text-muted-foreground">
                                {person.nozzles.join(", ")}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function OpenShift({
    schedules,
    currencies,
    books,
}: {
    schedules: {
        id: string;
        name: string;
        starts_at: string;
        ends_at: string;
    }[];
    currencies: { code: string; name: string }[];
    books?: string;
}) {
    const { errors } = usePage().props;

    return (
        <Panel>
            <PanelHeader
                title="No shift is open"
                description="Count the float into the drawer, then open the shift. Meter readings carry on from the last closing."
            />
            <PanelBody>
                <Form {...store.form()} className="grid gap-3 md:grid-cols-3">
                    <Field
                        id="opening_float"
                        label="Opening float"
                        error={errors.opening_float}
                    >
                        <Input
                            id="opening_float"
                            name="opening_float"
                            inputMode="decimal"
                            required
                        />
                    </Field>
                    <Field id="schedule_id" label="Schedule">
                        <Select
                            id="schedule_id"
                            name="schedule_id"
                            placeholder="None"
                            options={schedules.map((row) => ({
                                value: row.id,
                                label: `${row.name} ${row.starts_at}–${row.ends_at}`,
                            }))}
                        />
                    </Field>
                    {currencies
                        .filter((row) => row.code !== books)
                        .map((row, i) => (
                            <Field
                                key={row.code}
                                id={`float-${row.code}`}
                                label={`Opening float (${row.code})`}
                            >
                                <input
                                    type="hidden"
                                    name={`foreign_floats[${i}][currency]`}
                                    value={row.code}
                                />
                                <Input
                                    id={`float-${row.code}`}
                                    name={`foreign_floats[${i}][amount]`}
                                    inputMode="decimal"
                                    defaultValue="0.00"
                                />
                            </Field>
                        ))}
                    <Field
                        id="handover_note"
                        label="Handover note"
                        className="md:col-span-3"
                    >
                        <Input
                            id="handover_note"
                            name="handover_note"
                            placeholder="Anything the next shift should know"
                        />
                    </Field>
                    <div>
                        <Button type="submit">
                            <Clock className="size-4" aria-hidden />
                            Open shift
                        </Button>
                    </div>
                </Form>
            </PanelBody>
        </Panel>
    );
}

/**
 * Counted minus expected per closing: a polarity, so two sides of a zero
 * line, red short and blue over, with the sign in the tooltip and table.
 */
function Differences({
    closings,
    money,
}: {
    closings: Closing[];
    money: (minor: number) => string;
}) {
    const [table, setTable] = useState(false);
    if (closings.length === 0) {
        return <Empty>No approved closings yet.</Empty>;
    }
    const label = (row: Closing) =>
        `${shortDate(row.businessDate)}${row.schedule ? ` ${row.schedule}` : ""}`;
    const signed = (minor: number) => `${minor > 0 ? "+" : ""}${money(minor)}`;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <li className="flex items-center gap-1.5">
                        <span
                            className="size-2.5 rounded-sm"
                            style={{ background: "var(--viz-bad)" }}
                            aria-hidden
                        />
                        Short
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span
                            className="size-2.5 rounded-sm"
                            style={{ background: "var(--viz-1)" }}
                            aria-hidden
                        />
                        Over
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span
                            className="h-px w-3 bg-muted-foreground"
                            aria-hidden
                        />
                        Exact
                    </li>
                </ul>
                <ViewToggle table={table} onChange={setTable} />
            </div>
            {table ? (
                <div className="max-h-64 overflow-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 text-left font-medium">
                                    Shift
                                </th>
                                <th className="py-1.5 font-medium">Expected</th>
                                <th className="py-1.5 font-medium">
                                    Difference
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...closings].reverse().map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="py-1.5 text-left">
                                        {row.number} · {label(row)}
                                    </td>
                                    <td className="py-1.5">
                                        {money(row.expectedMinor)}
                                    </td>
                                    <td className="py-1.5">
                                        <Difference
                                            minor={row.differenceMinor}
                                            money={money}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    className="h-56"
                    role="img"
                    aria-label="Cash differences of recent closings"
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={closings}
                            margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                            barCategoryGap="20%"
                            onClick={(state) => {
                                const row =
                                    closings[Number(state?.activeTooltipIndex)];
                                if (row) {
                                    router.visit(show(row.id));
                                }
                            }}
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--viz-grid)"
                            />
                            <XAxis
                                dataKey="businessDate"
                                tickFormatter={shortDate}
                                minTickGap={24}
                                stroke="var(--viz-grid)"
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                                tickLine={false}
                            />
                            <YAxis
                                tickFormatter={(minor: number) =>
                                    new Intl.NumberFormat(undefined, {
                                        notation: "compact",
                                        maximumFractionDigits: 1,
                                        signDisplay: "exceptZero",
                                    }).format(minor / 100)
                                }
                                width={48}
                                axisLine={false}
                                tickLine={false}
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                            />
                            <ReferenceLine y={0} stroke="var(--viz-axis)" />
                            <Tooltip
                                cursor={{ fill: "var(--muted)", opacity: 0.6 }}
                                content={({ active, payload }) => {
                                    const row = payload?.[0]?.payload as
                                        Closing | undefined;
                                    if (!active || !row) {
                                        return null;
                                    }

                                    return (
                                        <div className="min-w-44 border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
                                            <p className="mb-1 font-semibold">
                                                {row.number} · {label(row)}
                                            </p>
                                            <p className="flex justify-between gap-4 tabular-nums">
                                                <span className="text-muted-foreground">
                                                    Expected
                                                </span>
                                                {money(row.expectedMinor)}
                                            </p>
                                            <p className="flex justify-between gap-4 font-semibold tabular-nums">
                                                <span className="font-normal text-muted-foreground">
                                                    {row.differenceMinor < 0
                                                        ? "Short"
                                                        : row.differenceMinor >
                                                            0
                                                          ? "Over"
                                                          : "Exact"}
                                                </span>
                                                {signed(row.differenceMinor)}
                                            </p>
                                        </div>
                                    );
                                }}
                            />
                            <Bar
                                dataKey="differenceMinor"
                                maxBarSize={24}
                                radius={4}
                                isAnimationActive={false}
                                cursor="pointer"
                            >
                                {closings.map((row) => (
                                    <Cell
                                        key={row.id}
                                        fill={
                                            row.differenceMinor < 0
                                                ? "var(--viz-bad)"
                                                : "var(--viz-1)"
                                        }
                                    />
                                ))}
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

/** A signed difference with an icon, so short and over never rely on colour. */
function Difference({
    minor,
    money,
}: {
    minor: number | null;
    money: (minor: number) => string;
}) {
    if (minor === null) {
        return <span className="text-muted-foreground">—</span>;
    }
    if (minor === 0) {
        return (
            <span className="inline-flex items-center gap-1 text-emerald-700">
                <CircleCheck className="size-3.5" aria-hidden />
                Exact
            </span>
        );
    }

    return (
        <span
            className={cn(
                "inline-flex items-center gap-1 font-medium tabular-nums",
                minor < 0 ? "text-red-700" : "text-blue-700",
            )}
        >
            {minor < 0 ? (
                <XCircle className="size-3.5" aria-hidden />
            ) : (
                <TrendingUp className="size-3.5" aria-hidden />
            )}
            {minor > 0 ? "+" : ""}
            {money(minor)}
        </span>
    );
}

function Big({
    icon: Icon,
    label,
    value,
    note,
    warn,
}: {
    icon: typeof Fuel;
    label: string;
    value: string;
    note?: string;
    warn?: boolean;
}) {
    return (
        <div className="px-4 py-3">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon
                    className={cn("size-3.5", warn && "text-amber-600")}
                    aria-hidden
                />
                {label}
            </p>
            <p className="mt-0.5 text-xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {note && (
                <p
                    className={cn(
                        "text-xs",
                        warn ? "text-amber-700" : "text-muted-foreground",
                    )}
                >
                    {note}
                </p>
            )}
        </div>
    );
}

function Pulse({
    icon: Icon,
    label,
    value,
    note,
    warn,
    bad,
    href,
}: {
    icon: typeof Fuel;
    label: string;
    value: string;
    note?: string;
    warn?: boolean;
    bad?: boolean;
    href?: string;
}) {
    const body = (
        <>
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon
                    className={cn(
                        "size-3.5",
                        bad
                            ? "text-red-600"
                            : warn
                              ? "text-amber-600"
                              : undefined,
                    )}
                    aria-hidden
                />
                {label}
            </p>
            <p className="mt-0.5 truncate text-lg font-semibold tabular-nums">
                {value}
            </p>
            {note && (
                <p
                    className={cn(
                        "truncate text-xs",
                        bad
                            ? "text-red-700"
                            : warn
                              ? "text-amber-700"
                              : "text-muted-foreground",
                    )}
                >
                    {note}
                </p>
            )}
        </>
    );

    return href ? (
        <Link href={href} className="block px-4 py-3 hover:bg-muted/40">
            {body}
        </Link>
    ) : (
        <div className="px-4 py-3">{body}</div>
    );
}

function PageLink({
    href,
    children,
}: {
    href: string | null;
    children: ReactNode;
}) {
    return href ? (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll>
                {children}
            </Link>
        </Button>
    ) : (
        <Button variant="outline" size="sm" disabled>
            {children}
        </Button>
    );
}

function Th({ children, right }: { children: ReactNode; right?: boolean }) {
    return (
        <th
            className={cn(
                "px-3 py-2 font-medium whitespace-nowrap",
                right ? "text-right" : "text-left",
            )}
        >
            {children}
        </th>
    );
}

function Td({ children, right }: { children: ReactNode; right?: boolean }) {
    return (
        <td
            className={cn(
                "px-3 py-2 whitespace-nowrap",
                right && "text-right tabular-nums",
            )}
        >
            {children}
        </td>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join("");
}

/** "Sat 14 Nov" from "2026-11-14". */
function longDate(date: string): string {
    const [y, m, d] = date.split("-").map(Number);

    return new Date(y, m - 1, d).toLocaleDateString([], {
        weekday: "short",
        day: "numeric",
        month: "short",
    });
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
}

/** "7 h 40 min" from an opening to a closing (or now). */
function duration(from: string, to: string | null): string {
    const minutes = Math.max(
        0,
        Math.round(
            ((to ? Date.parse(to) : Date.now()) - Date.parse(from)) / 60_000,
        ),
    );
    const hours = Math.floor(minutes / 60);

    return hours > 0 ? `${hours} h ${minutes % 60} min` : `${minutes} min`;
}
