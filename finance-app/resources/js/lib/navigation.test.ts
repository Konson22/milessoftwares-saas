import { describe, expect, it } from 'vite-plus/test';
import { visibleNavigation } from './navigation';

/** Sections a member holding these permissions sees in the menu. */
function sections(...permissions: string[]): string[] {
    return visibleNavigation((permission) =>
        permissions.includes(permission),
    ).map((group) => group.title);
}

describe('visibleNavigation', () => {
    it('shows a finance member financial management, not accounting', () => {
        const groups = sections(
            'finance.dashboard.finance',
            'finance.payments.view',
            'finance.reports.view',
            'finance.journals.view',
        );

        expect(groups).toContain('Payments');
        expect(groups).toContain('Planning');
        expect(groups).not.toContain('Accounting');
    });

    it('shows an accounting member accounting, not planning', () => {
        const groups = sections(
            'finance.dashboard.accounting',
            'finance.payments.view',
            'finance.reports.view',
            'finance.journals.view',
        );

        expect(groups).toContain('Accounting');
        expect(groups).toContain('Payments');
        expect(groups).not.toContain('Planning');
    });

    it('shows the union to someone holding both', () => {
        const groups = sections(
            'finance.dashboard.finance',
            'finance.dashboard.accounting',
            'finance.reports.view',
            'finance.journals.view',
        );

        expect(groups).toContain('Accounting');
        expect(groups).toContain('Planning');
    });

    it('hides nothing by area from a member with no dashboard permission', () => {
        const groups = sections(
            'finance.reports.view',
            'finance.journals.view',
        );

        expect(groups).toContain('Accounting');
        expect(groups).toContain('Planning');
    });

    it('lets any of several permissions show an item', () => {
        expect(sections('finance.payables.manage')).toContain('Payments');
        expect(sections('finance.payments.prepare')).toContain('Payments');
    });
});
