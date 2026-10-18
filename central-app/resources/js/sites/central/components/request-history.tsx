import { Form } from '@inertiajs/react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import { withdraw } from '@/routes/organization/plan-requests';
import { formatDate, humanize, requestTone } from '@/shared/lib/billing';

export type PlanRequestRow = {
    id: string;
    type: 'subscribe' | 'cancel';
    status: string;
    subject: string | null;
    note: string | null;
    decisionNote: string | null;
    requestedBy: string | null;
    requestedAt: string | null;
    decidedAt: string | null;
};

/**
 * Plan and cancellation requests the owner has sent, with staff's answer.
 * A pending one can be withdrawn.
 */
export function RequestHistory({ requests }: { requests: PlanRequestRow[] }) {
    if (requests.length === 0) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Your requests"
                description="Miles Global Softwares reviews each request, usually within one working day."
            />
            <ul className="divide-y text-sm">
                {requests.map((request) => (
                    <li
                        key={request.id}
                        className="flex flex-wrap items-start gap-3 px-4 py-3"
                    >
                        <div className="min-w-0 flex-1 space-y-0.5">
                            <p className="font-medium">
                                {request.type === 'subscribe'
                                    ? `Subscribe to ${request.subject ?? 'a plan'}`
                                    : `Cancel ${request.subject ?? 'a subscription'}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {formatDate(request.requestedAt)}
                                {request.requestedBy &&
                                    ` · ${request.requestedBy}`}
                                {request.decidedAt &&
                                    ` · decided ${formatDate(request.decidedAt)}`}
                            </p>
                            {request.note && (
                                <p className="text-muted-foreground">
                                    “{request.note}”
                                </p>
                            )}
                            {request.decisionNote && (
                                <p>
                                    <span className="text-muted-foreground">
                                        Reply:
                                    </span>{' '}
                                    {request.decisionNote}
                                </p>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <StatusBadge tone={requestTone[request.status]}>
                                {humanize(request.status)}
                            </StatusBadge>
                            {request.status === 'pending' && (
                                <Form
                                    {...withdraw.form(request.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}
                                        >
                                            Withdraw
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
