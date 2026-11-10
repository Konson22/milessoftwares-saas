import { describe, expect, it } from 'vite-plus/test';
import { visibleNavigation } from './navigation';

/**
 * Roles of catalog 1.11.0 (platform/catalog.json). The menu goes by
 * permission only, so a role is just its permissions here.
 */
const ROLES: Record<string, string[]> = {
    financeManager: [
        'finance.dashboard.finance',
        'finance.journals.view',
        'finance.payables.view',
        'finance.receivables.view',
        'finance.banking.view',
        'finance.reports.view',
        'finance.budgets.manage',
        'finance.payments.view',
        'finance.payments.approve',
        'finance.accounts.view',
        'finance.approvals.view',
        'finance.audit.view',
    ],
    financeOfficer: [
        'finance.dashboard.finance',
        'finance.payables.view',
        'finance.receivables.view',
        'finance.banking.view',
        'finance.reports.view',
        'finance.payments.view',
        'finance.payments.prepare',
    ],
    cashier: [
        'finance.dashboard.finance',
        'finance.payables.view',
        'finance.receivables.view',
        'finance.banking.view',
        'finance.receipts.record',
        'finance.payments.view',
        'finance.payments.disburse',
    ],
    auditor: [
        'finance.dashboard.finance',
        'finance.dashboard.accounting',
        'finance.accounts.view',
        'finance.journals.view',
        'finance.payables.view',
        'finance.receivables.view',
        'finance.banking.view',
        'finance.reports.view',
        'finance.payments.view',
        'finance.approvals.view',
        'finance.audit.view',
    ],
    systemAdministrator: [
        'finance.accounts.view',
        'finance.approvals.configure',
        'finance.dashboard.admin',
        'finance.approvals.view',
        'finance.audit.view',
    ],
};

/** Menu items a member holding these permissions sees. */
function items(permissions: string[]): string[] {
    return visibleNavigation((permission) =>
        permissions.includes(permission),
    ).flatMap((group) => group.items.map((item) => String(item.title)));
}

describe('visibleNavigation', () => {
    it('gives the Finance Manager journals, recurring journals, accounts and cost centres', () => {
        const menu = items(ROLES.financeManager);

        expect(menu).toContain('Journals');
        expect(menu).toContain('Recurring journals');
        expect(menu).toContain('Chart of accounts');
        expect(menu).toContain('Cost centres & projects');
        expect(menu).toContain('Budgets');
        expect(menu).toContain('Payment requests');
        expect(menu).toContain('Approval rules');
    });

    it('gives the Auditor the approval rules and the audit trail', () => {
        const menu = items(ROLES.auditor);

        expect(menu).toContain('Approval rules');
        expect(menu).toContain('Audit trail');
        expect(menu).toContain('Reconciliation');
        expect(menu).toContain('Payment requests');
    });

    it('gives the System Administrator setup, not money', () => {
        const menu = items(ROLES.systemAdministrator);

        expect(menu).toContain('Approval rules');
        expect(menu).toContain('Audit trail');
        expect(menu).toContain('Chart of accounts');
        expect(menu).not.toContain('Payment requests');
        expect(menu).not.toContain('Bank & cash');
        expect(menu).not.toContain('Journals');
        expect(menu).not.toContain('Profit & loss');
    });

    it('keeps the Cashier and Finance Officer read access to banking', () => {
        for (const role of [ROLES.cashier, ROLES.financeOfficer]) {
            const menu = items(role);

            expect(menu).toContain('Bank & cash');
            expect(menu).toContain('Reconciliation');
            expect(menu).toContain('Payment requests');
            expect(menu).not.toContain('Journals');
            expect(menu).not.toContain('Audit trail');
            expect(menu).not.toContain('Approval rules');
        }
    });

    it('shows no item for a retired permission', () => {
        expect(items(['finance.payables.manage'])).toEqual(['Dashboard']);
        expect(items(['finance.banking.manage'])).toEqual(['Dashboard']);
    });
});
