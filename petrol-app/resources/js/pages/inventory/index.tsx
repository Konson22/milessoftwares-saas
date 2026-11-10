import { Head, usePage } from '@inertiajs/react';
import { AlertTriangle, Droplets, Package, Truck } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { cn } from '@miles/ui/lib/utils';
import { Select } from '@/components/field';
import { formatMoney } from '@/lib/money';

type Moving = {
    id: string;
    product: string;
    quantity: string;
    from: string;
    to: string;
};

type Fuel = {
    tanks: {
        id: string;
        branchId: string;
        station: string;
        code: string;
        name: string;
        product: string;
        quantity: string;
        capacity: string;
        fill: number;
        valueMinor: number;
        low: boolean;
        water: boolean;
    }[];
    products: {
        name: string;
        quantity: string;
        capacity: string;
        fill: number;
        valueMinor: number;
        tanks: number;
        low: number;
        stations: number;
    }[];
    totalLitres: string;
    totalCapacity: string;
    totalValueMinor: number;
    inTransit: Moving[];
    deliveriesWaiting: number;
};

type Goods = {
    items: {
        id: string;
        name: string;
        code: string;
        unit: string;
        reorder: string | null;
        quantity: string;
        valueMinor: number;
        low: number;
        byStation: {
            branchId: string;
            station: string;
            quantity: string;
            low: boolean;
        }[];
    }[];
    stores: number;
    totalValueMinor: number;
    lowCount: number;
    inTransit: Moving[];
};

/** "1200.000" → "1,200"; keeps up to three decimals when there are any. */
function amount(text: string): string {
    return Number(text).toLocaleString(undefined, {
        maximumFractionDigits: 3,
    });
}

/**
 * What the company holds across its stations: fuel in the tanks, goods in
 * the stores, and what is between stations.
 */
export default function Inventory({
    stations,
    fuel,
    goods,
}: {
    stations: { id: string; name: string }[];
    fuel: Fuel | null;
    goods: Goods | null;
}) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'SSP');
    const [station, setStation] = useState('');
    const [search, setSearch] = useState('');
    const [lowOnly, setLowOnly] = useState(false);

    const query = search.trim().toLowerCase();
    const tanks = (fuel?.tanks ?? []).filter(
        (tank) =>
            (station === '' || tank.branchId === station) &&
            (!lowOnly || tank.low || tank.water) &&
            (query === '' ||
                tank.product.toLowerCase().includes(query) ||
                tank.code.toLowerCase().includes(query)),
    );
    const items = (goods?.items ?? [])
        .map((item) => ({
            ...item,
            byStation:
                station === ''
                    ? item.byStation
                    : item.byStation.filter((row) => row.branchId === station),
        }))
        .filter(
            (item) =>
                item.byStation.length > 0 &&
                (!lowOnly || item.byStation.some((row) => row.low)) &&
                (query === '' ||
                    item.name.toLowerCase().includes(query) ||
                    item.code.toLowerCase().includes(query)),
        );
    const moving = [...(fuel?.inTransit ?? []), ...(goods?.inTransit ?? [])];
    const lowTanks = (fuel?.tanks ?? []).filter((tank) => tank.low).length;

    return (
        <>
            <Head title="Inventory" />
            <PageHeader title="Inventory" />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {fuel && (
                    <StatCard
                        label="Fuel in tanks"
                        value={`${amount(fuel.totalLitres)} L`}
                        hint={`Worth ${money(fuel.totalValueMinor)} · ${amount(fuel.totalCapacity)} L capacity`}
                        icon={Droplets}
                        accent="emerald"
                    />
                )}
                {goods && (
                    <StatCard
                        label="Store goods"
                        value={money(goods.totalValueMinor)}
                        hint={`${goods.items.length} items in ${goods.stores} store${goods.stores === 1 ? '' : 's'}`}
                        icon={Package}
                        accent="violet"
                    />
                )}
                <StatCard
                    label="Running low"
                    value={lowTanks + (goods?.lowCount ?? 0)}
                    hint={`${lowTanks} tank${lowTanks === 1 ? '' : 's'} · ${goods?.lowCount ?? 0} store line${goods?.lowCount === 1 ? '' : 's'} at or below reorder`}
                    icon={AlertTriangle}
                    accent={
                        lowTanks + (goods?.lowCount ?? 0) > 0
                            ? 'amber'
                            : 'slate'
                    }
                />
                <StatCard
                    label="On the road"
                    value={moving.length}
                    hint={
                        fuel
                            ? `${fuel.deliveriesWaiting} supplier deliver${fuel.deliveriesWaiting === 1 ? 'y' : 'ies'} waiting to be received`
                            : 'Transfers dispatched, not yet received'
                    }
                    icon={Truck}
                    accent="slate"
                />
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-2 border bg-card p-2">
                <SearchField
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Fuel, tank or item"
                    aria-label="Find stock"
                    className="w-56"
                />
                {stations.length > 1 && (
                    <Select
                        aria-label="Station"
                        value={station}
                        onChange={(event) => setStation(event.target.value)}
                        placeholder="All stations"
                        options={stations.map((row) => ({
                            value: row.id,
                            label: row.name,
                        }))}
                        className="w-48"
                    />
                )}
                <label className="flex items-center gap-2 px-1 text-sm">
                    <input
                        type="checkbox"
                        checked={lowOnly}
                        onChange={(event) => setLowOnly(event.target.checked)}
                    />
                    Only what is running low
                </label>
            </div>

            {fuel && (
                <>
                    <Panel className="mt-4">
                        <PanelHeader title="Fuel by product" />
                        <PanelBody>
                            {fuel.products.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No active tanks.
                                </p>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {fuel.products.map((product) => (
                                        <div
                                            key={product.name}
                                            className="border p-3"
                                        >
                                            <div className="flex items-baseline justify-between gap-2">
                                                <p className="font-medium">
                                                    {product.name}
                                                </p>
                                                <p className="text-sm font-semibold tabular-nums">
                                                    {product.fill}%
                                                </p>
                                            </div>
                                            <Bar
                                                value={product.fill}
                                                warn={product.low > 0}
                                            />
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {amount(product.quantity)} L of{' '}
                                                {amount(product.capacity)} L ·{' '}
                                                {money(product.valueMinor)}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {product.tanks} tank
                                                {product.tanks === 1
                                                    ? ''
                                                    : 's'}{' '}
                                                at {product.stations} station
                                                {product.stations === 1
                                                    ? ''
                                                    : 's'}
                                                {product.low > 0 && (
                                                    <span className="text-amber-700">
                                                        {' '}
                                                        · {product.low} low
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </PanelBody>
                    </Panel>

                    <Panel className="mt-4">
                        <PanelHeader title="Tanks" />
                        {tanks.length === 0 ? (
                            <PanelBody>
                                <EmptyState
                                    icon={Droplets}
                                    title="No tank matches"
                                />
                            </PanelBody>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">
                                                Station
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Tank
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Fuel
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Level
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Litres
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Value
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tanks.map((tank) => (
                                            <tr
                                                key={tank.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-3 py-2">
                                                    {tank.station}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <span className="font-medium">
                                                        {tank.code}
                                                    </span>{' '}
                                                    <span className="text-xs text-muted-foreground">
                                                        {tank.name}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {tank.product}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <div className="w-24">
                                                            <Bar
                                                                value={
                                                                    tank.fill
                                                                }
                                                                warn={tank.low}
                                                            />
                                                        </div>
                                                        <span className="text-xs tabular-nums">
                                                            {tank.fill}%
                                                        </span>
                                                        {tank.water && (
                                                            <StatusBadge tone="danger">
                                                                Water
                                                            </StatusBadge>
                                                        )}
                                                        {tank.low && (
                                                            <StatusBadge tone="warning">
                                                                Low
                                                            </StatusBadge>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {amount(tank.quantity)}
                                                    <span className="text-xs text-muted-foreground">
                                                        {' '}
                                                        /{' '}
                                                        {amount(tank.capacity)}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {money(tank.valueMinor)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                </>
            )}

            {goods && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Store and shop goods"
                        description="Lubricants, parts and shop items, by station. Low means at or below the item's reorder level."
                    />
                    {items.length === 0 ? (
                        <PanelBody>
                            <EmptyState
                                icon={Package}
                                title={
                                    goods.items.length === 0
                                        ? 'No goods in any store yet'
                                        : 'No item matches'
                                }
                            />
                        </PanelBody>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Item
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            By station
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            Total
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            Reorder at
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            Value
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <td className="px-3 py-2">
                                                <p className="font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.code}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    {item.byStation.map(
                                                        (row) => (
                                                            <StatusBadge
                                                                key={
                                                                    row.branchId
                                                                }
                                                                tone={
                                                                    row.low
                                                                        ? 'warning'
                                                                        : 'neutral'
                                                                }
                                                            >
                                                                {row.station}{' '}
                                                                {amount(
                                                                    row.quantity,
                                                                )}
                                                            </StatusBadge>
                                                        ),
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {amount(item.quantity)}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {item.unit}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                                {item.reorder === null
                                                    ? '—'
                                                    : amount(item.reorder)}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {money(item.valueMinor)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            )}

            <Panel className="mt-4">
                <PanelHeader
                    title="On the road"
                    description="Transfers dispatched and not yet received."
                />
                <PanelBody>
                    {moving.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing is between stations.
                        </p>
                    ) : (
                        <ul className="grid gap-2 text-sm">
                            {moving.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap justify-between gap-2"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {row.product}
                                        </span>{' '}
                                        · {row.from} → {row.to}
                                    </span>
                                    <span className="tabular-nums">
                                        {amount(row.quantity)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}

function Bar({ value, warn }: { value: number; warn?: boolean }) {
    return (
        <div className="mt-1 h-2 overflow-hidden bg-muted">
            <div
                className={cn(
                    'h-full',
                    warn ? 'bg-amber-500' : 'bg-emerald-600',
                )}
                style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
            />
        </div>
    );
}
