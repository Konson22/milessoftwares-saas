import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CircleCheck,
    ExternalLink,
    Plus,
    Settings,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge, type Tone } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { cn } from '@miles/ui/lib/utils';
import { formatMoney } from '@/lib/money';
import { update } from '@/routes/branch';
import { index as overview } from '@/routes/overview';

type Today = {
    state: 'critical' | 'attention' | 'ok' | 'closed';
    shift: { number: string; openedAt: string } | null;
    salesMinor: number;
    litres: string;
    count: number;
    stockLitres: string;
    fill: number;
    tanks: number;
    lowTanks: number;
    attendants: number;
    staffedPumps: number;
    pumps: number;
    issues: { tone: string; text: string }[];
};

type Row = {
    id: string;
    name: string;
    branchCode: string;
    city: string | null;
    current: boolean;
    canManage: boolean;
    station: {
        code: string;
        active: boolean;
        main: boolean;
        collectionMode: string;
        hours: string | null;
        lowStockPercent: number;
    } | null;
    rostered: number;
    today: Today | null;
};

const STATE: Record<string, { label: string; tone: Tone }> = {
    critical: { label: 'Act now', tone: 'danger' },
    attention: { label: 'Attention', tone: 'warning' },
    ok: { label: 'Running', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
};

const COLLECTION: Record<string, string> = {
    attendant: 'Attendants collect',
    cashier: 'Cashier collects',
    both: 'Attendants or cashier',
};

/**
 * Every branch of the company and the petrol station it runs: set-up,
 * today's state, and the way into each one.
 */
export default function Stations({
    stations,
    canAddBranch,
}: {
    stations: Row[];
    today: string;
    canAddBranch: boolean;
}) {
    const { workspace, currency } = usePage().props;
    const [search, setSearch] = useState('');
    const money = (minor: number) => formatMoney(minor, currency ?? 'SSP');
    const structure = workspace
        ? new URL('structure', workspace.url).toString()
        : null;

    const query = search.trim().toLowerCase();
    const shown = stations.filter(
        (row) =>
            query === '' ||
            [row.name, row.branchCode, row.city, row.station?.code]
                .filter(Boolean)
                .some((text) => text!.toLowerCase().includes(query)),
    );
    const running = stations.filter(
        (row) => row.station?.active && row.today?.shift,
    ).length;
    const setUp = stations.filter((row) => row.station !== null).length;
    const sales = stations.reduce(
        (sum, row) => sum + (row.today?.salesMinor ?? 0),
        0,
    );
    const needing = stations.filter(
        (row) =>
            row.today?.state === 'critical' || row.today?.state === 'attention',
    ).length;

    return (
        <>
            <Head title="Stations" />
            <PageHeader
                title="Stations"
                actions={
                    canAddBranch &&
                    structure && (
                        <Button asChild>
                            <a href={structure}>
                                <Plus className="size-4" aria-hidden />
                                Add branch
                            </a>
                        </Button>
                    )
                }
            />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Branches"
                    value={stations.length}
                    hint={`${setUp} with a station set up`}
                    icon={Building2}
                    accent="slate"
                />
                <StatCard
                    label="Selling now"
                    value={`${running} of ${setUp}`}
                    hint="Stations with a shift open"
                    icon={CircleCheck}
                    accent={running < setUp ? 'amber' : 'emerald'}
                />
                <StatCard
                    label="Sales today"
                    value={money(sales)}
                    hint="All stations in this list"
                    icon={Building2}
                    accent="blue"
                    href={overview()}
                />
                <StatCard
                    label="Need attention"
                    value={needing}
                    hint="Stations with something to act on"
                    icon={AlertTriangle}
                    accent={needing > 0 ? 'red' : 'slate'}
                    href={overview()}
                />
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="All branches"
                    description={
                        structure
                            ? 'Branches are added and renamed on the workspace’s Structure page. A new branch shows here once it is saved there.'
                            : undefined
                    }
                    actions={
                        <SearchField
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Find a station"
                            aria-label="Find a station"
                            className="w-56"
                        />
                    }
                />
                {shown.length === 0 ? (
                    <PanelBody>
                        <EmptyState
                            icon={Building2}
                            title={
                                stations.length === 0
                                    ? 'No branches yet'
                                    : 'No station matches'
                            }
                        >
                            {stations.length === 0
                                ? 'Add the first branch on the workspace, then set up its station here.'
                                : null}
                        </EmptyState>
                    </PanelBody>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Branch
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Station
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Today
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Sales
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Tanks
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Staff
                                    </th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((row) => (
                                    <StationRow
                                        key={row.id}
                                        row={row}
                                        money={money}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}

function StationRow({
    row,
    money,
}: {
    row: Row;
    money: (minor: number) => string;
}) {
    const today = row.today;
    const state =
        row.station === null
            ? { label: 'Not set up', tone: 'neutral' as Tone }
            : STATE[today?.state ?? 'closed'];
    const issue = today?.issues.find((item) => item.tone !== 'info');

    return (
        <tr className="border-b align-top last:border-0">
            <td className="px-3 py-2.5">
                <p className="font-medium">
                    {row.name}
                    {row.current && (
                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                            working here
                        </span>
                    )}
                </p>
                <p className="text-xs text-muted-foreground">
                    {[row.branchCode, row.city].filter(Boolean).join(' · ')}
                </p>
            </td>
            <td className="px-3 py-2.5">
                {row.station ? (
                    <>
                        <p className="flex flex-wrap items-center gap-1.5">
                            <span className="font-medium">
                                {row.station.code}
                            </span>
                            {row.station.main && (
                                <StatusBadge tone="info">Main</StatusBadge>
                            )}
                            {!row.station.active && (
                                <StatusBadge>Inactive</StatusBadge>
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {COLLECTION[row.station.collectionMode] ??
                                row.station.collectionMode}
                            {row.station.hours ? ` · ${row.station.hours}` : ''}
                        </p>
                    </>
                ) : (
                    <span className="text-muted-foreground">
                        No station yet
                    </span>
                )}
            </td>
            <td className="px-3 py-2.5">
                <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
                <p className="mt-1 text-xs text-muted-foreground">
                    {row.station === null
                        ? 'Set up its station to start selling.'
                        : today?.shift
                          ? `Shift ${today.shift.number} open`
                          : 'No shift open'}
                </p>
                {issue && (
                    <p
                        className={cn(
                            'mt-0.5 text-xs',
                            issue.tone === 'critical'
                                ? 'text-red-700'
                                : 'text-amber-700',
                        )}
                    >
                        {issue.text}
                    </p>
                )}
            </td>
            <td className="px-3 py-2.5 text-right tabular-nums">
                {today ? (
                    <>
                        <p className="font-medium">{money(today.salesMinor)}</p>
                        <p className="text-xs text-muted-foreground">
                            {today.litres} L · {today.count} sales
                        </p>
                    </>
                ) : (
                    '—'
                )}
            </td>
            <td className="px-3 py-2.5">
                {today && today.tanks > 0 ? (
                    <div className="w-32">
                        <div className="h-2 overflow-hidden bg-muted">
                            <div
                                className={cn(
                                    'h-full',
                                    today.lowTanks > 0
                                        ? 'bg-amber-500'
                                        : 'bg-emerald-600',
                                )}
                                style={{
                                    width: `${Math.min(100, today.fill)}%`,
                                }}
                            />
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {today.fill}% · {today.tanks} tank
                            {today.tanks === 1 ? '' : 's'}
                            {today.lowTanks > 0
                                ? ` · ${today.lowTanks} low`
                                : ''}
                        </p>
                    </div>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        No tanks
                    </span>
                )}
            </td>
            <td className="px-3 py-2.5 text-xs text-muted-foreground">
                <p>
                    {row.rostered} attendant{row.rostered === 1 ? '' : 's'}
                </p>
                {today && today.pumps > 0 && (
                    <p>
                        {today.staffedPumps} of {today.pumps} pumps staffed
                    </p>
                )}
            </td>
            <td className="px-3 py-2.5">
                <div className="flex flex-wrap justify-end gap-1.5">
                    {row.station && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={overview({ query: { branch: row.id } })}
                            >
                                <ExternalLink
                                    className="size-3.5"
                                    aria-hidden
                                />
                                Operations
                            </Link>
                        </Button>
                    )}
                    {!row.current && (
                        <Form {...update.form()}>
                            <input
                                type="hidden"
                                name="branch_id"
                                value={row.id}
                            />
                            <Button
                                type="submit"
                                variant={
                                    row.station === null && row.canManage
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                            >
                                {row.station === null && row.canManage ? (
                                    <>
                                        <Settings
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                        Set up station
                                    </>
                                ) : (
                                    'Work here'
                                )}
                            </Button>
                        </Form>
                    )}
                </div>
            </td>
        </tr>
    );
}
