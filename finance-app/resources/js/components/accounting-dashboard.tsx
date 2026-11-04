import { Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    CalendarRange,
    Check,
    FileClock,
    NotebookPen,
    Scale,
    Users,
} from 'lucide-react';
import { Amount } from '@/components/amount';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { useMoney } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { index as bills } from '@/routes/bills';
import { index as invoices } from '@/routes/invoices';
import { index as journalApprovals } from '@/routes/journal-approvals';
import { index as paymentRequests } from '@/routes/payment-requests';
import { index as periods } from '@/routes/periods';
import { index as reconciliation } from '@/routes/reconciliation';
import { index as recurringJournals } from '@/routes/recurring-journals';
import { generalLedger, trialBalance } from '@/routes/reports';

type BankRow = {
    id: string;
    name: string;
    balanceMinor: number;
    statementEndsOn: string | null;
    reconciled: boolean;
    unmatched: number;
};

export type AccountingDashboardData = {
    journals: {
        count: number;
        minor: number;
        oldest: {
            id: string;
            number: string;
            memo: string;
            minor: number;
            by: string;
        }[];
    } | null;
    unposted: {
        payments: { count: number; minor: number } | null;
        recurringDue: number | null;
        recurringHeld: number | null;
    } | null;
    banks: BankRow[] | null;
    pettyCash: BankRow[] | null;
    trialBalance: {
        debitMinor: number;
        creditMinor: number;
        balanced: boolean;
    } | null;
    ledger: { journals: number; debitMinor: number } | null;
    advances: { outstandingMinor: number; accountId: string | null } | null;
    close: {
        month: string;
        periodId: string;
        tasks: { key: string; label: string; open: number }[];
    } | null;
};

type Props = {
    data: AccountingDashboardData;
    receivable: { outstandingMinor: number; overdue: number } | null;
    payable: {
        outstandingMinor: number;
        overdue: number;
        dueSoon: number;
    } | null;
};

/**
 * Accounting operations: entries to post, accounts to reconcile, balances to
 * check and the month to close. Drawn from the same ledger as finance's.
 */
export function AccountingDashboard({ data, receivable, payable }: Props) {
    const money = useMoney();
    const { journals, unposted, banks, pettyCash, ledger, advances, close } =
        data;
    const balance = data.trialBalance;
    const unpostedCount =
        (unposted?.payments?.count ?? 0) +
        (unposted?.recurringDue ?? 0) +
        (unposted?.recurringHeld ?? 0);

    return (
        <div className="grid gap-3">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {journals && (
                    <StatCard
                        label="Pending journal entries"
                        value={String(journals.count)}
                        hint={money(journals.minor)}
                        icon={NotebookPen}
                        accent={journals.count > 0 ? 'amber' : 'emerald'}
                        href={journalApprovals()}
                    />
                )}
                {unposted && (
                    <StatCard
                        label="Unposted transactions"
                        value={String(unpostedCount)}
                        hint={`${unposted.payments?.count ?? 0} payments to disburse, ${(unposted.recurringDue ?? 0) + (unposted.recurringHeld ?? 0)} recurring due`}
                        icon={FileClock}
                        accent={unpostedCount > 0 ? 'amber' : 'emerald'}
                        href={
                            unposted.payments && unposted.payments.count > 0
                                ? paymentRequests()
                                : recurringJournals()
                        }
                    />
                )}
                {balance && (
                    <StatCard
                        label="Trial balance"
                        value={
                            balance.balanced ? 'In balance' : 'Out of balance'
                        }
                        hint={`${money(balance.debitMinor)} debits, ${money(balance.creditMinor)} credits`}
                        icon={Scale}
                        accent={balance.balanced ? 'emerald' : 'red'}
                        href={trialBalance()}
                    />
                )}
                {ledger && (
                    <StatCard
                        label="General ledger this month"
                        value={`${ledger.journals} journals`}
                        hint={`${money(ledger.debitMinor)} posted`}
                        icon={NotebookPen}
                        accent="blue"
                        href={generalLedger()}
                    />
                )}
                {payable && (
                    <StatCard
                        label="Accounts payable"
                        value={money(payable.outstandingMinor)}
                        hint={
                            payable.overdue > 0
                                ? `${payable.overdue} bills overdue`
                                : `${payable.dueSoon} due in 7 days`
                        }
                        icon={ArrowUpFromLine}
                        accent={payable.overdue > 0 ? 'red' : 'violet'}
                        href={bills()}
                    />
                )}
                {receivable && (
                    <StatCard
                        label="Accounts receivable"
                        value={money(receivable.outstandingMinor)}
                        hint={
                            receivable.overdue > 0
                                ? `${receivable.overdue} invoices overdue`
                                : 'Nothing overdue'
                        }
                        icon={ArrowDownToLine}
                        accent={receivable.overdue > 0 ? 'amber' : 'emerald'}
                        href={invoices()}
                    />
                )}
                {advances && (
                    <StatCard
                        label="Outstanding employee advances"
                        value={money(advances.outstandingMinor)}
                        hint="Balance of the advances account"
                        icon={Users}
                        accent={
                            advances.outstandingMinor > 0 ? 'amber' : 'slate'
                        }
                        href={
                            advances.accountId
                                ? showAccount(advances.accountId)
                                : undefined
                        }
                    />
                )}
            </div>

            <div className="grid gap-3 xl:grid-cols-2">
                {close && (
                    <Panel>
                        <PanelHeader
                            title="Period-end closing"
                            description={`Before ${close.month} is locked`}
                            actions={
                                <Link
                                    href={periods()}
                                    className="text-sm text-primary hover:underline"
                                >
                                    Periods
                                </Link>
                            }
                        />
                        <ul className="divide-y text-sm">
                            {close.tasks.map((task) => (
                                <li
                                    key={task.key}
                                    className="flex items-center justify-between gap-3 px-4 py-2"
                                >
                                    <span>{task.label}</span>
                                    {task.open === 0 ? (
                                        <StatusBadge tone="success">
                                            <Check
                                                className="size-3"
                                                aria-hidden
                                            />{' '}
                                            Done
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge tone="warning">
                                            {task.open} open
                                        </StatusBadge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
                {!close && journals && (
                    <Panel>
                        <PanelHeader title="Period-end closing" />
                        <PanelBody className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CalendarRange className="size-4" aria-hidden />
                            No ended month is waiting to be locked.
                        </PanelBody>
                    </Panel>
                )}

                {journals && (
                    <Panel>
                        <PanelHeader
                            title="Journals waiting longest"
                            actions={
                                <Link
                                    href={journalApprovals()}
                                    className="text-sm text-primary hover:underline"
                                >
                                    Journal approvals
                                </Link>
                            }
                        />
                        {journals.oldest.length === 0 ? (
                            <PanelBody className="text-sm text-muted-foreground">
                                Nothing waiting.
                            </PanelBody>
                        ) : (
                            <ul className="divide-y text-sm">
                                {journals.oldest.map((draft) => (
                                    <li
                                        key={draft.id}
                                        className="flex items-center justify-between gap-3 px-4 py-2"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-mono text-xs">
                                                {draft.number}
                                            </span>{' '}
                                            {draft.memo}
                                            <span className="block text-xs text-muted-foreground">
                                                {draft.by}
                                            </span>
                                        </span>
                                        <Amount minor={draft.minor} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}

                {banks && (
                    <BankStatusPanel
                        title="Bank reconciliation status"
                        rows={banks}
                    />
                )}
                {pettyCash && pettyCash.length > 0 && (
                    <BankStatusPanel
                        title="Petty cash reconciliation"
                        rows={pettyCash}
                    />
                )}
            </div>
        </div>
    );
}

function BankStatusPanel({ title, rows }: { title: string; rows: BankRow[] }) {
    return (
        <Panel>
            <PanelHeader
                title={title}
                actions={
                    <Link
                        href={reconciliation()}
                        className="text-sm text-primary hover:underline"
                    >
                        Reconciliation
                    </Link>
                }
            />
            <ul className="divide-y text-sm">
                {rows.map((bank) => (
                    <li
                        key={bank.id}
                        className="flex items-center justify-between gap-3 px-4 py-2"
                    >
                        <span className="min-w-0">
                            {bank.name}
                            <span className="block text-xs text-muted-foreground">
                                {bank.statementEndsOn
                                    ? `Latest statement to ${bank.statementEndsOn}`
                                    : 'No statement yet'}
                            </span>
                        </span>
                        <span className="flex items-center gap-2">
                            <Amount minor={bank.balanceMinor} />
                            {bank.statementEndsOn === null ? (
                                <StatusBadge>No statement</StatusBadge>
                            ) : bank.reconciled ? (
                                <StatusBadge tone="success">
                                    Reconciled
                                </StatusBadge>
                            ) : (
                                <StatusBadge tone="warning">
                                    {bank.unmatched} unmatched
                                </StatusBadge>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
