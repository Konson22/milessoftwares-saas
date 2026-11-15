import { Form, Head } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { formatDate } from '@/lib/status';
import { accept, decline } from '@/routes/collaboration';
import type { ProjectInvitationSummary } from './landing';

/**
 * An emailed invitation to a project, in the invited organization's own
 * workspace: someone who manages its collaboration accepts or declines.
 */
export default function ProjectInvitation({
    token,
    invitation,
    here,
    acceptable,
}: {
    token: string;
    invitation: ProjectInvitationSummary;
    here: string;
    acceptable: boolean;
}) {
    return (
        <div className="max-w-2xl">
            <Head title={`Invitation from ${invitation.from}`} />
            <h1 className="mb-1 text-xl font-semibold">
                {invitation.from} invites {here} to {invitation.project}
            </h1>
            <p className="mb-2 text-sm text-muted-foreground">
                {invitation.inviter} invited your organization to take part as{' '}
                {invitation.relationship.toLowerCase()}. It works until{' '}
                {formatDate(invitation.expiresAt)}.
            </p>
            {invitation.message && (
                <p className="mb-2 text-sm italic">“{invitation.message}”</p>
            )}
            <p className="mb-6 text-sm text-muted-foreground">
                You would be able to: {invitation.capabilities.join(', ')}.{' '}
                {invitation.from} gets nothing of {here}&apos;s own records.
            </p>

            {acceptable ? (
                <div className="flex flex-wrap gap-2">
                    <Form {...accept.form({ token })}>
                        {({ processing, errors }) => (
                            <>
                                <Button type="submit" disabled={processing}>
                                    Accept for {here}
                                </Button>
                                {errors.invitation && (
                                    <p className="mt-2 text-sm text-destructive">
                                        {errors.invitation}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                    <Form {...decline.form({ token })}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Decline
                            </Button>
                        )}
                    </Form>
                </div>
            ) : (
                <p className="text-sm">
                    {here} runs this project or takes part in it already. Open
                    the link in your own organization&apos;s workspace.
                </p>
            )}
        </div>
    );
}
