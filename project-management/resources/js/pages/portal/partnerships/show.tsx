import { Form, Head } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { formatDate } from '@/lib/status';
import { accept, decline } from '@/routes/partnerships';

/**
 * A donor's invitation, in the invited organization's own workspace:
 * someone who manages it here connects it to the donor, or declines.
 */
export default function PartnershipInvitation({
    token,
    donor,
    inviter,
    organization,
    label,
    email,
    expiresAt,
    connectable,
    here,
}: {
    token: string;
    donor: string;
    inviter: string;
    organization: string;
    label: string;
    email: string;
    expiresAt: string;
    connectable: boolean;
    here: string;
}) {
    return (
        <div className="max-w-2xl">
            <Head title={`Invitation from ${donor}`} />
            <h1 className="mb-1 text-xl font-semibold">
                {donor} invited {organization}
            </h1>
            <p className="mb-2 text-sm text-muted-foreground">
                {inviter} invited <strong>{email}</strong> to connect{' '}
                {organization}, as a {label.toLowerCase()}. It works until{' '}
                {formatDate(expiresAt)}.
            </p>
            <p className="mb-6 text-sm text-muted-foreground">
                You choose what each grant shares with {donor}. Nothing is
                shared until you turn it on, and your books stay your own.
            </p>

            {connectable ? (
                <div className="flex flex-wrap gap-2">
                    <Form {...accept.form({ token })}>
                        {({ processing }) => (
                            <Button type="submit" disabled={processing}>
                                Connect {here} to {donor}
                            </Button>
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
                    {here} cannot be connected to {donor}: it is {donor} itself,
                    or already connected. Open the link in your
                    organization&apos;s own workspace.
                </p>
            )}
        </div>
    );
}
