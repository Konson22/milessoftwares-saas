import { Link } from '@inertiajs/react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { cn } from '@miles/ui/lib/utils';
import { formatMoney } from '@/lib/money';
import { show as showListing } from '@/routes/marketplace/projects';
import type { Option } from '@/types';

/** A listing or profile as donors see it in a list. */
export type ListingCardData = {
    id: string;
    title: string;
    summary: string | null;
    organization: { slug: string; name: string };
    sector: string | null;
    countryCode: string | null;
    location: string | null;
    startOn: string | null;
    endOn: string | null;
    currency: string;
    targetMinor: number;
    confirmedMinor: number;
    remainingMinor: number;
    fundingStatus: string;
};

const publication = {
    draft: { label: 'Draft', tone: 'neutral' },
    published: { label: 'Published', tone: 'success' },
    unpublished: { label: 'Unpublished', tone: 'warning' },
} as const;

export function PublicationBadge({ status }: { status: string }) {
    const shown =
        publication[status as keyof typeof publication] ?? publication.draft;

    return <StatusBadge tone={shown.tone}>{shown.label}</StatusBadge>;
}

/** A multi-line text field in the look of the inputs. */
export function TextArea({
    className,
    ...props
}: React.ComponentProps<'textarea'>) {
    return (
        <textarea
            rows={4}
            className={cn(
                'w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    );
}

/** Checkboxes that post as name[]. */
export function CheckboxGroup({
    name,
    options,
    selected,
    empty,
}: {
    name: string;
    options: Option[];
    selected: string[];
    empty?: string;
}) {
    if (options.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {empty ?? 'Nothing to choose from.'}
            </p>
        );
    }

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {options.map((option) => (
                <label
                    key={option.value}
                    className="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name={`${name}[]`}
                        value={option.value}
                        defaultChecked={selected.includes(option.value)}
                        className="size-4 accent-primary"
                    />
                    {option.label}
                </label>
            ))}
        </div>
    );
}

/** How much of the target is confirmed. Interest from donors is not counted. */
export function FundingBar({
    currency,
    targetMinor,
    confirmedMinor,
    remainingMinor,
}: {
    currency: string;
    targetMinor: number;
    confirmedMinor: number;
    remainingMinor: number;
}) {
    const share =
        targetMinor > 0
            ? Math.min(100, (confirmedMinor / targetMinor) * 100)
            : 0;

    return (
        <div className="grid gap-1.5">
            <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(share)}
                aria-label="Funding confirmed"
            >
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${share}%` }}
                />
            </div>
            <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                <span>
                    {formatMoney(confirmedMinor, currency)} confirmed of{' '}
                    {formatMoney(targetMinor, currency)}
                </span>
                <span className="font-medium text-foreground">
                    {formatMoney(remainingMinor, currency)} still needed
                </span>
            </div>
        </div>
    );
}

export function ListingCard({ listing }: { listing: ListingCardData }) {
    return (
        <Link
            href={showListing({ listing: listing.id })}
            className="grid gap-3 rounded-lg border bg-card p-4 transition-colors hover:border-primary/50"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">{listing.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {listing.organization.name}
                        {listing.location && ` · ${listing.location}`}
                        {listing.countryCode && ` · ${listing.countryCode}`}
                    </p>
                </div>
                <StatusBadge tone="info">{listing.fundingStatus}</StatusBadge>
            </div>
            {listing.summary && (
                <p className="line-clamp-2 text-sm text-muted-foreground">
                    {listing.summary}
                </p>
            )}
            {listing.sector && (
                <p className="text-xs text-muted-foreground">
                    {listing.sector}
                </p>
            )}
            <FundingBar {...listing} />
        </Link>
    );
}

/** An expression of interest as either side sees it. */
export type InterestData = {
    id: string;
    listing: { id: string; title: string };
    message: string;
    contactName: string;
    contactEmail: string;
    status: 'submitted' | 'under_review' | 'accepted' | 'declined';
    statusText: string;
    response: string | null;
    submittedAt: string;
    respondedAt: string | null;
};

const interestTones = {
    submitted: 'info',
    under_review: 'warning',
    accepted: 'success',
    declined: 'neutral',
} as const;

export function InterestBadge({
    status,
    statusText,
}: Pick<InterestData, 'status' | 'statusText'>) {
    return (
        <StatusBadge
            tone={interestTones[status] ?? 'neutral'}
            className="w-fit"
        >
            {statusText}
        </StatusBadge>
    );
}
