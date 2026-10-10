import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import MarketingLayout from '@/layouts/marketing-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceLayout from '@/layouts/workspace-layout';

/**
 * Layout for an Inertia page, by page name. Each host has its own chrome;
 * the workspace suspension page renders its own.
 */
export function resolveLayout(name: string) {
    switch (true) {
        case name === 'workspace/suspended':
            return null;
        case name.startsWith('marketing/'):
            return MarketingLayout;
        case name.startsWith('admin/'):
            return AdminLayout;
        case name.startsWith('workspace/'):
            return WorkspaceLayout;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
}
