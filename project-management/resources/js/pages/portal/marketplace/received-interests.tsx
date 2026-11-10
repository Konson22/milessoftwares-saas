import { Form, Head } from '@inertiajs/react';
import { HandHeart } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { InterestBadge, TextArea } from '@/components/marketplace';
import type { InterestData } from '@/components/marketplace';
import { formatDate } from '@/lib/status';
import { respond } from '@/routes/funding-interests';

type Next = 'under_review' | 'accepted' | 'declined';

type Interest = InterestData & { donor: string; next: Next[] };

const actions: Record<
    Next,
    { label: string; variant: 'default' | 'outline' | 'ghost' }
> = {
    under_review: { label: 'Mark under review', variant: 'outline' },
    accepted: { label: 'Accept', variant: 'default' },
    declined: { label: 'Decline', variant: 'ghost' },
};

/**
 * What donors said about this organization's listings. Accepting is not
 * funding: it tells the donor you would like to talk, and they may then invite
 * you to partner.
 */
export default function ReceivedInterests({
    interests,
}: {
    interests: Interest[];
}) {
    return (
        <>
            <Head title="Expressions of interest" />
            <PageHeader
                title="Expressions of interest"
                description="Donors interested in the projects you listed. Accepting creates no grant and no confirmed funding."
            />

            {interests.length === 0 ? (
                <Panel>
                    <EmptyState icon={HandHeart} title="No interest yet">
                        Donors can express interest once you publish a funding
                        listing.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-4">
                    {interests.map((interest) => (
                        <Panel key={interest.id}>
                            <PanelBody className="grid gap-3 text-sm">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">
                                            {interest.donor} ·{' '}
                                            {interest.listing.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {interest.contactName} ·{' '}
                                            <a
                                                href={`mailto:${interest.contactEmail}`}
                                                className="hover:underline"
                                            >
                                                {interest.contactEmail}
                                            </a>{' '}
                                            · {formatDate(interest.submittedAt)}
                                        </p>
                                    </div>
                                    <InterestBadge {...interest} />
                                </div>
                                <p className="whitespace-pre-line">
                                    {interest.message}
                                </p>
                                {interest.response && (
                                    <p className="border-l-2 pl-3 whitespace-pre-line text-muted-foreground">
                                        {interest.response}
                                    </p>
                                )}
                                {interest.next.length > 0 && (
                                    <Form
                                        {...respond.form({
                                            interest: interest.id,
                                        })}
                                        options={{ preserveScroll: true }}
                                        className="grid gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <TextArea
                                                    name="response"
                                                    rows={2}
                                                    maxLength={2000}
                                                    aria-label="Reply to the donor"
                                                    placeholder="A note for the donor (optional)"
                                                />
                                                {errors.status && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.status}
                                                    </p>
                                                )}
                                                <div className="flex flex-wrap gap-2">
                                                    {interest.next.map(
                                                        (status) => (
                                                            <Button
                                                                key={status}
                                                                type="submit"
                                                                name="status"
                                                                value={status}
                                                                size="sm"
                                                                variant={
                                                                    actions[
                                                                        status
                                                                    ].variant
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                {
                                                                    actions[
                                                                        status
                                                                    ].label
                                                                }
                                                            </Button>
                                                        ),
                                                    )}
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </PanelBody>
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}
