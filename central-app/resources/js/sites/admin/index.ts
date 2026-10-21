import AdminAuthLayout from '@/sites/admin/layouts/admin-auth-layout';
import AdminLayout from '@/sites/admin/layouts/admin-layout';

/**
 * Admin site: admin.milesoftwares.com, staff only. Pages in ./pages are
 * rendered as `admin/{page}`; its own sign-in pages are under auth/.
 */
export function layout(page: string): unknown {
    return page.startsWith('auth/') ? AdminAuthLayout : AdminLayout;
}
