import { describe, expect, it } from 'vite-plus/test';
import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import MarketingLayout from '@/layouts/marketing-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceLayout from '@/layouts/workspace-layout';
import { resolveLayout } from './layouts';

describe('resolveLayout', () => {
    it.each([
        ['marketing/home', MarketingLayout],
        ['marketing/pricing', MarketingLayout],
        ['admin/overview', AdminLayout],
        ['admin/staff/index', AdminLayout],
        ['workspace/home', WorkspaceLayout],
        ['workspace/manage/access', WorkspaceLayout],
        ['auth/login', AuthLayout],
        ['dashboard', AppLayout],
        ['organizations/create', AppLayout],
    ])('gives %s its host layout', (page, layout) => {
        expect(resolveLayout(page)).toBe(layout);
    });

    it('nests settings inside the app layout', () => {
        expect(resolveLayout('settings/profile')).toEqual([
            AppLayout,
            SettingsLayout,
        ]);
    });

    it('lets the suspension page render its own chrome', () => {
        expect(resolveLayout('workspace/suspended')).toBeNull();
    });
});
