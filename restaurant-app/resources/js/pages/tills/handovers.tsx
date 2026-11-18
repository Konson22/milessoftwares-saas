import { Form, Head, Link, usePage } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { index as handoversIndex, receive } from '@/routes/handovers';
import { index as tills, show as showTill } from '@/routes/tills';

export type Handover = {
    id: string;
    number: string;
    status: 'pending' | 'received';
    tillId: string;
    till: string | null;
    amountMinor: number;
    note: string | null;
    handedBy: string;
    handedAt: string;
    receivedBy: string | null;
    receivedAt: string | null;
    receivedMinor: number | null;
    differenceMinor: number | null;
    receivingNote: string | null;
};

type Props = {
    status: 'pending' | 'received' | 'all';
    managing: boolean;
    handovers: (Handover & { canReceive: boolean })[];
};

const FILTERS = [
    { value: 'pending', label: 'Waiting' },
    { value: 'received', label: 'Received' },
    { value: 'all', label: 'All' },
] as const;

export function HandoverBadge({ status }: { status: Handover['status'] }) {
    return (
        <StatusBadge tone={status === 'pending' ? 'warning' : 'success'}>
            {status === 'pending' ? 'Waiting' : 'Received'}
        </StatusBadge>
    );
}

/** What was received, and any gap against what was handed over. */
export function HandoverReceipt({
    handover,
    money,
}: {
    handover: Handover;
    money: (minor: number) => string;
}) {
    return (
        <p>
            Received {money(handover.receivedMinor ?? 0)} by{' '}
            {handover.receivedBy}
            {handover.receivedAt &&
                `, ${new Date(handover.receivedAt).toLocaleString()}`}
            {handover.differenceMinor ? (
                <span className="font-medium text-red-700">
                    {' '}
                    ({money(handover.differenceMinor)})
                </span>
            ) : null}
            {handover.receivingNote && ` · “${handover.receivingNote}”`}
        </p>
    );
}

/**
 * Cash handed over from closed tills. A manager counts each and confirms
 * what they received; a difference needs a note. Cashiers see their own.
 */
export default function Handovers({ status, managing, handovers }: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const waiting = handovers
        .filter((handover) => handover.status === 'pending')
        .reduce((sum, handover) => sum + handover.amountMinor, 0);

    return (
        <>
            <Head title="Cash handovers" />
            <PageHeader
                title="Cash handovers"
                description={
                    managing
                        ? `${branch?.name}: cash from closed tills, to count and receive`
                        : `${branch?.name}: cash you handed over`
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={tills()}>← Shifts</Link>
                    </Button>
                }
            />

            <Panel>
                <PanelToolbar>
                    <div className="flex flex-wrap items-center gap-2">
                        {FILTERS.map((filter) => (
                            <Button
                                key={filter.value}
                                asChild
                                size="sm"
                                variant={
                                    status === filter.value
                                        ? 'default'
                                        : 'outline'
                                }
                            >
                                <Link
                                    href={handoversIndex(undefined, {
                                        query: { status: filter.value },
                                    })}
                                >
                                    {filter.label}
                                </Link>
                            </Button>
                        ))}
                        {waiting > 0 && (
                            <span className="text-sm text-muted-foreground">
                                {money(waiting)} waiting to be received
                            </span>
                        )}
                    </div>
                </PanelToolbar>

                {handovers.length === 0 ? (
                    <EmptyState
                        icon={HandCoins}
                        title={
                            status === 'pending'
                                ? 'No cash waiting to be received'
                                : 'No handovers'
                        }
                    />
                ) : (
                    <ul className="divide-y">
                        {handovers.map((handover) => (
                            <li
                                key={handover.id}
                                className="grid gap-3 px-4 py-3 md:grid-cols-[1fr_auto]"
                            >
                                <div className="text-sm">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {handover.number}
                                        </span>
                                        <HandoverBadge
                                            status={handover.status}
                                        />
                                        <span className="font-semibold tabular-nums">
                                            {money(handover.amountMinor)}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        From {handover.handedBy},{' '}
                                        {new Date(
                                            handover.handedAt,
                                        ).toLocaleString()}{' '}
                                        · shift{' '}
                                        <Link
                                            href={showTill({
                                                till: handover.tillId,
                                            })}
                                            className="hover:underline"
                                        >
                                            {handover.till}
                                        </Link>
                                    </p>
                                    {handover.note && (
                                        <p className="text-muted-foreground">
                                            “{handover.note}”
                                        </p>
                                    )}
                                    {handover.status === 'received' && (
                                        <HandoverReceipt
                                            handover={handover}
                                            money={money}
                                        />
                                    )}
                                </div>

                                {handover.canReceive && (
                                    <Form
                                        {...receive.form({
                                            handover: handover.id,
                                        })}
                                        options={{ preserveScroll: true }}
                                        className="flex flex-wrap items-start gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div className="grid gap-1">
                                                    <Input
                                                        name="received"
                                                        inputMode="decimal"
                                                        aria-label="Cash received"
                                                        placeholder="Cash counted"
                                                        required
                                                        className="w-36"
                                                    />
                                                    {errors.received && (
                                                        <p className="max-w-48 text-xs text-destructive">
                                                            {errors.received}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="grid gap-1">
                                                    <Input
                                                        name="receiving_note"
                                                        aria-label="Note"
                                                        placeholder="Note, if it differs"
                                                        className="w-48"
                                                    />
                                                    {errors.receiving_note && (
                                                        <p className="max-w-48 text-xs text-destructive">
                                                            {
                                                                errors.receiving_note
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Receive
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}
