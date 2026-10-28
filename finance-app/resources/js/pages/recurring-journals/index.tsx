import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Check, Pause, Play, Plus, Repeat, Send } from 'lucide-react';
import { Amount } from '@/components/amount';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { show as journal } from '@/routes/journals';
import {
    approve,
    create,
    edit,
    run,
    toggle,
} from '@/routes/recurring-journals';

type Props = {
    templates: {
        id: string;
        name: string;
        memo: string;
        frequency: string;
        startsOn: string;
        endsOn: string | null;
        nextOn: string | null;
        due: boolean;
        postedCount: number;
        reverses: boolean;
        active: boolean;
        totalMinor: number;
        lastJournal: { id: string; number: string; date: string } | null;
        lastError: string | null;
        awaitingApproval: boolean;
        ownChange: boolean;
        approvedBy: string | null;
    }[];
};

/**
 * Recurring journals: templates posted every day they fall due (rent,
 * accruals, prepaid amortisation), or now with "Post due".
 */
export default function RecurringJournals({ templates }: Props) {
    const can = useCan();
    const manage = can('finance.journals.post');
    const approver = can('finance.journals.approve');
    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <>
            <Head title="Recurring journals" />
            <PageHeader
                title="Recurring journals"
                description="Entries that repeat: posted automatically each night on the days they fall due, in order, into open periods only."
                actions={
                    manage && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden />
                                New recurring journal
                            </Link>
                        </Button>
                    )
                }
            />

            {errors.approve && (
                <p className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {errors.approve}
                </p>
            )}
            {errors.run && (
                <p className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {errors.run}
                </p>
            )}

            <Panel>
                {templates.length === 0 ? (
                    <EmptyState icon={Repeat} title="No recurring journals yet">
                        Set up rent, monthly accruals or prepaid amortisation
                        once, and they post themselves.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Schedule</TableHead>
                                <TableHead>Next</TableHead>
                                <TableHead>Last posted</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead>Status</TableHead>
                                {manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {templates.map((template) => (
                                <TableRow key={template.id}>
                                    <TableCell>
                                        {manage ? (
                                            <Link
                                                href={edit(template.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {template.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {template.name}
                                            </span>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {template.memo}
                                        </div>
                                        {template.lastError && (
                                            <div className="text-xs text-destructive">
                                                Stopped: {template.lastError}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {template.frequency}
                                        <div className="text-xs text-muted-foreground">
                                            from {template.startsOn}
                                            {template.endsOn &&
                                                ` to ${template.endsOn}`}
                                            {template.reverses &&
                                                ' · reversed next day'}
                                        </div>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {template.nextOn ?? 'Finished'}
                                    </TableCell>
                                    <TableCell>
                                        {template.lastJournal ? (
                                            <Link
                                                href={journal(
                                                    template.lastJournal.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {template.lastJournal.number} ·{' '}
                                                {template.lastJournal.date}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not yet
                                            </span>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {template.postedCount} posted
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={template.totalMinor} />
                                    </TableCell>
                                    <TableCell>
                                        {!template.active ? (
                                            <StatusBadge>Paused</StatusBadge>
                                        ) : template.awaitingApproval ? (
                                            <div className="grid justify-items-start gap-1">
                                                <StatusBadge tone="warning">
                                                    Awaiting approval
                                                </StatusBadge>
                                                {approver &&
                                                    !template.ownChange && (
                                                        <Form
                                                            {...approve.form(
                                                                template.id,
                                                            )}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    type="submit"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    <Check
                                                                        className="size-4"
                                                                        aria-hidden
                                                                    />
                                                                    Approve
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                {template.ownChange && (
                                                    <span className="text-xs text-muted-foreground">
                                                        Your change: someone
                                                        else approves it.
                                                    </span>
                                                )}
                                            </div>
                                        ) : template.lastError ? (
                                            <StatusBadge tone="danger">
                                                Stopped
                                            </StatusBadge>
                                        ) : template.due ? (
                                            <StatusBadge tone="warning">
                                                Due
                                            </StatusBadge>
                                        ) : template.nextOn ? (
                                            <StatusBadge tone="success">
                                                Scheduled
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge>Finished</StatusBadge>
                                        )}
                                    </TableCell>
                                    {manage && (
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                {template.active &&
                                                    template.due &&
                                                    !template.awaitingApproval && (
                                                        <Form
                                                            {...run.form(
                                                                template.id,
                                                            )}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    type="submit"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    <Send
                                                                        className="size-4"
                                                                        aria-hidden
                                                                    />
                                                                    Post due
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                <Form
                                                    {...toggle.form(
                                                        template.id,
                                                    )}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={
                                                                template.active
                                                                    ? 'Pause'
                                                                    : 'Resume'
                                                            }
                                                        >
                                                            {template.active ? (
                                                                <Pause
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                            ) : (
                                                                <Play
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                            )}
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
