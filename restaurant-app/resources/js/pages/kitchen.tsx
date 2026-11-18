import { Form, Head, router } from '@inertiajs/react';
import { ChefHat } from 'lucide-react';
import { useEffect } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { statusTone } from '@/lib/status';
import { clear, ready, served } from '@/routes/kitchen';

type Ticket = {
    id: string;
    number: string;
    status: 'preparing' | 'ready';
    sentAt: string;
    readyAt: string | null;
    sentBy: string;
    order: {
        id: string;
        number: string;
        service: 'dine_in' | 'takeaway';
        table: string | null;
        customerName: string | null;
    };
    lines: {
        id: string;
        name: string;
        quantity: number;
        note: string | null;
        voided: boolean;
    }[];
    /** Every line voided: nothing to make, the cook clears it. */
    cancelled: boolean;
};

type Props = { tickets: Ticket[]; slowMinutes: number };

/** How often the screen looks for new tickets. */
const REFRESH_MS = 15000;

function minutesSince(iso: string): number {
    return Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );
}

/**
 * The kitchen's tickets, oldest first: cooking, then ready to go out. The
 * screen refreshes by itself.
 */
export default function Kitchen({ tickets, slowMinutes }: Props) {
    useEffect(() => {
        const timer = window.setInterval(
            () => router.reload({ only: ['tickets'] }),
            REFRESH_MS,
        );

        return () => window.clearInterval(timer);
    }, []);

    return (
        <>
            <Head title="Kitchen" />
            <PageHeader
                title="Kitchen"
                description="New tickets appear by themselves. Mark a ticket ready when it can go out."
            />

            {tickets.length === 0 ? (
                <EmptyState icon={ChefHat} title="Nothing to cook" />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tickets.map((ticket) => {
                        const waiting = minutesSince(ticket.sentAt);
                        const action = ticket.cancelled
                            ? clear
                            : ticket.status === 'preparing'
                              ? ready
                              : served;

                        return (
                            <article
                                key={ticket.id}
                                className={`flex flex-col rounded-lg border p-3 ${ticket.cancelled ? 'border-destructive bg-muted' : ticket.status === 'ready' ? 'border-green-600 bg-green-50 dark:bg-green-950' : waiting >= slowMinutes ? 'border-destructive' : ''}`}
                            >
                                <header className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="text-lg font-semibold">
                                            {ticket.order.table ??
                                                `Takeaway${ticket.order.customerName ? ` · ${ticket.order.customerName}` : ''}`}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {ticket.number} ·{' '}
                                            {ticket.order.number} ·{' '}
                                            {ticket.sentBy}
                                        </p>
                                    </div>
                                    {ticket.cancelled ? (
                                        <StatusBadge tone="danger">
                                            Cancelled
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge
                                            tone={statusTone(ticket.status)}
                                        >
                                            {ticket.status === 'ready'
                                                ? 'Ready'
                                                : `${waiting} min`}
                                        </StatusBadge>
                                    )}
                                </header>
                                <ul className="my-3 flex-1 space-y-1.5 text-sm">
                                    {ticket.lines.map((line) => (
                                        <li
                                            key={line.id}
                                            className={
                                                line.voided
                                                    ? 'text-muted-foreground line-through'
                                                    : ''
                                            }
                                        >
                                            <span className="font-semibold tabular-nums">
                                                {line.quantity} ×
                                            </span>{' '}
                                            {line.name}
                                            {line.note && (
                                                <span className="block pl-6 text-amber-700 dark:text-amber-400">
                                                    {line.note}
                                                </span>
                                            )}
                                            {line.voided && (
                                                <span className="pl-2 text-xs no-underline">
                                                    voided
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                <Form
                                    {...action.form({ ticket: ticket.id })}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            className="w-full"
                                            variant={
                                                ticket.status === 'preparing' &&
                                                !ticket.cancelled
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            disabled={processing}
                                        >
                                            {ticket.cancelled
                                                ? 'Stop and clear'
                                                : ticket.status === 'preparing'
                                                  ? 'Ready'
                                                  : 'Served'}
                                        </Button>
                                    )}
                                </Form>
                            </article>
                        );
                    })}
                </div>
            )}
        </>
    );
}
