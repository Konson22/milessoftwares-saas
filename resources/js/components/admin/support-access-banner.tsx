type Props = {
    organization: { displayName: string };
    grant: { expiresAt: string; scope: string };
};

export default function SupportAccessBanner({ organization, grant }: Props) {
    return (
        <div
            role="status"
            className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
        >
            You are viewing {organization.displayName}&apos;s data under support
            access (
            {grant.scope === 'read_only' ? 'view only' : 'configuration'}),
            until {new Date(grant.expiresAt).toLocaleString()}. This view is
            recorded in their audit log.
        </div>
    );
}
