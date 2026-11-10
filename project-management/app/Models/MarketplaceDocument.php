<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document an organization shows with its public profile or a funding
 * listing. Only its latest version is served, and only while the profile or
 * listing is published and the document is neither confidential nor archived.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $document_id
 * @property string $subject_type self::PROFILE or self::LISTING
 * @property string $subject_id
 * @property-read Document $document
 */
class MarketplaceDocument extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const PROFILE = 'profile';

    public const LISTING = 'listing';

    protected $guarded = ['id', 'organization_id'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
