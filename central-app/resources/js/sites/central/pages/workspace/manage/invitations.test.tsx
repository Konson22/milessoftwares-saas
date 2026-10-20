// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import Invitations from './invitations';

const page = vi.hoisted(() => ({ flash: {} as Record<string, unknown> }));
const router = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

// Inertia's components need a running app; for checking markup they are
// replaced with their plain HTML equivalents.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router,
    usePage: () => page,
    Form: ({
        children,
        action,
        method,
        className,
    }: {
        children: (state: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
        action: string;
        method: string;
        className?: string;
    }) => (
        <form action={action} method={method} className={className}>
            {children({ errors: {}, processing: false })}
        </form>
    ),
}));

const props = {
    invitations: [],
    awaiting: [
        {
            id: 'membership-1',
            name: 'Deng Garang',
            email: 'deng@example.test',
            expiresAt: '2026-10-23',
            expired: false,
        },
    ],
    roles: [
        {
            id: 'role-1',
            name: 'Cashier',
            application: 'Pharmacy',
            scopes: ['branch'],
        },
    ],
    branches: [{ id: 'branch-1', name: 'Juba' }],
    departments: [],
};

afterEach(() => {
    cleanup();
    page.flash = {};
    router.post.mockClear();
});

describe('manage invitations', () => {
    it('invites by email by default and can add someone directly instead', () => {
        const { container } = render(<Invitations {...props} />);

        expect(container.querySelector('form')?.getAttribute('action')).toBe(
            '/manage/invitations',
        );

        fireEvent.click(screen.getByRole('tab', { name: 'Add directly' }));

        const form = container.querySelector('form');
        expect(form?.getAttribute('action')).toBe('/manage/members');
        expect(form?.querySelector('input[name="name"]')).not.toBeNull();
        expect(form?.querySelector('input[name="email"]')).not.toBeNull();
        expect(form?.querySelector('select[name="role_id"]')).not.toBeNull();
    });

    it('lists people awaiting first sign-in and issues them a new password', () => {
        render(<Invitations {...props} />);

        expect(screen.getByText('Deng Garang')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'New password' }));

        expect(router.post).toHaveBeenCalledWith(
            '/manage/members/membership-1/temporary-password',
            {},
            { preserveScroll: true },
        );
    });

    it('shows a temporary password once, after it is issued', () => {
        page.flash = {
            credentials: {
                name: 'Deng Garang',
                email: 'deng@example.test',
                password: 'hK7m-Qx3p-Tz9a',
                expiresAt: '2026-10-23',
                signInUrl: 'http://acme.milesoftwares.local',
                roleNote: 'The role was not given: not allowed.',
            },
        };
        render(<Invitations {...props} />);

        expect(screen.getByText('hK7m-Qx3p-Tz9a')).toBeTruthy();
        expect(
            screen.getByText('The role was not given: not allowed.'),
        ).toBeTruthy();

        cleanup();
        page.flash = {};
        render(<Invitations {...props} />);
        expect(screen.queryByText('hK7m-Qx3p-Tz9a')).toBeNull();
    });

    it('has no accessibility violations', async () => {
        page.flash = {
            credentials: {
                name: 'Deng Garang',
                email: 'deng@example.test',
                password: 'hK7m-Qx3p-Tz9a',
                expiresAt: '2026-10-23',
                signInUrl: 'http://acme.milesoftwares.local',
                roleNote: null,
            },
        };
        const { container } = render(<Invitations {...props} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Add directly' }));

        const results = await axe.run(container, {
            rules: { 'color-contrast': { enabled: false } },
        });
        expect(results.violations.map((violation) => violation.id)).toEqual([]);
    });
});
