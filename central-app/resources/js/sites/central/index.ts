import AppLayout from '@/sites/central/layouts/app-layout';
import AuthLayout from '@/sites/central/layouts/auth-layout';
import SettingsLayout from '@/sites/central/layouts/settings/layout';
import WorkspaceLayout from '@/sites/central/layouts/workspace-layout';

/**
 * Central app: auth., app. and the {business} workspace hosts. Pages in
 * ./pages are rendered as `central/{page}`. Each host has its own chrome;
 * the workspace suspension page and the members' application picker render
 * their own.
 */
export function layout(page: string): unknown {
    switch (true) {
        case page === 'workspace/suspended':
        case page === 'workspace/apps':
            return null;
        case page.startsWith('workspace/'):
            return WorkspaceLayout;
        case page.startsWith('auth/'):
            return AuthLayout;
        case page.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
}
