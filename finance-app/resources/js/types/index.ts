/**
 * Props every page receives (app/Http/Middleware/HandleInertiaRequests.php).
 */
export type SharedProps = {
    product: { name: string; code: string };
    workspace: { slug: string; name: string; url: string } | null;
    user: { name: string; email: string } | null;
    /** For showing and hiding UI only: the server authorizes every action. */
    access: { isOwner: boolean; permissions: string[] } | null;
    /** The books' currency (ISO code); amounts arrive in minor units. */
    currency: string | null;
    flash: { success: string | null };
};
