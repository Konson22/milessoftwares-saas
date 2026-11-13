import { Form, Head, Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { selectClass } from '@/components/fields';
import { formatMoney, percent } from '@/lib/money';
import { audit } from '@/routes';
import { show as showEmployee } from '@/routes/employees';
import { show as showRun } from '@/routes/runs';

type AuditEvent = {
    id: string;
    at: string;
    action: string;
    subjectType: 'run' | 'employee' | 'component' | 'rule';
    subjectId: string | null;
    subject: string;
    user: string | null;
    details: Record<string, unknown>;
};

type Props = {
    events: {
        data: AuditEvent[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
    };
    filters: { subject: string | null; search: string };
};

const ACTIONS: Record<string, string> = {
    'run.prepared': 'Prepared',
    'run.recalculated': 'Recalculated',
    'run.discarded': 'Discarded draft',
    'run.reviewed': 'Reviewed',
    'run.approved': 'Approved',
    'run.paid': 'Recorded a payment',
    'run.reversed': 'Reversed',
    'run.adjustment_added': 'Added a one-off adjustment',
    'run.adjustment_removed': 'Removed a one-off adjustment',
    'employee.added': 'Added to the payroll',
    'employee.updated': 'Changed work details',
    'employee.left': 'Recorded leaving',
    'employee.pay_set': 'Set basic pay',
    'employee.component_added': 'Added an allowance or deduction',
    'employee.component_ended': 'Ended an allowance or deduction',
    'employee.payment_details_changed': 'Changed payment details',
    'settings.component_added': 'Added an allowance or deduction type',
    'settings.component_changed': 'Changed an allowance or deduction type',
    'settings.rule_added': 'Added income tax and social security rules',
};

const METHODS: Record<string, string> = {
    bank: 'bank transfer',
    cash: 'cash',
    mobile_money: 'mobile money',
};

/** The details worth reading, in words. */
function describe(details: Record<string, unknown>): string {
    const parts: string[] = [];
    const text = (key: string) =>
        typeof details[key] === 'string' ? details[key] : null;
    const number = (key: string) =>
        typeof details[key] === 'number' ? details[key] : null;

    if (text('employee')) parts.push(text('employee') ?? '');
    if (text('component')) parts.push(text('component') ?? '');
    if (text('payment')) parts.push(text('payment') ?? '');
    if (number('amount_minor') !== null) {
        parts.push(formatMoney(number('amount_minor')));
    }
    if (number('rate_bp') !== null) parts.push(percent(number('rate_bp') ?? 0));
    if (number('net_minor') !== null) {
        parts.push(`net ${formatMoney(number('net_minor'))}`);
    }
    if (number('headcount') !== null) {
        parts.push(`${number('headcount')} people`);
    }
    if (text('method')) {
        parts.push(METHODS[text('method') ?? ''] ?? text('method') ?? '');
    }
    if (text('reference')) parts.push(`ref. ${text('reference')}`);
    if (text('effective_from')) parts.push(`from ${text('effective_from')}`);
    if (text('starts_on')) parts.push(`from ${text('starts_on')}`);
    if (text('ends_on')) parts.push(`to ${text('ends_on')}`);
    if (text('left_on')) parts.push(`last day ${text('left_on')}`);
    if (Array.isArray(details.changed)) {
        parts.push(
            `changed ${details.changed.join(', ').replaceAll('_', ' ')}`,
        );
    }
    if (text('reason')) parts.push(`“${text('reason')}”`);
    if (text('note')) parts.push(`“${text('note')}”`);

    return parts.join(' · ');
}

export default function AuditTrail({ events, filters }: Props) {
    const subjectLink = (event: AuditEvent) => {
        if (event.subjectId === null || event.action === 'run.discarded') {
            return null;
        }
        if (event.subjectType === 'run') return showRun(event.subjectId);
        if (event.subjectType === 'employee') {
            return showEmployee(event.subjectId);
        }

        return null;
    };

    return (
        <>
            <Head title="Audit trail" />
            <PageHeader title="Audit trail" />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...audit.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Person, payroll or who did it"
                            aria-label="Search the audit trail"
                            className="max-w-sm"
                        />
                        <select
                            name="subject"
                            defaultValue={filters.subject ?? ''}
                            aria-label="What changed"
                            className={`${selectClass} w-auto`}
                        >
                            <option value="">Everything</option>
                            <option value="run">Payroll runs</option>
                            <option value="employee">People and pay</option>
                            <option value="component">
                                Allowance and deduction types
                            </option>
                            <option value="rule">
                                Tax and social security
                            </option>
                        </select>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {events.data.length === 0 ? (
                    <EmptyState icon={History} title="Nothing recorded">
                        Changes to payrolls, pay and rules appear here.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>On</TableHead>
                                <TableHead>Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.data.map((event) => {
                                const link = subjectLink(event);

                                return (
                                    <TableRow key={event.id}>
                                        <TableCell className="whitespace-nowrap text-muted-foreground tabular-nums">
                                            {new Date(
                                                event.at,
                                            ).toLocaleString()}
                                        </TableCell>
                                        <TableCell>
                                            {event.user ?? 'Another product'}
                                        </TableCell>
                                        <TableCell>
                                            {ACTIONS[event.action] ??
                                                event.action}
                                        </TableCell>
                                        <TableCell>
                                            {link ? (
                                                <Link
                                                    href={link}
                                                    className="hover:underline"
                                                >
                                                    {event.subject}
                                                </Link>
                                            ) : (
                                                event.subject
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {describe(event.details) || '—'}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}

                {events.last_page > 1 && (
                    <div className="flex items-center justify-between border-t px-3 py-2 text-sm">
                        <span className="text-muted-foreground">
                            Page {events.current_page} of {events.last_page}
                        </span>
                        <div className="flex gap-2">
                            {events.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={events.prev_page_url}
                                        preserveScroll
                                    >
                                        Newer
                                    </Link>
                                </Button>
                            )}
                            {events.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={events.next_page_url}
                                        preserveScroll
                                    >
                                        Older
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Panel>
        </>
    );
}
