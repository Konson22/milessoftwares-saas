import AdminLayout from '@/sites/admin/layouts/admin-layout';

/**
 * Admin site: admin.milesoftwares.com, staff only. Pages in ./pages are
 * rendered as `admin/{page}`.
 */
export function layout(): unknown {
    return AdminLayout;
}
