import { usePage } from '@inertiajs/react';

/**
 * Whether the member holds a catalog permission, for showing or hiding UI.
 * The server checks again on every action.
 */
export function useCan(): (permission: string) => boolean {
    const { access } = usePage().props;

    return (permission) =>
        access !== null &&
        (access.isOwner || access.permissions.includes(permission));
}
