<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\FundingInterest;
use App\Models\FundingListing;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A donor tells an organization it is interested in a project the organization
 * listed, and the organization answers. Nothing here creates a grant, a
 * commitment or confirmed funding: an accepted interest only lets the donor
 * offer a partnership, which the organization accepts in turn.
 */
final class FundingInterests
{
    /**
     * @param  array{contact_name: string, contact_email: string, message: string}  $details
     *
     * @throws ValidationException
     */
    public static function express(Organization $donor, string $listingId, User $by, array $details): FundingInterest
    {
        $listing = FundingListing::visible()->with('organization')->find($listingId);
        abort_if($listing === null, 404);
        if ($listing->organization_id === $donor->id) {
            throw ValidationException::withMessages(['message' => 'This is your own listing.']);
        }

        return DB::transaction(function () use ($donor, $listing, $by, $details) {
            $open = FundingInterest::query()->where('donor_organization_id', $donor->id)
                ->where('funding_listing_id', $listing->id)->whereIn('status', FundingInterest::OPEN)->lockForUpdate()->exists();
            if ($open) {
                throw ValidationException::withMessages(['message' => 'You have already expressed interest in this project.']);
            }

            $interest = new FundingInterest;
            $interest->forceFill([
                'donor_organization_id' => $donor->id,
                'organization_id' => $listing->organization_id,
                'funding_listing_id' => $listing->id,
                'contact_user_id' => $by->id,
                'contact_name' => $details['contact_name'],
                'contact_email' => mb_strtolower(trim($details['contact_email'])),
                'message' => $details['message'],
                'status' => FundingInterest::SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            Audit::record('funding_interest.expressed', $interest, ['listing' => $listing->title, 'to' => $listing->organization->name], $donor->id, $by);
            Audit::record('funding_interest.received', $interest, ['listing' => $listing->title, 'from' => $donor->name], $listing->organization_id, $by);
            if ($listing->organization->isActive()) {
                app(CurrentOrganization::class)->run($listing->organization, fn () => Alerts::tell(
                    Alerts::holders('marketplace.publish'),
                    UserNotification::MESSAGE,
                    "{$donor->name} is interested in {$listing->title}",
                    $interest->message,
                    route('funding-interests.index', [], false),
                    $by,
                ));
            }

            return $interest;
        });
    }

    /**
     * The organization moves an interest on: under review, accepted or
     * declined, with an optional note the donor sees.
     *
     * @throws ValidationException
     */
    public static function respond(FundingInterest $interest, string $status, ?string $response, User $by): FundingInterest
    {
        return DB::transaction(function () use ($interest, $status, $response, $by) {
            $interest = FundingInterest::query()->lockForUpdate()->findOrFail($interest->id);
            if (! $interest->canMoveTo($status)) {
                throw ValidationException::withMessages(['status' => "This interest is already {$interest->statusText()}."]);
            }

            $interest->forceFill([
                'status' => $status,
                'response' => filled($response) ? $response : $interest->response,
                'responded_at' => now(),
                'responded_by' => $by->id,
            ])->save();
            $interest->load('donor', 'organization', 'listing');

            Audit::record("funding_interest.{$status}", $interest, ['listing' => $interest->listing->title, 'donor' => $interest->donor->name], $interest->organization_id, $by);
            Audit::record("funding_interest.{$status}", $interest, ['listing' => $interest->listing->title, 'by' => $interest->organization->name], $interest->donor_organization_id, $by);
            if ($interest->donor->isActive()) {
                app(CurrentOrganization::class)->run($interest->donor, fn () => Alerts::tell(
                    Alerts::holders('grants.view'),
                    UserNotification::MESSAGE,
                    "{$interest->organization->name}: your interest in {$interest->listing->title} is ".mb_strtolower($interest->statusText()),
                    $interest->response,
                    route('marketplace.interests', [], false),
                    $by,
                ));
            }

            return $interest;
        });
    }

    /**
     * After an accepted interest, the donor offers to partner: the same
     * invitation as from the organization's profile, answered by the
     * organization. An existing connection is reused, not doubled.
     *
     * @throws ValidationException
     */
    public static function invite(FundingInterest $interest, User $by): Partnership
    {
        if ($interest->status !== FundingInterest::ACCEPTED) {
            throw ValidationException::withMessages(['interest' => 'Only an accepted interest leads to a partnership invitation.']);
        }

        $partnership = Beneficiaries::inviteOrganization($interest->donor, $interest->organization, $by);
        $interest->forceFill(['partnership_id' => $partnership->id])->save();

        return $partnership;
    }
}
