import AdminLayout from '@/sites/admin/layouts/admin-layout';

/**
 * Admin site: admin.milessoftwares.com, staff only. Pages in ./pages are
 * rendered as `admin/{page}`.
 */
export function layout(): unknown {
    return AdminLayout;
}
