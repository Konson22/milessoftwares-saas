<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\GrantReportStatus;
use App\Models\Funder;
use App\Models\FunderContact;
use App\Models\Grant;
use App\Models\GrantMessage;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use App\Models\Invitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the donor portal. Staff invite people to act for a funder and
 * decide what each grant shares; a contact can read that, accept a submitted
 * report or ask for a revision, and write to the organization. A contact holds
 * only the funder contact's permission: they are kept to the portal (App\Http\Middleware\ConfineFunderContacts).
 * Every change is audited.
 */
final class DonorPortal
{
    public const DISK = 'local';

    /**
     * Adds someone to act for the funder. They also need a place in the
     * workspace on the platform with the "Funder contact" role; they become
     * the funder's contact when they first open Projects here.
     *
     * @throws ValidationException when they are a contact here already
     */
    public static function invite(Funder $funder, string $email, User $by): Invitation
    {
        $organization = app(CurrentOrganization::class)->require();
        $invitation = Organizations::addFunderContact($organization, $by, $email, $funder->id);
        Audit::record('funder.contact_invited', $funder, ['email' => $invitation->email], actor: $by);

        return $invitation;
    }

    /**
     * Ends their access to the portal at once. Their place in the workspace
     * is the platform's: remove it there too if they should not come back.
     */
    public static function removeContact(FunderContact $contact, User $by): void
    {
        Audit::record('funder.contact_removed', $contact->funder, ['user_id' => $contact->user_id], actor: $by);
        $contact->delete();
    }

    /**
     * @param  array{portal_enabled: bool, portal_budget: bool, portal_spending: bool, portal_commitments: bool, portal_progress: bool, portal_milestones: bool, portal_tasks: bool}  $sharing
     */
    public static function share(Grant $grant, array $sharing, User $by): void
    {
        $grant->forceFill($sharing)->save();
        Audit::record('grant.portal_sharing_changed', $grant, $sharing, actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function attach(GrantReport $report, UploadedFile $file, User $by): GrantReportDocument
    {
        if ($report->status === GrantReportStatus::Accepted) {
            throw ValidationException::withMessages(['document' => 'The funder has accepted this report: it stays as it is.']);
        }

        $path = $file->store("organizations/{$report->organization_id}/grant-reports/{$report->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['document' => 'The file could not be stored.']);
        }

        $document = GrantReportDocument::query()->create([
            'grant_report_id' => $report->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
        Audit::record('grant.report_document_attached', $report, ['document' => $document->original_name], actor: $by);

        return $document;
    }

    /**
     * Only from a report the funder doesn't have yet: what they were given stays.
     *
     * @throws ValidationException
     */
    public static function detach(GrantReport $report, GrantReportDocument $document, User $by): void
    {
        if (! $report->status->isOutstanding()) {
            throw ValidationException::withMessages(['document' => 'The funder has this report: its documents stay.']);
        }

        $document->delete();
        Storage::disk(self::DISK)->delete($document->path);
        Audit::record('grant.report_document_removed', $report, ['document' => $document->original_name], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function funderDecides(GrantReport $report, GrantReportStatus $to, ?string $reason, User $by): void
    {
        if (! in_array($to, [GrantReportStatus::Accepted, GrantReportStatus::RevisionRequested], true)) {
            throw ValidationException::withMessages(['status' => 'A funder accepts a report or asks for a revision.']);
        }
        if ($report->status !== GrantReportStatus::Submitted) {
            throw ValidationException::withMessages(['status' => 'Only a submitted report waits for your decision.']);
        }
        if ($to === GrantReportStatus::RevisionRequested && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => 'Say what needs to change.']);
        }

        DB::transaction(function () use ($report, $to, $reason, $by) {
            Grants::moveReport($report, $to, CarbonImmutable::today(), $by);
            $grant = Grant::query()->findOrFail($report->grant_id);

            if ($to === GrantReportStatus::RevisionRequested) {
                self::post($grant, "Revision requested on {$report->title}: ".trim((string) $reason), $by, fromFunder: true, tell: false);
            }

            Alerts::tell(
                Alerts::holders('grants.manage'),
                UserNotification::MESSAGE,
                $to === GrantReportStatus::Accepted
                    ? "{$by->name} accepted a report on {$grant->reference}"
                    : "{$by->name} asked for a revision of a report on {$grant->reference}",
                $to === GrantReportStatus::Accepted ? $report->title : trim((string) $reason),
                route('grants.show', ['grant' => $grant->id], false),
                $by,
            );
        });
    }

    public static function post(Grant $grant, string $body, User $by, bool $fromFunder, bool $tell = true): GrantMessage
    {
        return DB::transaction(function () use ($grant, $body, $by, $fromFunder, $tell) {
            $message = GrantMessage::query()->create([
                'grant_id' => $grant->id,
                'user_id' => $by->id,
                'from_funder' => $fromFunder,
                'body' => trim($body),
                'created_at' => now(),
            ]);
            Audit::record('grant.message_posted', $grant, ['from_funder' => $fromFunder], actor: $by);

            if ($tell) {
                $title = "{$by->name} wrote about {$grant->reference}";
                if ($fromFunder) {
                    Alerts::tell(Alerts::holders('grants.manage'), UserNotification::MESSAGE, $title, $message->body, route('grants.show', ['grant' => $grant->id], false), $by);
                } elseif ($grant->portal_enabled) {
                    self::tellContacts($grant, $title, $message->body, $by);
                }
            }

            return $message;
        });
    }

    /**
     * A report reached the funder, when the portal is open to them.
     */
    public static function reportSubmitted(GrantReport $report, User $by): void
    {
        $grant = Grant::query()->findOrFail($report->grant_id);
        if ($grant->portal_enabled) {
            self::tellContacts($grant, "A report on {$grant->reference} is ready for you", $report->title, $by);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public static function contacts(Grant $grant): Collection
    {
        $organizationId = app(CurrentOrganization::class)->require()->id;

        return FunderContact::query()->where('funder_id', $grant->funder_id)->with('user')->get()
            ->map(fn (FunderContact $contact) => $contact->user)
            ->filter(fn (User $user) => $user->isActive() && OrganizationMembership::query()
                ->where('organization_id', $organizationId)->where('user_id', $user->id)
                ->where('status', OrganizationMembership::ACTIVE)->exists())
            ->values();
    }

    private static function tellContacts(Grant $grant, string $title, ?string $excerpt, User $by): void
    {
        Alerts::tell(self::contacts($grant), UserNotification::MESSAGE, $title, $excerpt, route('donor.grants.show', ['grant' => $grant->id], false), $by);
        Beneficiaries::tellDonor($grant, $title, $excerpt, $by);
    }
}
