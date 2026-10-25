import { usePage } from '@inertiajs/react';

/**
 * Whether the member holds a catalog permission anywhere, for showing or
 * hiding UI. The server checks again on every action.
 */
export function useCan(): (permission: string) => boolean {
    const { access } = usePage().props;

    return (permission) =>
        access !== null &&
        (access.isOwner || access.permissions.includes(permission));
}

/**
 * Whether the permission applies in the branch being worked in (a role for
 * another branch does not count). UI only: the server checks again.
 */
export function useCanHere(): (permission: string) => boolean {
    const { access, branchPermissions } = usePage().props;

    return (permission) =>
        access !== null &&
        (access.isOwner || branchPermissions.includes(permission));
}
