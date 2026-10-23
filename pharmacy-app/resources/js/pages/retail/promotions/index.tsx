import { Form, Head } from '@inertiajs/react';
import { Percent } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { end, store } from '@/routes/retail/promotions';

type Props = {
    promotions: {
        id: string;
        name: string;
        percentOff: number;
        on: string;
        branch: string;
        startsOn: string;
        endsOn: string | null;
        status: 'running' | 'upcoming' | 'ended';
        createdBy: string;
    }[];
    items: { id: string; label: string }[];
    categories: string[];
    branches: { id: string; name: string }[];
    today: string;
};

const tones = {
    running: 'success',
    upcoming: 'info',
    ended: 'neutral',
} as const;

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

/**
 * Percentages off a product or a category, for some days, in every branch
 * or one. The till takes them off by itself.
 */
export default function Promotions({
    promotions,
    items,
    categories,
    branches,
    today,
}: Props) {
    const [on, setOn] = useState<'item' | 'category'>('item');

    return (
        <>
            <Head title="Promotions" />
            <PageHeader
                title="Promotions"
                description="Taken off at the till by itself while it runs. A seller can still give more, within their discount limit."
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    {promotions.length === 0 ? (
                        <EmptyState icon={Percent} title="No promotions yet">
                            Put a product, or a whole category, on offer for a
                            few days.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Promotion</TableHead>
                                    <TableHead className="text-right">
                                        Off
                                    </TableHead>
                                    <TableHead>When</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {promotions.map((promotion) => (
                                    <TableRow key={promotion.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {promotion.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {promotion.on},{' '}
                                                {promotion.branch}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {promotion.percentOff}%
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {promotion.startsOn}
                                            {' – '}
                                            {promotion.endsOn ?? 'until ended'}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge
                                                    tone={
                                                        tones[promotion.status]
                                                    }
                                                >
                                                    {promotion.status}
                                                </StatusBadge>
                                                {promotion.status !==
                                                    'ended' && (
                                                    <Form
                                                        {...end.form(
                                                            promotion.id,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                End
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader title="New promotion" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            resetOnSuccess
                            className="grid gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            placeholder="Back to school"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">
                                                {errors.name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="percent_off">
                                            Percent off
                                        </Label>
                                        <Input
                                            id="percent_off"
                                            name="percent_off"
                                            type="number"
                                            min={1}
                                            max={100}
                                            required
                                            className="w-28"
                                        />
                                        {errors.percent_off && (
                                            <p className="text-sm text-destructive">
                                                {errors.percent_off}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>On</Label>
                                        <div className="flex gap-3 text-sm">
                                            <label className="flex items-center gap-1.5">
                                                <input
                                                    type="radio"
                                                    checked={on === 'item'}
                                                    onChange={() =>
                                                        setOn('item')
                                                    }
                                                />
                                                A product
                                            </label>
                                            <label className="flex items-center gap-1.5">
                                                <input
                                                    type="radio"
                                                    checked={on === 'category'}
                                                    onChange={() =>
                                                        setOn('category')
                                                    }
                                                    disabled={
                                                        categories.length === 0
                                                    }
                                                />
                                                A category
                                            </label>
                                        </div>
                                        {on === 'item' ? (
                                            <select
                                                name="item_id"
                                                aria-label="Product"
                                                className={selectClass}
                                            >
                                                {items.map((item) => (
                                                    <option
                                                        key={item.id}
                                                        value={item.id}
                                                    >
                                                        {item.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <select
                                                name="category"
                                                aria-label="Category"
                                                className={selectClass}
                                            >
                                                {categories.map((category) => (
                                                    <option
                                                        key={category}
                                                        value={category}
                                                    >
                                                        {category}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        {(errors.item_id ||
                                            errors.category) && (
                                            <p className="text-sm text-destructive">
                                                {errors.item_id ??
                                                    errors.category}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="branch_id">Where</Label>
                                        <select
                                            id="branch_id"
                                            name="branch_id"
                                            defaultValue=""
                                            className={selectClass}
                                        >
                                            <option value="">
                                                Every branch
                                            </option>
                                            {branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {branch.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="starts_on">
                                                From
                                            </Label>
                                            <Input
                                                id="starts_on"
                                                name="starts_on"
                                                type="date"
                                                required
                                                defaultValue={today}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="ends_on">
                                                Until
                                            </Label>
                                            <Input
                                                id="ends_on"
                                                name="ends_on"
                                                type="date"
                                            />
                                        </div>
                                    </div>
                                    {(errors.starts_on || errors.ends_on) && (
                                        <p className="text-sm text-destructive">
                                            {errors.starts_on ?? errors.ends_on}
                                        </p>
                                    )}
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || items.length === 0
                                        }
                                    >
                                        Start the promotion
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
