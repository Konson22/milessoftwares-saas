/**
 * Props every page receives (app/Http/Middleware/HandleInertiaRequests.php).
 */
export type SharedProps = {
    product: { name: string; code: string };
    workspace: { slug: string; name: string; url: string } | null;
    user: { name: string; email: string } | null;
    /** For showing and hiding UI only: the server authorizes every action. */
    access: { isOwner: boolean; permissions: string[] } | null;
    /** The workspace currency (ISO code); amounts arrive in minor units. */
    currency: string | null;
    /** The branch being worked in, once chosen. */
    branch: { id: string; name: string } | null;
    /** Permissions that apply in that branch (UI only). */
    branchPermissions: string[];
    /** The branches open to the member, for the branch switcher. */
    branches: { id: string; name: string }[];
    flash: { success: string | null };
};
