import { Link } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import type { WorkFrame } from '@/components/partner-work';
import { WorkPage } from '@/components/partner-work';
import { RequestForm } from '@/components/request-fields';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import type { Option } from '@/types';

type RequestRow = {
    id: string;
    reference: string;
    typeLabel: string;
    title: string;
    status: string;
    amountMinor: number | null;
    currency: string | null;
    submitter: string;
    reviewer: string;
    direction: 'outgoing' | 'incoming' | 'other';
    createdAt: string;
    url: string;
};

type Props = {
    frame: WorkFrame;
    requests: RequestRow[];
    can: { create: boolean };
    types: Option[];
    categories: Option[];
    reviewers: Option[];
    defaultReviewer: string;
    currency: string;
    urls: { store: string };
};

export default function Requests({
    frame,
    requests,
    can,
    types,
    categories,
    reviewers,
    defaultReviewer,
    currency,
    urls,
}: Props) {
    return (
        <WorkPage frame={frame} title="Requests">
            <Panel>
                <PanelHeader
                    title="Requests"
                    description="Funding, budget revisions, changes and information asked between the parties of this project. Approving one moves no money and changes no budget by itself."
                />
                {requests.length === 0 ? (
                    <EmptyState icon={Inbox} title="No requests">
                        Requests you raise, and those sent to you, appear here.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Request</TableHead>
                                <TableHead>From → to</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Raised</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {requests.map((request) => (
                                <TableRow key={request.id}>
                                    <TableCell>
                                        <Link
                                            href={request.url}
                                            className="font-medium hover:underline"
                                        >
                                            {request.reference} ·{' '}
                                            {request.title}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {request.typeLabel}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {request.submitter} → {request.reviewer}
                                    </TableCell>
                                    <TableCell className="text-sm tabular-nums">
                                        {request.amountMinor !== null &&
                                        request.currency
                                            ? formatMoney(
                                                  request.amountMinor,
                                                  request.currency,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {formatDate(request.createdAt)}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={request.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {can.create && (
                <Panel>
                    <PanelHeader
                        title="Raise a request"
                        description="It starts as a draft; submit it when it is ready."
                    />
                    <PanelBody>
                        <RequestForm
                            initial={{
                                type: 'information',
                                title: '',
                                justification: '',
                                reviewer: defaultReviewer,
                                details: {},
                            }}
                            url={urls.store}
                            method="post"
                            types={types}
                            categories={categories}
                            reviewers={reviewers}
                            currency={currency}
                            submitLabel="Save draft"
                        />
                    </PanelBody>
                </Panel>
            )}
        </WorkPage>
    );
}
