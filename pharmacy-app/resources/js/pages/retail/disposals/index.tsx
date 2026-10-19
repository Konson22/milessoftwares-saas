import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { usePage } from '@inertiajs/react';
import { statusTone } from '@/lib/status';
import { formatMoney } from '@/lib/money';
import { approve, index, reject } from '@/routes/retail/disposals';
import { show as stockShow } from '@/routes/retail/stock';

type Disposal = {
    id: string;
    number: string;
    itemId: string;
    medicine: string;
    batchNumber: string;
    expiresOn: string | null;
    quantity: number;
    costMinor: number;
    reason: string;
    note: string | null;
    requestedBy: string;
    requestedAt: string;
    mine: boolean;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
};

type Props = {
    status: 'pending' | 'approved' | 'rejected';
    disposals: Disposal[];
    canDecide: boolean;
};

const TABS = [
    { value: 'pending', label: 'Waiting' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Turned down' },
] as const;

/**
 * Stock asked to be destroyed, and the decisions on it.
 */
export default function Disposals({ status, disposals, canDecide }: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Write-offs" />
            <PageHeader
                title="Write-offs"
                description={`Stock written off in ${branch?.name ?? 'this branch'} (damaged, expired, recalled): asked for from a product's stock page, approved by someone else.`}
            />

            <Panel>
                <PanelToolbar>
                    <nav aria-label="Disposal status" className="flex gap-1">
                        {TABS.map((tab) => (
                            <Button
                                key={tab.value}
                                variant={
                                    status === tab.value ? 'secondary' : 'ghost'
                                }
                                size="sm"
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: { status: tab.value },
                                    })}
                                >
                                    {tab.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </PanelToolbar>

                {disposals.length === 0 ? (
                    <EmptyState icon={Trash2} title="Nothing here" />
                ) : (
                    <ul className="divide-y">
                        {disposals.map((disposal) => (
                            <li key={disposal.id} className="grid gap-2 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {disposal.number}
                                    </span>
                                    <StatusBadge tone={statusTone(status)}>
                                        {status}
                                    </StatusBadge>
                                    <Link
                                        href={stockShow(disposal.itemId)}
                                        className="hover:underline"
                                    >
                                        {disposal.quantity} ×{' '}
                                        {disposal.medicine}
                                    </Link>
                                    <span className="text-sm text-muted-foreground">
                                        batch {disposal.batchNumber}, exp{' '}
                                        {disposal.expiresOn ?? '—'}
                                    </span>
                                    <span className="ml-auto tabular-nums">
                                        {money(disposal.costMinor)} at cost
                                    </span>
                                </div>
                                <p className="text-sm">
                                    {disposal.reason}
                                    {disposal.note &&
                                        ` · “${disposal.note}”`}{' '}
                                    <span className="text-muted-foreground">
                                        · asked by {disposal.requestedBy},{' '}
                                        {new Date(
                                            disposal.requestedAt,
                                        ).toLocaleString()}
                                    </span>
                                </p>
                                {disposal.decidedBy && (
                                    <p className="text-sm text-muted-foreground">
                                        Decided by {disposal.decidedBy}
                                        {disposal.decidedAt &&
                                            `, ${new Date(disposal.decidedAt).toLocaleString()}`}
                                        {disposal.decisionNote &&
                                            `: ${disposal.decisionNote}`}
                                    </p>
                                )}
                                {status === 'pending' &&
                                    canDecide &&
                                    (disposal.mine ? (
                                        <p className="text-sm text-muted-foreground">
                                            You asked for this one: someone else
                                            decides.
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap items-start gap-2">
                                            <Form
                                                {...approve.form(disposal.id)}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Approve and write off
                                                    </Button>
                                                )}
                                            </Form>
                                            <Form
                                                {...reject.form(disposal.id)}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex flex-wrap items-start gap-2"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <div>
                                                            <Input
                                                                name="note"
                                                                required
                                                                placeholder="Why not?"
                                                                aria-label={`Why disposal ${disposal.number} is turned down`}
                                                                className="h-8 w-56"
                                                            />
                                                            {(errors.note ||
                                                                errors.disposal) && (
                                                                <p className="text-xs text-destructive">
                                                                    {errors.note ??
                                                                        errors.disposal}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Turn down
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </div>
                                    ))}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}
