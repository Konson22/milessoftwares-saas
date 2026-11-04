import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Banknote,
    BookOpenText,
    Boxes,
    Building2,
    CalendarRange,
    CheckCheck,
    Coins,
    Columns3,
    FileCheck2,
    FileInput,
    FileOutput,
    Hourglass,
    Landmark,
    LayoutDashboard,
    ListTree,
    NotebookPen,
    Percent,
    Activity,
    TrendingDown,
    Repeat,
    Rows3,
    ShieldCheck,
    SlidersHorizontal,
    Shapes,
    Scale,
    Target,
    Gauge,
    TrendingUp,
    Truck,
    Waves,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as accounts } from '@/routes/accounts';
import { index as assetCategories } from '@/routes/asset-categories';
import { index as banking } from '@/routes/banking';
import { index as bills } from '@/routes/bills';
import { index as budgets } from '@/routes/budgets';
import { index as currencies } from '@/routes/currencies';
import { index as customerPayments } from '@/routes/customer-payments';
import { index as depreciation } from '@/routes/depreciation';
import { index as dimensions } from '@/routes/dimensions';
import { index as fixedAssets } from '@/routes/fixed-assets';
import { index as invoices } from '@/routes/invoices';
import { index as approvalRules } from '@/routes/approval-rules';
import { index as journalApprovals } from '@/routes/journal-approvals';
import { index as journals } from '@/routes/journals';
import { index as paymentRequests } from '@/routes/payment-requests';
import { index as periods } from '@/routes/periods';
import { index as reconciliation } from '@/routes/reconciliation';
import { index as recurringJournals } from '@/routes/recurring-journals';
import {
    agedPayables,
    agedReceivables,
    balanceSheet,
    budgetVsActual,
    cashFlow,
    generalLedger,
    profitAndLoss,
    profitAndLossByDimension,
    ratios,
    trialBalance,
} from '@/routes/reports';
import { index as supplierPayments } from '@/routes/supplier-payments';
import { index as suppliers } from '@/routes/suppliers';
import { index as taxCodes } from '@/routes/tax-codes';
import { index as taxReturns } from '@/routes/tax-returns';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission, or any of these (or owners); null for everyone. */
    permission: string | string[] | null;
    /** Shown only when the plan includes this feature. */
    feature?: Feature;
};

/** Plan features that add sections (shared props of the same name). */
export type Feature = 'multicurrency' | 'fixedAssets';

/**
 * Who a section is mainly for. A member with only the finance dashboard does
 * not get accounting's sections in the menu, and the other way round. It
 * only tidies the menu: what they may open is decided by their permissions.
 */
export type NavArea = 'finance' | 'accounting';

export type NavGroup = { title: string; area?: NavArea; items: NavItem[] };

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
        title: 'Payments',
        items: [
            {
                title: 'Payment requests',
                href: paymentRequests(),
                icon: Banknote,
                permission: [
                    'finance.payments.view',
                    'finance.payments.prepare',
                    'finance.payables.manage',
                    'finance.banking.manage',
                ],
            },
        ],
    },
    {
        title: 'Planning',
        area: 'finance',
        items: [
            {
                title: 'Budgets',
                href: budgets(),
                icon: Target,
                permission: 'finance.reports.view',
            },
        ],
    },
    {
        title: 'Accounting',
        area: 'accounting',
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
            {
                title: 'Journal approvals',
                href: journalApprovals(),
                icon: ShieldCheck,
                permission: 'finance.journals.view',
            },
            {
                title: 'Approval rules',
                href: approvalRules(),
                icon: SlidersHorizontal,
                permission: 'finance.approvals.configure',
            },
            {
                title: 'Recurring journals',
                href: recurringJournals(),
                icon: Repeat,
                permission: 'finance.journals.view',
            },
            {
                title: 'Periods & close',
                href: periods(),
                icon: CalendarRange,
                permission: 'finance.journals.view',
            },
            {
                title: 'Cost centres & projects',
                href: dimensions(),
                icon: Shapes,
                permission: 'finance.accounts.view',
            },
            {
                title: 'Currencies',
                href: currencies(),
                icon: Coins,
                permission: 'finance.reports.view',
                feature: 'multicurrency',
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
            {
                title: 'Reconciliation',
                href: reconciliation(),
                icon: CheckCheck,
                permission: 'finance.banking.view',
            },
        ],
    },
    {
        title: 'Fixed assets',
        area: 'accounting',
        items: [
            {
                title: 'Asset register',
                href: fixedAssets(),
                icon: Building2,
                permission: 'finance.accounts.view',
                feature: 'fixedAssets',
            },
            {
                title: 'Depreciation',
                href: depreciation(),
                icon: TrendingDown,
                permission: 'finance.accounts.view',
                feature: 'fixedAssets',
            },
            {
                title: 'Asset categories',
                href: assetCategories(),
                icon: Boxes,
                permission: 'finance.accounts.view',
                feature: 'fixedAssets',
            },
        ],
    },
    {
        title: 'Tax',
        area: 'accounting',
        items: [
            {
                title: 'Tax returns',
                href: taxReturns(),
                icon: FileCheck2,
                permission: 'finance.reports.view',
            },
            {
                title: 'Tax codes',
                href: taxCodes(),
                icon: Percent,
                permission: 'finance.reports.view',
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
                title: 'P&L by dimension',
                href: profitAndLossByDimension(),
                icon: Columns3,
                permission: 'finance.reports.view',
            },
            {
                title: 'Budget vs actual',
                href: budgetVsActual(),
                icon: Gauge,
                permission: 'finance.reports.view',
            },
            {
                title: 'Balance sheet',
                href: balanceSheet(),
                icon: Scale,
                permission: 'finance.reports.view',
            },
            {
                title: 'Cash flow',
                href: cashFlow(),
                icon: Waves,
                permission: 'finance.reports.view',
            },
            {
                title: 'Financial ratios',
                href: ratios(),
                icon: Activity,
                permission: 'finance.reports.view',
            },
            {
                title: 'Trial balance',
                href: trialBalance(),
                icon: BookOpenText,
                permission: 'finance.reports.view',
            },
            {
                title: 'General ledger',
                href: generalLedger(),
                icon: Rows3,
                permission: 'finance.reports.view',
            },
            {
                title: 'Aged receivables',
                href: agedReceivables(),
                icon: Hourglass,
                permission: 'finance.reports.view',
            },
            {
                title: 'Aged payables',
                href: agedPayables(),
                icon: Hourglass,
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
    features: Partial<Record<Feature, boolean>> = {},
): NavGroup[] {
    const finance = can('finance.dashboard.finance');
    const accounting = can('finance.dashboard.accounting');
    const hidden: NavArea | null =
        finance && !accounting
            ? 'accounting'
            : accounting && !finance
              ? 'finance'
              : null;

    return navigation
        .filter((group) => group.area === undefined || group.area !== hidden)
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (item.permission === null ||
                        [item.permission].flat().some(can)) &&
                    (item.feature === undefined ||
                        features[item.feature] === true),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
