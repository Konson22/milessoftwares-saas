import {
    ArrowDownToLine,
    ArrowUpFromLine,
    BookOpenText,
    FileInput,
    FileOutput,
    Landmark,
    LayoutDashboard,
    ListTree,
    NotebookPen,
    Scale,
    TrendingUp,
    Truck,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as accounts } from '@/routes/accounts';
import { index as banking } from '@/routes/banking';
import { index as bills } from '@/routes/bills';
import { index as customerPayments } from '@/routes/customer-payments';
import { index as invoices } from '@/routes/invoices';
import { index as journals } from '@/routes/journals';
import { balanceSheet, profitAndLoss, trialBalance } from '@/routes/reports';
import { index as supplierPayments } from '@/routes/supplier-payments';
import { index as suppliers } from '@/routes/suppliers';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * Finance's sections, in menu order.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
            },
        ],
    },
    {
        title: 'Accounting',
        items: [
            {
                title: 'Chart of accounts',
                href: accounts(),
                icon: ListTree,
                permission: 'finance.accounts.view',
            },
            {
                title: 'Journals',
                href: journals(),
                icon: NotebookPen,
                permission: 'finance.journals.view',
            },
        ],
    },
    {
        title: 'Payables',
        items: [
            {
                title: 'Bills',
                href: bills(),
                icon: FileInput,
                permission: 'finance.payables.view',
            },
            {
                title: 'Supplier payments',
                href: supplierPayments(),
                icon: ArrowUpFromLine,
                permission: 'finance.payables.view',
            },
            {
                title: 'Suppliers',
                href: suppliers(),
                icon: Truck,
                permission: 'finance.payables.view',
            },
        ],
    },
    {
        title: 'Receivables',
        items: [
            {
                title: 'Invoices',
                href: invoices(),
                icon: FileOutput,
                permission: 'finance.receivables.view',
            },
            {
                title: 'Customer payments',
                href: customerPayments(),
                icon: ArrowDownToLine,
                permission: 'finance.receivables.view',
            },
        ],
    },
    {
        title: 'Banking',
        items: [
            {
                title: 'Bank & cash',
                href: banking(),
                icon: Landmark,
                permission: 'finance.banking.view',
            },
        ],
    },
    {
        title: 'Reports',
        items: [
            {
                title: 'Profit & loss',
                href: profitAndLoss(),
                icon: TrendingUp,
                permission: 'finance.reports.view',
            },
            {
                title: 'Balance sheet',
                href: balanceSheet(),
                icon: Scale,
                permission: 'finance.reports.view',
            },
            {
                title: 'Trial balance',
                href: trialBalance(),
                icon: BookOpenText,
                permission: 'finance.reports.view',
            },
        ],
    },
];

/**
 * The sections the member may use, without empty groups.
 */
export function visibleNavigation(
    can: (permission: string) => boolean,
): NavGroup[] {
    return navigation
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => item.permission === null || can(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
