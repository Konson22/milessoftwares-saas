import { describe, expect, it } from 'vite-plus/test';
import { layout as adminLayout } from '@/sites/admin';
import AdminLayout from '@/sites/admin/layouts/admin-layout';
import { layout as centralLayout } from '@/sites/central';
import AppLayout from '@/sites/central/layouts/app-layout';
import AuthLayout from '@/sites/central/layouts/auth-layout';
import SettingsLayout from '@/sites/central/layouts/settings/layout';
import WorkspaceLayout from '@/sites/central/layouts/workspace-layout';
import { resolveLayout, resolvePage, splitPageName } from '@/sites';
import { layout as publicLayout } from '@/sites/public';
import MarketingLayout from '@/sites/public/layouts/marketing-layout';

describe('page names', () => {
    it.each([
        ['public/pricing', 'public', 'pricing'],
        ['admin/staff/index', 'admin', 'staff/index'],
        [
            'central/workspace/manage/access',
            'central',
            'workspace/manage/access',
        ],
    ])('splits %s into site and page', (name, site, page) => {
        expect(splitPageName(name)).toEqual({ site, page });
    });

    it.each(['dashboard', 'marketing/home', '/home', 'other/page'])(
        'rejects %s, which names no site',
        (name) => {
            expect(() => splitPageName(name)).toThrow(/must start with a site/);
        },
    );

    it('reports a missing page by name', async () => {
        await expect(resolvePage('public/nope')).rejects.toThrow(
            'Page not found: public/nope',
        );
    });
});

describe('site layouts', () => {
    it('gives every public and admin page its site chrome', () => {
        expect(publicLayout()).toBe(MarketingLayout);
        expect(adminLayout()).toBe(AdminLayout);
    });

    it.each([
        ['workspace/home', WorkspaceLayout],
        ['workspace/manage/access', WorkspaceLayout],
        ['auth/login', AuthLayout],
        ['dashboard', AppLayout],
        ['organizations/create', AppLayout],
    ])('gives central %s its host layout', (page, layout) => {
        expect(centralLayout(page)).toBe(layout);
    });

    it('nests settings inside the app layout', () => {
        expect(centralLayout('settings/profile')).toEqual([
            AppLayout,
            SettingsLayout,
        ]);
    });

    it('lets the suspension page render its own chrome', () => {
        expect(centralLayout('workspace/suspended')).toBeNull();
    });

    it('uses the layout of the site the page was loaded from', async () => {
        await resolvePage('public/thanks');
        await resolvePage('central/dashboard');

        expect(resolveLayout('public/thanks')).toBe(MarketingLayout);
        expect(resolveLayout('central/dashboard')).toBe(AppLayout);
    });
});
